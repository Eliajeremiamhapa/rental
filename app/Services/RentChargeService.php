<?php

namespace App\Services;

use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RentChargeService
{
    /**
     * The deterministic list of monthly installments a rental is billed for.
     *
     * Periods are anchored to the rental start day (e.g. a tenancy starting on
     * the 10th bills 10th -> 9th), so a N-month rental yields exactly N charges
     * regardless of whether the start date falls mid-month. Walking calendar
     * months instead produced N+1 full-price charges for mid-month starts and
     * overlapping duplicates once the recurring job also ran.
     *
     * @return array<int, array{period_start: Carbon, period_end: Carbon, due_date: Carbon}>
     */
    public function expectedPeriods(Rental $rental): array
    {
        $anchor = Carbon::parse($rental->start_date)->startOfDay();
        $months = max(1, (int) $rental->period_months);
        $end = $rental->end_date ? Carbon::parse($rental->end_date)->startOfDay() : null;

        $periods = [];

        for ($k = 0; $k < $months; $k++) {
            $periodStart = $anchor->copy()->addMonthsNoOverflow($k);
            $periodEnd = $anchor->copy()->addMonthsNoOverflow($k + 1)->subDay();

            if ($end && $periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }
            if ($end && $periodStart->gt($end)) {
                break;
            }

            $dueDate = $periodStart->copy()->day($rental->due_day);
            if ($dueDate->lt($periodStart)) {
                $dueDate->addMonth();
            }

            $periods[] = [
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'due_date' => $dueDate,
            ];
        }

        return $periods;
    }

    public function generateChargesForRental(Rental $rental): Collection
    {
        $charges = collect();

        foreach ($this->expectedPeriods($rental) as $period) {
            $existing = RentCharge::where('rental_id', $rental->id)
                ->whereDate('period_start', $period['period_start']->toDateString())
                ->first();

            if (! $existing) {
                $charges->push($this->generateChargeForPeriod(
                    $rental,
                    $period['period_start']->copy(),
                    $period['period_end']->copy(),
                ));
            }
        }

        return $charges;
    }

    /**
     * Generate the upcoming installments of an active rental, skipping any
     * period that already has a charge. Because periods come from the same
     * anchor as generateChargesForRental, this can never create an overlapping
     * duplicate for a mid-month start.
     */
    public function generateRecurringCharges(Rental $rental, int $lookaheadMonths = 6): Collection
    {
        $charges = collect();
        $windowStart = now()->startOfMonth();
        $windowEnd = now()->copy()->addMonths($lookaheadMonths)->endOfMonth();

        foreach ($this->expectedPeriods($rental) as $period) {
            $periodStart = $period['period_start'];

            if ($periodStart->lt($windowStart) || $periodStart->gt($windowEnd)) {
                continue;
            }

            $existing = RentCharge::where('rental_id', $rental->id)
                ->whereDate('period_start', $periodStart->toDateString())
                ->first();

            if (! $existing) {
                $charges->push($this->generateChargeForPeriod(
                    $rental,
                    $periodStart->copy(),
                    $period['period_end']->copy(),
                ));
            }
        }

        return $charges;
    }

    public function generateChargeForPeriod(Rental $rental, Carbon $periodStart, Carbon $periodEnd): RentCharge
    {
        $dueDate = $periodStart->copy()->day($rental->due_day);
        if ($dueDate->lt($periodStart)) {
            $dueDate->addMonth();
        }

        $charge = RentCharge::create([
            'rental_id' => $rental->id,
            'tenant_id' => $rental->tenant_id,
            'room_id' => $rental->room_id,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'due_date' => $dueDate->toDateString(),
            'expected_amount' => $rental->rent_amount,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // An overpayment may already sit on an earlier period as credit (it had
        // no later charge to fall into when it arrived). Re-spread the rental's
        // money so this new period is settled automatically.
        $this->reallocateCredit($rental);

        return $charge->fresh();
    }

    /**
     * Re-spread every payment recorded on a rental across its charges, oldest
     * debt first.
     *
     * A payment is stored against the one charge the payer targeted, but the
     * money belongs to the tenancy as a whole. Without this a lump sum aimed at
     * a later period leaves an earlier period unpaid, and an overpayment stays
     * as credit on a single charge that the following period can never reach.
     * Recomputing the distribution from the total received keeps every charge's
     * paid amount and status consistent with the ledger.
     */
    public function reallocateCredit(Rental|int $rental): void
    {
        $rentalId = $rental instanceof Rental ? $rental->id : $rental;

        $charges = RentCharge::query()
            ->where('rental_id', $rentalId)
            ->orderBy('period_start')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($charges->isEmpty()) {
            return;
        }

        $pool = (float) $charges->sum(fn (RentCharge $charge) => (float) $charge->paid_amount);

        foreach ($charges as $charge) {
            $expected = (float) $charge->expected_amount;
            $applied = max(0.0, min($pool, $expected));
            $pool -= $applied;

            // Setting the attribute marks it dirty so updateChargeStatus()'s
            // save() persists it alongside the recalculated status.
            $charge->paid_amount = $applied;
            $this->updateChargeStatus($charge);
            $this->markOverdueIfPastDue($charge->fresh());
        }

        // Money beyond every period stays on the newest charge as credit so the
        // next generated period absorbs it.
        if ($pool > 0) {
            $last = $charges->last();
            $last->paid_amount = (float) $last->paid_amount + $pool;
            $this->updateChargeStatus($last);
        }
    }

    /**
     * Keep a charge flagged overdue when it is still not fully settled and its
     * due date has passed. updateChargeStatus() only knows unpaid/partial/paid,
     * so reallocation must re-assert the overdue flag rather than demote it.
     */
    private function markOverdueIfPastDue(RentCharge $charge): void
    {
        if (
            in_array($charge->status, ['unpaid', 'partial'], true)
            && $charge->due_date
            && $charge->due_date->lt(now()->startOfDay())
        ) {
            $charge->update(['status' => 'overdue']);
        }
    }

    public function updateChargeStatus(RentCharge $charge): RentCharge
    {
        $paid = (float) $charge->paid_amount;
        $expected = (float) $charge->expected_amount;

        if ($paid <= 0) {
            $status = 'unpaid';
            $paidAt = null;
        } elseif ($paid >= $expected) {
            $status = 'paid';
            $paidAt = $charge->paid_at ?? now();
        } else {
            $status = 'partial';
            $paidAt = null;
        }

        $charge->update([
            'status' => $status,
            'paid_at' => $paidAt,
        ]);

        return $charge->fresh();
    }

    public function checkOverdue(): int
    {
        $charges = RentCharge::whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', now()->toDateString())
            ->get();

        $count = 0;
        foreach ($charges as $charge) {
            $charge->update(['status' => 'overdue']);
            $count++;
        }

        return $count;
    }

    public function getUpcomingCharges(int $days = 7, ?User $user = null): Collection
    {
        return RentCharge::query()
            ->ownedBy($user ?? auth()->user())
            ->whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '>=', now()->toDateString())
            ->where('due_date', '<=', now()->addDays($days)->toDateString())
            ->with(['tenant', 'room', 'rental'])
            ->get();
    }

    public function getOverdueCharges(?User $user = null): Collection
    {
        return RentCharge::query()
            ->ownedBy($user ?? auth()->user())
            ->where('status', 'overdue')
            ->with(['tenant', 'room', 'rental'])
            ->get();
    }
}
