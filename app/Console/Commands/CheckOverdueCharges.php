<?php

namespace App\Console\Commands;

use App\Services\RentChargeService;
use Illuminate\Console\Command;

class CheckOverdueCharges extends Command
{
    protected $signature = 'rental:check-overdue';

    protected $description = 'Mark unpaid/partial rent charges that are past due as overdue';

    public function handle(RentChargeService $rentChargeService): int
    {
        $count = $rentChargeService->checkOverdue();

        $this->info("Marked {$count} charge(s) as overdue.");

        return self::SUCCESS;
    }
}
