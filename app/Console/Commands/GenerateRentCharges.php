<?php

namespace App\Console\Commands;

use App\Models\Rental;
use App\Services\RentChargeService;
use Illuminate\Console\Command;

class GenerateRentCharges extends Command
{
    protected $signature = 'rental:generate-charges';

    protected $description = 'Generate rent charges for upcoming periods of all running rentals';

    public function handle(RentChargeService $rentChargeService): int
    {
        // Generate charges for the next several months for all still-running
        // rentals ("active" and "expiring_soon") that have not yet been extended.
        $rentals = Rental::live()->get();

        $totalGenerated = 0;
        foreach ($rentals as $rental) {
            $charges = $rentChargeService->generateRecurringCharges($rental, 6);
            $totalGenerated += $charges->count();
        }

        $this->info("Generated {$totalGenerated} rent charge(s) for {$rentals->count()} running rental(s).");

        return self::SUCCESS;
    }
}
