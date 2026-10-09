<?php

namespace App\Services;

use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class RentChargeService
{
    public function generateChargesForRental(Rental $rental): Collection
    {
        $charges = collect();
        $start = Carbon::parse($rental->start_date);
        $end = $rental->end_date ? Carbon::parse($rental->end_date) : $start->copy()->addMonths($rental->period_months);

        while ($start->lte($end)) {
            $periodEnd = $start->copy()->endOfMonth();
            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }

            $existing = RentCharge::where('rental_id', $rental->id)
                ->where('period_start', $start->toDateString())
                ->first();

            if (! $existing) {
                $charges->push($this->generateChargeForPeriod($rental, $start->copy(), $periodEnd));
            }

            $start = $start->copy()->addMonth()->startOfMonth();
        }

        return $charges;
    }

    /**
     * Generate monthly charges for the upcoming $lookaheadMonths months of an active rental,
     * skipping any period that already has a charge. Does not extend beyond the rental end date.
     */
    public function generateRecurringCharges(Rental $rental, int $lookaheadMonths = 6): Collection
    {
        $charges = collect();
        $end = $rental->end_date ? Carbon::parse($rental->end_date) : Carbon::parse($rental->start_date)->addMonths($rental->period_months);

        $start = now()->startOfMonth();
        $limit = now()->copy()->addMonths($lookaheadMonths)->startOfMonth();

        // Walk month by month from today's month forward.
        while ($start->lte($limit)) {
            $periodStart = $start->copy();
            $periodEnd = $start->copy()->endOfMonth();

            if ($periodStart->gt($end)) {
                break;
            }
            if ($periodEnd->gt($end)) {
                $periodEnd = $end->copy();
            }

            $periodStartKey = $periodStart->toDateString();

            $existing = RentCharge::where('rental_id', $rental->id)
                ->where('period_start', $periodStartKey)
                ->first();

            if (! $existing && $periodStartKey >= now()->startOfMonth()->toDateString()) {
                $charges->push($this->generateChargeForPeriod($rental, $periodStart, $periodEnd));
            }

            $start = $start->copy()->addMonth();
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

        // Absorb any credit left on the immediately-preceding charge (an overpayment
        // that had no later charge at the time it was received) into this period.
        $previous = RentCharge::query()
            ->where('rental_id', $rental->id)
            ->where('period_start', '<', $periodStart->toDateString())
            ->orderByDesc('period_start')
            ->first();

        if ($previous) {
            $credit = (float) $previous->paid_amount - (float) $previous->expected_amount;
            if ($credit > 0) {
                $previous->update(['paid_amount' => (float) $previous->expected_amount]);
                $this->updateChargeStatus($previous->fresh());
                $charge->update(['paid_amount' => $credit]);
                $charge = $this->updateChargeStatus($charge->fresh());
            }
        }

        return $charge;
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
