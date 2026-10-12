<?php

namespace App\Services;

use App\Models\House;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReportService
{
    protected ?int $ownerId = null;

    public function ownerId(?int $ownerId): self
    {
        $this->ownerId = $ownerId;

        return $this;
    }

    protected function houseIds(): array
    {
        return House::where('user_id', $this->ownerId)->pluck('id')->all();
    }

    protected function scopeChargesToOwner($query)
    {
        if ($this->ownerId) {
            $query->whereHas('room.house', fn ($q) => $q->where('user_id', $this->ownerId));
        }

        return $query;
    }

    protected function scopeRentalsToOwner($query)
    {
        if ($this->ownerId) {
            $query->where('house_id', $this->houseIds());
        }

        return $query;
    }

    /**
     * A DB-portable expression rendering a date column as "YYYY-MM".
     *
     * Used for both the SELECT list and GROUP BY, so the alias is added by the
     * caller. Grouping by the expression rather than the alias keeps this valid
     * on PostgreSQL, which does not allow output aliases in GROUP BY.
     */
    protected function monthExpr(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM')",
            'sqlite' => "strftime('%Y-%m', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m')",
        };
    }

    /**
     * Restrict charges to the periods that overlap the requested window.
     *
     * Overlap, not containment: a charge covering 20 Aug - 20 Sep belongs in a
     * report filtered to September. Testing period_start >= from AND period_end
     * <= to would silently drop every charge straddling a boundary, which is
     * exactly what happens on any hand-picked date range.
     */
    protected function applyChargeWindow(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('period_end', '>=', $from);
        }

        if ($to) {
            $query->where('period_start', '<=', $to);
        }

        return $query;
    }

    /**
     * Portable replacement for MySQL's SUM(status = "paid"). The double-quoted
     * literal that shortcut relied on is not portable either: it is an identifier
     * on PostgreSQL with ANSI_QUOTES enabled and invalid on SQLite.
     */
    protected function paidChargeCountSql(): string
    {
        return "SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END)";
    }

    public function rentCollectionReport(?string $fromDate = null, ?string $toDate = null): array
    {
        $expr = $this->monthExpr('period_start');

        $query = RentCharge::query()
            ->selectRaw("{$expr} as period")
            ->selectRaw('SUM(expected_amount) as expected_amount')
            ->selectRaw('SUM(paid_amount) as collected_amount')
            ->selectRaw('SUM(expected_amount - paid_amount) as outstanding_amount')
            ->selectRaw('COUNT(*) as charge_count')
            ->selectRaw($this->paidChargeCountSql().' as paid_charge_count')
            ->groupByRaw($expr);

        $this->scopeChargesToOwner($query);
        $this->applyChargeWindow($query, $fromDate, $toDate);

        $rows = $query->orderByRaw($expr)->get()->map(fn ($r) => [
            'period' => $r->period,
            'expected_amount' => (float) $r->expected_amount,
            'collected_amount' => (float) $r->collected_amount,
            'outstanding_amount' => (float) $r->outstanding_amount,
            'charge_count' => (int) $r->charge_count,
            'collection_rate' => $r->expected_amount > 0
                ? round(($r->collected_amount / $r->expected_amount) * 100, 2)
                : 0,
        ]);

        return [
            'rows' => $rows,
            'total_expected' => (float) $rows->sum('expected_amount'),
            'total_collected' => (float) $rows->sum('collected_amount'),
            'total_outstanding' => (float) $rows->sum('outstanding_amount'),
        ];
    }

    public function tenantReport(?int $tenantId = null): Collection
    {
        $tenants = Tenant::query()->with(['rentals.room.house', 'rentals.rentCharges']);

        if ($this->ownerId) {
            $tenants->whereHas('rentals.room.house', fn ($q) => $q->where('user_id', $this->ownerId));
        }

        if ($tenantId) {
            $tenants->where('id', $tenantId);
        }

        return $tenants->get()->map(function (Tenant $tenant) {
            $activeRental = $tenant->rentals
                ->first(fn ($r) => in_array($r->status, Rental::LIVE_STATUSES, true));

            $balance = $tenant->rentals
                ->flatMap(fn ($r) => $r->rentCharges)
                ->sum(fn ($c) => (float) $c->expected_amount - (float) $c->paid_amount);

            return [
                'id' => $tenant->id,
                'full_name' => $tenant->full_name,
                'phone' => $tenant->phone,
                'room_name' => $activeRental?->room?->name ?? null,
                'house_name' => $activeRental?->room?->house?->name ?? null,
                'balance' => round($balance, 2),
                'status' => $tenant->status,
            ];
        })->values();
    }

    public function roomReport(?int $roomId = null, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $rooms = Room::query()->with(['house', 'rentals']);

        if ($this->ownerId) {
            $rooms->whereHas('house', fn ($q) => $q->where('user_id', $this->ownerId));
        }

        if ($roomId) {
            $rooms->where('id', $roomId);
        }

        return $rooms->get()->map(function (Room $room) use ($fromDate, $toDate) {
            $charges = RentCharge::where('room_id', $room->id);
            $this->applyChargeWindow($charges, $fromDate, $toDate);

            [$occupiedMonths, $totalMonths] = $this->occupancyFor($room, $fromDate, $toDate);

            return [
                'id' => $room->id,
                'name' => $room->name,
                'house_name' => $room->house?->name ?? null,
                'rent_amount' => (float) $room->rent_amount,
                'occupied_months' => $occupiedMonths,
                'total_months' => $totalMonths,
                'total_revenue' => (float) $charges->sum('paid_amount'),
                'occupancy_rate' => $totalMonths > 0 ? round(($occupiedMonths / $totalMonths) * 100, 1) : 0,
            ];
        })->values();
    }

    /**
     * How many of the calendar months in the window had a tenant at all.
     *
     * Every tenancy counts, ended or not: the dates on the rentals are the
     * truth, not whatever status label was applied later. A tenancy counts a
     * month as occupied when its [start, end ?? today] overlaps that month at
     * all. Future-start tenancies do not occupy months before they begin, and
     * nothing is ever counted past today, so the rate can never exceed 100%.
     *
     * The window defaults to the room's whole life (created_at -> today) and is
     * scoped to from_date/to_date when the caller supplies them, so the
     * occupancy figures follow the same window as the revenue figures.
     *
     * @return array{int, int} [occupied_months, total_months]
     */
    protected function occupancyFor(Room $room, ?string $fromDate, ?string $toDate): array
    {
        $windowStart = $fromDate
            ? Carbon::parse($fromDate)->startOfDay()
            : Carbon::parse($room->created_at)->startOfDay();
        $windowEnd = ($toDate ? Carbon::parse($toDate) : Carbon::now())->startOfDay();

        // An inverted (or empty) window has no elapsed period to rate against.
        if ($windowEnd->lt($windowStart)) {
            return [0, 1];
        }

        $firstMonth = $windowStart->copy()->startOfMonth();
        $totalMonths = max(1, (int) $firstMonth->diffInMonths($windowEnd->copy()->startOfMonth()) + 1);

        $occupied = 0;
        $now = Carbon::now();
        foreach ($room->rentals as $rental) {
            if (! $rental->start_date) {
                continue;
            }

            $tenantStart = Carbon::parse($rental->start_date);
            $tenantEnd = $rental->end_date ? Carbon::parse($rental->end_date) : $now;

            for ($i = 0; $i < $totalMonths; $i++) {
                $monthStart = $firstMonth->copy()->addMonths($i);
                $monthEnd = $monthStart->copy()->endOfMonth();

                if ($tenantStart->lte($monthEnd) && $tenantEnd->gte($monthStart)) {
                    $occupied++;
                }
            }
        }

        return [min($occupied, $totalMonths), $totalMonths];
    }

    public function houseReport(?int $houseId = null, ?string $fromDate = null, ?string $toDate = null): Collection
    {
        $houses = House::query()->with('rooms');

        if ($this->ownerId) {
            $houses->where('user_id', $this->ownerId);
        }

        if ($houseId) {
            $houses->where('id', $houseId);
        }

        return $houses->get()->map(function (House $house) use ($fromDate, $toDate) {
            $roomIds = $house->rooms->pluck('id')->all();

            $charges = RentCharge::whereIn('room_id', $roomIds);
            $this->applyChargeWindow($charges, $fromDate, $toDate);
            $charges = $charges->get();

            return [
                'id' => $house->id,
                'name' => $house->name,
                'total_rooms' => $house->rooms->count(),
                'expected_income' => (float) $charges->sum('expected_amount'),
                'collected_income' => (float) $charges->sum('paid_amount'),
                'outstanding' => (float) $charges->sum(fn ($c) => (float) $c->expected_amount - (float) $c->paid_amount),
            ];
        })->values();
    }

    /**
     * Rent that is genuinely owed: past its due date and still not settled.
     *
     * Rent is charged ahead of time, so filtering on status alone reported the
     * next few months of not-yet-due rent as arrears and overstated the figure
     * the owner would chase tenants for.
     */
    public function arrearsReport(): Collection
    {
        $query = RentCharge::whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->whereDate('due_date', '<=', Carbon::now()->toDateString())
            ->whereRaw('expected_amount > paid_amount')
            ->with(['tenant', 'room.house'])
            ->orderBy('due_date');

        $this->scopeChargesToOwner($query);

        return $query->get()->map(fn (RentCharge $c) => [
            'id' => $c->id,
            'tenant_name' => $c->tenant?->full_name ?? null,
            'room_name' => $c->room?->name ?? null,
            'overdue_amount' => round((float) $c->expected_amount - (float) $c->paid_amount, 2),
            'days_overdue' => (int) Carbon::parse($c->due_date)->diffInDays(Carbon::now()),
            'due_date' => $c->due_date,
        ])->values();
    }

    public function expiryReport(int $withinDays = 60): Collection
    {
        $query = Rental::with(['tenant', 'room'])
            ->live()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [Carbon::now()->toDateString(), Carbon::now()->addDays($withinDays)->toDateString()])
            ->orderBy('end_date');

        $this->scopeRentalsToOwner($query);

        return $query->get()->map(fn (Rental $r) => [
            'id' => $r->id,
            'tenant_name' => $r->tenant?->full_name ?? null,
            'room_name' => $r->room?->name ?? null,
            'end_date' => $r->end_date,
            'days_remaining' => (int) Carbon::now()->startOfDay()->diffInDays(Carbon::parse($r->end_date)->startOfDay(), false),
            'status' => $r->status,
        ])->values();
    }

    /**
     * Month-by-month rent performance.
     *
     * There is no expenses table in this application, so an "Expenses" column
     * and a "Net" figure equal to income were arithmetic theatre: they showed a
     * business as fully profitable by construction. What is reported instead is
     * what the data can actually support - income, what was expected, what is
     * still outstanding, and the share of charges settled in full.
     */
    public function monthlyReport(?string $fromDate = null, ?string $toDate = null): array
    {
        // A rolling 12 months by default. Defaulting to the current month alone
        // made this a single-row summary, not a report.
        $end = $toDate ?: Carbon::now()->endOfMonth()->toDateString();
        $start = $fromDate ?: Carbon::now()->subMonths(11)->startOfMonth()->toDateString();

        $expr = $this->monthExpr('period_start');

        $query = RentCharge::query()
            ->selectRaw("{$expr} as month")
            ->selectRaw('SUM(expected_amount) as expected_amount')
            ->selectRaw('SUM(paid_amount) as income')
            ->selectRaw('SUM(expected_amount - paid_amount) as outstanding')
            ->selectRaw('COUNT(*) as total_charges')
            ->selectRaw($this->paidChargeCountSql().' as paid_charges')
            ->groupByRaw($expr);

        $this->scopeChargesToOwner($query);
        $this->applyChargeWindow($query, $start, $end);

        $rows = $query->orderByRaw($expr)->get()->map(fn ($r) => [
            'month' => $r->month,
            'income' => (float) $r->income,
            'expected' => (float) $r->expected_amount,
            'outstanding' => (float) $r->outstanding,
            'charge_count' => (int) $r->total_charges,
            'charge_paid_rate' => $r->total_charges > 0
                ? round(($r->paid_charges / $r->total_charges) * 100, 1)
                : 0,
        ]);

        return [
            'rows' => $rows,
            'total_income' => (float) $rows->sum('income'),
            'total_expected' => (float) $rows->sum('expected'),
            'total_outstanding' => (float) $rows->sum('outstanding'),
        ];
    }
}
