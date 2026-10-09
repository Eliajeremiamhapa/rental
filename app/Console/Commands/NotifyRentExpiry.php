<?php

namespace App\Console\Commands;

use App\Models\RentCharge;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class NotifyRentExpiry extends Command
{
    protected $signature = 'rental:notify-expiry';

    protected $description = 'Send expiring-soon notifications (3 days before) and rent-due notifications (on due date)';

    public function handle(NotificationService $notificationService): int
    {
        $today = Carbon::today();

        // Rent due today
        $dueToday = RentCharge::with(['tenant.user', 'room.house.owner'])
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', $today->toDateString())
            ->get();

        foreach ($dueToday as $charge) {
            $notificationService->notifyRentDue($charge);
        }

        // Expiring soon (due in 3 days)
        $expiring = RentCharge::with(['tenant.user', 'room.house.owner'])
            ->whereIn('status', ['unpaid', 'partial'])
            ->whereDate('due_date', $today->copy()->addDays(3)->toDateString())
            ->get();

        foreach ($expiring as $charge) {
            $notificationService->notifyExpiringSoon($charge, 3);
        }

        $this->info("Sent {$dueToday->count()} rent-due and {$expiring->count()} expiring-soon notification(s).");

        return self::SUCCESS;
    }
}
