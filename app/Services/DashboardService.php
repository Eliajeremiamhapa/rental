<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Carbon\Carbon;

class DashboardService
{
    public function __construct(
        protected BalanceService $balanceService,
        protected RentChargeService $rentChargeService,
    ) {}

    public function getOwnerDashboard(User $owner): array
    {
        $houseIds = $owner->houses()->pluck('houses.id');
        $roomIds = Room::whereIn('house_id', $houseIds)->pluck('id');

        $totalHouses = $owner->houses()->count();
        $totalRooms = Room::whereIn('house_id', $houseIds)->count();
        $occupiedRooms = Room::whereIn('house_id', $houseIds)->where('status', 'occupied')->count();
        $availableRooms = Room::whereIn('house_id', $houseIds)->where('status', 'available')->count();
        $maintenanceRooms = Room::whereIn('house_id', $houseIds)->where('status', 'maintenance')->count();

        $chargesQuery = RentCharge::whereIn('room_id', $roomIds);

        $totalExpectedRent = (float) $chargesQuery->clone()->sum('expected_amount');
        $totalCollectedRent = (float) $chargesQuery->clone()->sum('paid_amount');
        $totalOutstanding = $totalExpectedRent - $totalCollectedRent;

        $totalTenants = Tenant::whereHas('rentals', function ($q) use ($roomIds) {
            $q->whereIn('room_id', $roomIds)->live();
        })->count();

        $expiringSoon = $this->rentChargeService->getUpcomingCharges(7)
            ->filter(fn ($charge) => $roomIds->contains($charge->room_id))
            ->values();

        $overdue = $this->rentChargeService->getOverdueCharges()
            ->filter(fn ($charge) => $roomIds->contains($charge->room_id))
            ->values();

        $monthlyCollection = collect();
        for ($i = 5; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth()->toDateString();
            $monthEnd = $month->copy()->endOfMonth()->toDateString();

            $monthCharges = RentCharge::whereIn('room_id', $roomIds)
                ->where('period_start', '>=', $monthStart)
                ->where('period_start', '<=', $monthEnd);

            $monthlyCollection->push([
                'month' => $month->format('M Y'),
                'expected' => (float) $monthCharges->clone()->sum('expected_amount'),
                'collected' => (float) $monthCharges->clone()->sum('paid_amount'),
            ]);
        }

        $recentPayments = Payment::whereHas('rentCharge', function ($q) use ($roomIds) {
            $q->whereIn('room_id', $roomIds);
        })
            ->with('tenant', 'rentCharge.room.house')
            ->orderByDesc('payment_date')
            ->limit(5)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'tenant_name' => $p->tenant->full_name ?? 'Unknown',
                'room_name' => $p->rentCharge->room->name ?? 'Unknown',
                'house_name' => $p->rentCharge->room->house->name ?? 'Unknown',
                'amount' => $p->amount,
                'payment_date' => $p->payment_date,
                'payment_method' => $p->payment_method,
            ]);

        $housePerformance = $owner->houses()->withCount(['rooms as total_rooms'])->get()->map(function ($house) {
            $houseRoomIds = $house->rooms()->pluck('rooms.id');
            $occupied = $house->rooms()->where('status', 'occupied')->count();
            $available = $house->rooms()->where('status', 'available')->count();
            $collected = (float) RentCharge::whereIn('room_id', $houseRoomIds)->sum('paid_amount');
            $expected = (float) RentCharge::whereIn('room_id', $houseRoomIds)->sum('expected_amount');

            return [
                'id' => $house->id,
                'name' => $house->name,
                'total_rooms' => $house->total_rooms,
                'occupied_rooms' => $occupied,
                'available_rooms' => $available,
                'occupancy_rate' => $house->total_rooms > 0 ? round(($occupied / $house->total_rooms) * 100) : 0,
                'collected' => $collected,
                'expected' => $expected,
            ];
        });

        return [
            'stats' => [
                'total_houses' => $totalHouses,
                'total_rooms' => $totalRooms,
                'occupied_rooms' => $occupiedRooms,
                'available_rooms' => $availableRooms,
                'maintenance_rooms' => $maintenanceRooms,
                'total_tenants' => $totalTenants,
                'occupancy_rate' => $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0,
            ],
            'financial' => [
                'expected_rent' => $totalExpectedRent,
                'collected' => $totalCollectedRent,
                'outstanding' => $totalOutstanding,
                'collection_rate' => $totalExpectedRent > 0 ? round(($totalCollectedRent / $totalExpectedRent) * 100, 1) : 0,
            ],
            'expiring_soon' => $expiringSoon->map(fn ($c) => [
                'tenant_name' => $c->tenant->full_name ?? 'Unknown',
                'room_name' => $c->room->name ?? 'Unknown',
                'days_remaining' => max(0, now()->diffInDays(Carbon::parse($c->due_date))),
                'amount' => $c->expected_amount,
            ])->values(),
            'overdue' => $overdue->map(fn ($c) => [
                'tenant_name' => $c->tenant->full_name ?? 'Unknown',
                'room_name' => $c->room->name ?? 'Unknown',
                'days_overdue' => max(0, now()->diffInDays(Carbon::parse($c->due_date))),
                'amount' => ($c->expected_amount ?? 0) - ($c->paid_amount ?? 0),
            ])->values(),
            'monthly_collection' => $monthlyCollection,
            'recent_payments' => $recentPayments,
            'house_performance' => $housePerformance,
        ];
    }

    public function getTenantDashboard(Tenant $tenant): array
    {
        $currentRental = Rental::where('tenant_id', $tenant->id)
            ->live()
            ->with(['room.house', 'rentCharges'])
            ->first();

        // Fall back to the most recent rental if none is currently active.
        if (! $currentRental) {
            $currentRental = Rental::where('tenant_id', $tenant->id)
                ->with(['room.house', 'rentCharges'])
                ->orderByDesc('start_date')
                ->first();
        }

        $balance = $this->balanceService->calculateTenantBalance($tenant->id);

        $nextDueCharge = RentCharge::where('tenant_id', $tenant->id)
            ->whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->orderBy('due_date')
            ->first();

        $nextDueDate = $nextDueCharge?->due_date
            ?? $this->getNextExpectedDueDate($currentRental);

        $recentPayments = Payment::where('tenant_id', $tenant->id)
            ->with('rentCharge.room')
            ->orderByDesc('payment_date')
            ->limit(5)
            ->get();

        $history = RentCharge::where('tenant_id', $tenant->id)
            ->orderByDesc('period_start')
            ->get()
            ->map(fn (RentCharge $c) => [
                'id' => $c->id,
                'tenant_id' => $c->tenant_id,
                'period_start' => $c->period_start,
                'period_end' => $c->period_end,
                'due_date' => $c->due_date,
                'expected_amount' => (float) $c->expected_amount,
                'paid_amount' => (float) $c->paid_amount,
                'balance' => round((float) $c->expected_amount - (float) $c->paid_amount, 2),
                'status' => $c->status,
            ]);

        $room = $currentRental?->room;
        if ($room) {
            $room->setRelation('rental', $currentRental);
        }

        return [
            'tenant' => [
                'name' => $tenant->full_name,
                'phone' => $tenant->phone,
            ],
            'currentRoom' => $room,
            'currentBalance' => $balance['total_balance'],
            'totalPaid' => $balance['total_paid'],
            'totalOwing' => $balance['total_overdue'],
            'nextDueDate' => $nextDueDate,
            'recentPayments' => $recentPayments,
            'room' => $room,
            'rental' => $currentRental,
            'balance' => [
                'expected_amount' => $balance['total_expected'],
                'paid_amount' => $balance['total_paid'],
                'balance' => $balance['total_balance'],
                'status' => $nextDueCharge?->status ?? ($balance['total_balance'] > 0 ? 'unpaid' : 'paid'),
            ],
            'history' => $history,
            'payment_accounts' => $tenant->owner?->settings?->payment_accounts ?? [],
            'owner_settings' => [
                'payment_accounts' => $tenant->owner?->settings?->payment_accounts ?? [],
                'lipa_namba' => $tenant->owner?->settings?->lipa_namba,
                'lipa_namba_name' => $tenant->owner?->settings?->lipa_namba_name,
                'company_name' => $tenant->owner?->settings?->company_name,
            ],
        ];
    }

    /**
     * Project the next expected payment date when no outstanding charge exists,
     * so an active tenant always sees an upcoming due date. Mirrors the date
     * logic used by RentChargeService::generateChargeForPeriod.
     */
    private function getNextExpectedDueDate(?Rental $rental): ?string
    {
        if (! $rental || ! in_array($rental->status, Rental::LIVE_STATUSES, true)) {
            return null;
        }

        $latest = $rental->rentCharges
            ->sortByDesc('period_end')
            ->first();

        $nextPeriodStart = $latest
            ? Carbon::parse($latest->period_start)->addMonth()->startOfMonth()
            : Carbon::parse($rental->start_date)->startOfMonth();

        $dueDate = $nextPeriodStart->copy()->day((int) $rental->due_day);
        if ($dueDate->lt($nextPeriodStart)) {
            $dueDate->addMonth();
        }

        return $dueDate->toDateString();
    }
}
