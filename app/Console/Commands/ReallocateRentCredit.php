<?php

namespace App\Console\Commands;

use App\Models\Rental;
use App\Services\RentChargeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-spread payments across each rental's charges, oldest debt first.
 *
 * Payments used to be apportioned only forward from the charge the payer
 * targeted, so a lump sum aimed at a later period left an earlier period unpaid
 * and stranded the excess as credit on a single charge. Running this repairs
 * rentals recorded before that behaviour was fixed. It is idempotent and safe
 * to run repeatedly; use --apply to commit, otherwise it reports only.
 */
class ReallocateRentCredit extends Command
{
    protected $signature = 'rental:reallocate-credit
        {rental? : Rental ID to reallocate (all rentals if omitted)}
        {--apply : Commit the changes (default is a dry run)}';

    protected $description = 'Re-distribute recorded payments across a rental\'s charges, oldest debt first';

    public function handle(RentChargeService $rentChargeService): int
    {
        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->warn('DRY RUN: no changes written. Re-run with --apply to commit.');
        }

        $query = Rental::query()->orderBy('id');

        if ($rentalId = $this->argument('rental')) {
            $query->whereKey($rentalId);
        }

        $rentals = $query->get();

        if ($rentals->isEmpty()) {
            $this->info('No rentals found.');

            return self::SUCCESS;
        }

        $changed = 0;

        foreach ($rentals as $rental) {
            $before = $this->snapshot($rental->id);
            $diff = $this->diff($before, $this->expected($rental->id));

            if ($diff === '') {
                continue;
            }

            $changed++;
            $this->line("Rental #{$rental->id}:");
            $this->line($diff);

            if ($apply) {
                DB::transaction(fn () => $rentChargeService->reallocateCredit($rental->id));
            }
        }

        $this->info($apply
            ? "Reallocated {$changed} rental(s)."
            : "DRY RUN: {$changed} rental(s) would change.");

        return self::SUCCESS;
    }

    /** @return array<int, array{paid: float, status: string}> */
    private function snapshot(int $rentalId): array
    {
        return DB::table('rent_charges')
            ->where('rental_id', $rentalId)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get(['id', 'paid_amount', 'status'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => [
                'paid' => (float) $c->paid_amount,
                'status' => (string) $c->status,
            ]])
            ->all();
    }

    /**
     * The distribution reallocateCredit() would produce, computed without
     * writing so a dry run can show the diff.
     *
     * @return array<int, array{paid: float, status: string}>
     */
    private function expected(int $rentalId): array
    {
        $charges = DB::table('rent_charges')
            ->where('rental_id', $rentalId)
            ->orderBy('period_start')
            ->orderBy('id')
            ->get(['id', 'expected_amount', 'paid_amount', 'status', 'due_date']);

        $pool = (float) $charges->sum(fn ($c) => (float) $c->paid_amount);
        $result = [];

        foreach ($charges as $c) {
            $expected = (float) $c->expected_amount;
            $applied = max(0.0, min($pool, $expected));
            $pool -= $applied;

            $status = $applied <= 0
                ? 'unpaid'
                : ($applied >= $expected ? 'paid' : 'partial');

            if (in_array($status, ['unpaid', 'partial'], true)
                && $c->due_date
                && $c->due_date < now()->toDateString()) {
                $status = 'overdue';
            }

            $result[(int) $c->id] = ['paid' => $applied, 'status' => $status];
        }

        if ($pool > 0 && $charges->isNotEmpty()) {
            $lastId = (int) $charges->last()->id;
            $result[$lastId]['paid'] += $pool;
            $result[$lastId]['status'] = 'paid';
        }

        return $result;
    }

    /**
     * @param  array<int, array{paid: float, status: string}>  $before
     * @param  array<int, array{paid: float, status: string}>  $after
     */
    private function diff(array $before, array $after): string
    {
        $lines = '';

        foreach ($after as $id => $to) {
            $from = $before[$id] ?? ['paid' => 0.0, 'status' => 'unpaid'];

            if ($from['status'] !== $to['status'] || abs($from['paid'] - $to['paid']) > 0.00001) {
                $lines .= sprintf(
                    '  charge #%d: %s / %s -> %s / %s'.PHP_EOL,
                    $id,
                    number_format($from['paid'], 0),
                    $from['status'],
                    number_format($to['paid'], 0),
                    $to['status'],
                );
            }
        }

        return $lines;
    }
}
