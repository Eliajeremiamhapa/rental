<?php

namespace App\Console\Commands;

use App\Models\Rental;
use App\Models\RentCharge;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CheckRentalExpiry extends Command
{
    protected $signature = 'rental:check-expiry';

    protected $description = 'Update running rental statuses to expiring_soon or expired based on end_date, and mark overdue charges';

    public function handle(): int
    {
        $today = Carbon::today();

        // Still-running rentals whose end date has passed -> expired. This must
        // cover "expiring_soon" as well, otherwise a rental that was flagged
        // beforehand is never retired and keeps holding its room forever.
        $expired = Rental::live()
            ->whereDate('end_date', '<', $today->toDateString())
            ->get();

        foreach ($expired as $rental) {
            $rental->update(['status' => 'expired']);
            // Free up the room when a rental expires (only if no other running rental on it)
            if ($rental->room && ! $rental->room->rentals()->live()->where('id', '!=', $rental->id)->exists()) {
                $rental->room->update(['status' => 'available']);
            }
        }

        // Active rentals ending within the next 3 days -> expiring_soon
        $expiringSoon = Rental::where('status', 'active')
            ->whereDate('end_date', '>=', $today->toDateString())
            ->whereDate('end_date', '<=', $today->copy()->addDays(3)->toDateString())
            ->get();

        foreach ($expiringSoon as $rental) {
            $rental->update(['status' => 'expiring_soon']);
        }

        // Mark overdue charges
        $overdueCharges = RentCharge::whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', '<', $today->toDateString())
            ->get();

        $overdueCount = 0;
        foreach ($overdueCharges as $charge) {
            $charge->update(['status' => 'overdue']);
            $overdueCount++;
        }

        $this->info("Expired {$expired->count()} rental(s), flagged {$expiringSoon->count()} as expiring soon, marked {$overdueCount} charge(s) overdue.");

        return self::SUCCESS;
    }
}
