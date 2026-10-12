<?php

namespace App\Console\Commands;

use App\Models\Rental;
use App\Models\RentCharge;
use App\Services\RentChargeService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Bring existing rent charges in line with the deterministic monthly
 * installment schedule introduced in RentChargeService.
 *
 * Rentals created before that change can hold surplus charges (an N-month
 * tenancy billed N+1 full months) or a single charge from the old assignment
 * flow. Without --apply this prints a dry-run diff; with --apply the changes
 * are committed inside a transaction. Charges that carry payments are never
 * deleted - their period dates are re-based, and any that cannot be mapped to
 * an expected period are reported instead of altered.
 */
class ReconcileRentCharges extends Command
{
    protected $signature = 'rental:reconcile-charges {--apply : Commit the changes (default is a dry run)}';

    protected $description = 'Reconcile existing rent charges with the rental period schedule';

    public function handle(RentChargeService $rentChargeService): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN: no changes written. Re-run with --apply to commit.');
        }

        $rentals = Rental::with(['rentCharges.payments'])->orderBy('id')->get();

        if ($rentals->isEmpty()) {
            $this->info('No rentals to reconcile.');

            return self::SUCCESS;
        }

        $totals = ['matched' => 0, 'created' => 0, 'deleted' => 0, 'flagged' => 0];

        foreach ($rentals as $rental) {
            $result = $this->reconcileRental($rental, $rentChargeService, $apply);

            foreach (['matched', 'created', 'deleted', 'flagged'] as $key) {
                $totals[$key] += $result[$key];
            }

            $this->line(
                sprintf(
                    'Rental #%d (%s -> %s): %d matched, %d to create, %d to delete, %d flagged.',
                    $rental->id,
                    $rental->start_date?->toDateString(),
                    $rental->end_date?->toDateString() ?? 'ongoing',
                    $result['matched'],
                    $result['created'],
                    $result['deleted'],
                    $result['flagged'],
                )
            );

            foreach ($result['details'] as $detail) {
                $this->line('  '.$detail);
            }
        }

        $this->info(sprintf(
            'Totals: %d matched, %d created, %d deleted, %d flagged across %d rental(s).',
            $totals['matched'],
            $totals['created'],
            $totals['deleted'],
            $totals['flagged'],
            $rentals->count(),
        ));

        return self::SUCCESS;
    }

    /**
     * @return array{matched: int, created: int, deleted: int, flagged: int, details: array<int, string>}
     */
    private function reconcileRental(Rental $rental, RentChargeService $service, bool $apply): array
    {
        $expected = $service->expectedPeriods($rental);
        $expectedByKey = collect($expected)
            ->keyBy(fn ($period) => $period['period_start']->toDateString());

        $charges = $rental->rentCharges->sortBy('period_start')->values();

        $matched = []; // [rent_charge, expected_key]
        $surplus = [];
        $usedExpectedKeys = [];

        foreach ($charges as $charge) {
            $key = $charge->period_start?->toDateString();

            if ($key && $expectedByKey->has($key) && ! in_array($key, $usedExpectedKeys, true)) {
                $usedExpectedKeys[] = $key;
                $matched[] = [$charge, $key];
            } else {
                $surplus[] = $charge;
            }
        }

        // Expected periods that have no charge yet.
        $remainingKeys = collect($expectedByKey->keys())
            ->filter(fn ($key) => ! in_array($key, $usedExpectedKeys, true))
            ->values();

        // Prefer surplus charges that carry money to fill missing periods, so
        // existing payments stay attached to a billable period.
        $reusable = collect($surplus)
            ->filter(fn ($charge) => $this->carriesMoney($charge))
            ->sortByDesc(fn ($charge) => (float) $charge->paid_amount)
            ->values();

        $details = [];
        $flagged = 0;
        $created = 0;
        $reusedIds = [];
        $reuseCount = 0;

        foreach ($remainingKeys as $key) {
            $period = $expectedByKey[$key];
            $surplusCharge = $reusable->first(fn ($charge) => ! in_array($charge->id, $reusedIds, true));

            if ($surplusCharge) {
                $reusedIds[] = $surplusCharge->id;
                $reuseCount++;
                $matched[] = [$surplusCharge, $key];
                $usedExpectedKeys[] = $key;

                if ($apply) {
                    $surplusCharge->update([
                        'period_start' => $period['period_start']->toDateString(),
                        'period_end' => $period['period_end']->toDateString(),
                        'due_date' => $period['due_date']->toDateString(),
                    ]);
                    $this->rebase($surplusCharge->fresh(), $period, $service, $apply);
                }

                $details[] = sprintf(
                    'Re-use charge #%d (TZS %s paid) for period %s',
                    $surplusCharge->id,
                    number_format((float) $surplusCharge->paid_amount, 0),
                    $period['period_start']->toDateString(),
                );
            } else {
                $created++;

                if ($apply) {
                    $service->generateChargeForPeriod($rental, $period['period_start']->copy(), $period['period_end']->copy());
                }

                $details[] = sprintf('Create charge for period %s', $period['period_start']->toDateString());
            }
        }

        // Re-base period metadata (not amounts) of every kept charge.
        foreach ($matched as [$charge, $key]) {
            if (! $expectedByKey->has($key)) {
                continue;
            }

            $this->rebase($charge, $expectedByKey[$key], $service, $apply, $details);
        }

        $matchedIds = collect($matched)->map(fn ($pair) => $pair[0]->id)->all();
        $deleted = 0;

        // Empty surplus charges can be removed; ones holding money are flagged.
        foreach ($surplus as $charge) {
            if (in_array($charge->id, $matchedIds, true) || in_array($charge->id, $reusedIds, true)) {
                continue;
            }

            if ($this->carriesMoney($charge)) {
                $flagged++;
                $details[] = sprintf(
                    'FLAG charge #%d (%s): holds money / payments, manual review required',
                    $charge->id,
                    $charge->period_start?->toDateString(),
                );

                continue;
            }

            $deleted++;

            if ($apply) {
                $charge->delete();
            }

            $details[] = sprintf('Delete surplus charge #%d (%s)', $charge->id, $charge->period_start?->toDateString());
        }

        return [
            'matched' => count($matched) - $reuseCount - $created,
            'created' => $created,
            'deleted' => $deleted,
            'flagged' => $flagged,
            'details' => $details,
        ];
    }

    /**
     * @param  array{period_end: Carbon, due_date: Carbon}  $period
     * @param  array<int, string>|null  $details
     */
    private function rebase(RentCharge $charge, array $period, RentChargeService $service, bool $apply, ?array &$details = null): void
    {
        if ($apply) {
            $charge->update([
                'period_end' => $period['period_end']->toDateString(),
                'due_date' => $period['due_date']->toDateString(),
            ]);
            $service->updateChargeStatus($charge->fresh());

            return;
        }

        if ($details !== null && $charge->period_end?->toDateString() !== $period['period_end']->toDateString()) {
            $details[] = sprintf(
                'Re-base charge #%d: period end %s -> %s',
                $charge->id,
                $charge->period_end?->toDateString(),
                $period['period_end']->toDateString(),
            );
        }
    }

    private function carriesMoney(RentCharge $charge): bool
    {
        return (float) $charge->paid_amount > 0 || $charge->payments->isNotEmpty();
    }
}
