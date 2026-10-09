<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\RentCharge;
use App\Services\NotificationService;
use Illuminate\Console\Command;

class NotifyRentOverdue extends Command
{
    protected $signature = 'rental:notify-overdue';

    protected $description = 'Send overdue notifications for unpaid rent charges past their due date';

    public function handle(NotificationService $notificationService): int
    {
        $overdueCharges = RentCharge::with(['tenant.user', 'room.house.owner'])
            ->where('status', 'overdue')
            ->get();

        $sent = 0;
        foreach ($overdueCharges as $charge) {
            // Skip if an overdue notification already exists for this charge
            $alreadyNotified = Notification::where('type', 'rent_overdue')
                ->where('related_type', RentCharge::class)
                ->where('related_id', $charge->id)
                ->exists();

            if (! $alreadyNotified) {
                $notificationService->notifyOverdue($charge);
                $sent++;
            }
        }

        $this->info("Sent {$sent} overdue notification(s) for {$overdueCharges->count()} overdue charge(s).");

        return self::SUCCESS;
    }
}
