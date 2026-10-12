<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Payment;
use App\Models\RentalRequest;
use App\Models\RentCharge;
use Illuminate\Database\Eloquent\Model;

class NotificationService
{
    public function __construct(
        protected SmsService $sms,
    ) {}

    public function create(
        int $userId,
        string $type,
        string $title,
        string $message,
        ?Model $related = null,
    ): Notification {
        return Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'related_type' => $related ? get_class($related) : null,
            'related_id' => $related?->id,
            'created_at' => now(),
        ]);
    }

    public function getUnreadCount(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->count();
    }

    public function markAsRead(int $notificationId): Notification
    {
        $notification = Notification::findOrFail($notificationId);
        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $notification->fresh();
    }

    public function markAllAsRead(int $userId): int
    {
        return Notification::where('user_id', $userId)
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);
    }

    protected function sms(?string $phone, string $message): void
    {
        if ($phone && $this->sms->isConfigured()) {
            $this->sms->send($phone, $message);
        }
    }

    public function notifyExpiringSoon(RentCharge $charge, int $daysUntilDue): ?Notification
    {
        $tenant = $charge->tenant;
        $user = $tenant?->user;
        $roomName = $charge->room?->name ?? 'your room';
        $amount = number_format($charge->expected_amount);
        $dueDate = $charge->due_date?->format('d M Y') ?? 'soon';

        $this->notifyOwnerRentExpiringSoon($charge, $daysUntilDue);

        $this->sms($user?->phone, "Dear {$tenant?->full_name}, your rent for {$roomName} is due in {$daysUntilDue} day(s) on {$dueDate}. Amount: TZS {$amount}. Please pay on time.");

        $ownerPhone = $charge->room?->house?->owner?->phone;
        $this->sms($ownerPhone, "{$tenant?->full_name}'s rent for {$roomName} is due in {$daysUntilDue} day(s) on {$dueDate}. Amount: TZS {$amount}.");

        if (! $user) {
            // No login account for this tenant: there is no valid recipient, and
            // notifications.user_id is a non-nullable foreign key.
            return null;
        }

        return $this->create(
            $user->id,
            'rent_expiring',
            'Rent Expiring Soon',
            "Your rent for {$charge->room?->name} is due in {$daysUntilDue} day(s). Amount: {$charge->expected_amount}",
            $charge,
        );
    }

    public function notifyRentDue(RentCharge $charge): ?Notification
    {
        $tenant = $charge->tenant;
        $user = $tenant?->user;
        $roomName = $charge->room?->name ?? 'your room';
        $amount = number_format($charge->expected_amount);

        $this->notifyOwnerRentDue($charge);

        $this->sms($user?->phone, "Dear {$tenant?->full_name}, your rent payment of TZS {$amount} for {$roomName} is now due. Please pay today.");

        $ownerPhone = $charge->room?->house?->owner?->phone;
        $this->sms($ownerPhone, "{$tenant?->full_name}'s rent payment of TZS {$amount} for {$roomName} is now due.");

        if (! $user) {
            return null;
        }

        return $this->create(
            $user->id,
            'rent_due',
            'Rent Payment Due',
            "Your rent payment of {$charge->expected_amount} for {$roomName} is now due.",
            $charge,
        );
    }

    public function notifyOwnerRentExpiringSoon(RentCharge $charge, int $daysUntilDue): ?Notification
    {
        $ownerId = $this->ownerIdForCharge($charge);
        if (! $ownerId) {
            return null;
        }

        return $this->create(
            $ownerId,
            'owner_rent_expiring',
            'Tenant Rent Expiring Soon',
            "{$charge->tenant?->full_name}'s rent for {$charge->room?->name} is due in {$daysUntilDue} day(s). Amount: {$charge->expected_amount}",
            $charge,
        );
    }

    public function notifyOwnerRentDue(RentCharge $charge): ?Notification
    {
        $ownerId = $this->ownerIdForCharge($charge);
        if (! $ownerId) {
            return null;
        }

        return $this->create(
            $ownerId,
            'owner_rent_due',
            'Tenant Rent Payment Due',
            "{$charge->tenant?->full_name}'s rent payment of {$charge->expected_amount} for {$charge->room?->name} is now due.",
            $charge,
        );
    }

    public function notifyOwnerOverdue(RentCharge $charge): ?Notification
    {
        $ownerId = $this->ownerIdForCharge($charge);
        if (! $ownerId) {
            return null;
        }

        $tenantName = $charge->tenant?->full_name ?? 'Tenant';
        $roomName = $charge->room?->name ?? 'a room';
        $dueDate = $charge->due_date?->format('d M Y') ?? '';
        $amount = number_format($charge->expected_amount);
        $overdueNote = $dueDate ? ' since '.$dueDate : '';

        return $this->create(
            $ownerId,
            'owner_rent_overdue',
            'Tenant Rent Overdue',
            "{$tenantName}'s rent payment of {$amount} for {$roomName} is overdue{$overdueNote}. Please follow up.",
            $charge,
        );
    }

    protected function ownerIdForCharge(RentCharge $charge): ?int
    {
        $house = $charge->room?->house;

        return $house?->user_id;
    }

    public function notifyOverdue(RentCharge $charge): ?Notification
    {
        $tenant = $charge->tenant;
        $user = $tenant?->user;
        $roomName = $charge->room?->name ?? 'your room';
        $amount = number_format($charge->expected_amount);
        $dueDate = $charge->due_date?->format('d M Y') ?? '';
        $overdueNote = $dueDate ? ' since '.$dueDate : '';

        $this->notifyOwnerOverdue($charge);

        $this->sms($user?->phone, "Dear {$tenant?->full_name}, your rent payment of TZS {$amount} for {$roomName} is overdue. Please pay immediately.");

        $ownerPhone = $charge->room?->house?->owner?->phone;
        $this->sms($ownerPhone, "{$tenant?->full_name}'s rent payment of TZS {$amount} for {$roomName} is overdue{$overdueNote}. Please follow up.");

        if (! $user) {
            return null;
        }

        return $this->create(
            $user->id,
            'rent_overdue',
            'Rent Payment Overdue',
            "Your rent payment of {$charge->expected_amount} for {$roomName} is overdue. Please pay immediately.",
            $charge,
        );
    }

    public function notifyPaymentReceived(Payment $payment): ?Notification
    {
        $tenant = $payment->tenant;
        $user = $tenant?->user;

        if (! $user) {
            return null;
        }

        return $this->create(
            $user->id,
            'payment_received',
            'Payment Received',
            "Your payment of {$payment->amount} has been received successfully. Reference: {$payment->reference_number}",
            $payment,
        );
    }

    public function notifyOwnerPaymentReceived(Payment $payment): ?Notification
    {
        $house = $payment->rentCharge?->room?->house;
        if (! $house) {
            return null;
        }

        $period = $payment->rentCharge?->period_start;
        $rentPeriod = $period ? $period->format('M Y') : 'Unknown';

        $payerName = $payment->tenant?->full_name ?? 'Tenant';
        $roomName = $payment->rentCharge?->room?->name ?? 'a room';

        return $this->create(
            $house->user_id,
            'owner_payment_received',
            'Payment Received',
            "{$payerName} paid {$payment->amount} for {$roomName} ({$house->name}), period {$rentPeriod}.",
            $payment,
        );
    }

    public function notifyOwnerRentalRequestReceived(RentalRequest $request): Notification
    {
        $tenant = $request->tenant;

        return $this->create(
            $request->owner_id,
            'rental_request_received',
            'New Rental Request',
            "{$tenant?->full_name} requested {$request->room?->name} at {$request->house?->name}.",
            $request,
        );
    }

    public function notifyTenantRequestAccepted(RentalRequest $request): ?Notification
    {
        $userId = $request->tenant?->user_id;
        if (! $userId) {
            return null;
        }

        return $this->create(
            $userId,
            'rental_request_accepted',
            'Rental Request Accepted',
            "Your request for {$request->room?->name} at {$request->house?->name} was accepted. The owner will send a contract for you to sign.",
            $request,
        );
    }

    public function notifyTenantRequestDeclined(RentalRequest $request): ?Notification
    {
        $userId = $request->tenant?->user_id;
        if (! $userId) {
            return null;
        }

        return $this->create(
            $userId,
            'rental_request_declined',
            'Rental Request Declined',
            $request->response_message
                ? "Your request for {$request->room?->name} was declined: {$request->response_message}"
                : "Your request for {$request->room?->name} was declined.",
            $request,
        );
    }
}
