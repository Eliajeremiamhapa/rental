<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        protected ReceiptService $receiptService,
        protected NotificationService $notificationService,
        protected AuditService $auditService,
        protected RentChargeService $rentChargeService,
    ) {}

    public function recordPayment(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // Lock the targeted charge row so two concurrent payments cannot both
            // pass the remaining-balance check and over-collect.
            $charge = RentCharge::query()
                ->whereKey($data['rent_charge_id'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($data['amount'] <= 0) {
                throw new \InvalidArgumentException('Payment amount must be greater than zero.');
            }

            $oldValues = [
                'paid_amount' => (float) $charge->paid_amount,
                'status' => $charge->status,
            ];

            // The payment is recorded against the targeted charge, then the whole
            // rental is re-spread oldest-debt-first. This lets a lump sum settle
            // earlier arrears and lets an overpayment cover the following period
            // instead of sitting on one charge as credit the next period cannot
            // reach.
            $payment = Payment::create([
                'rent_charge_id' => $charge->id,
                'tenant_id' => $charge->tenant_id,
                'recorded_by' => $data['recorded_by'],
                'amount' => $data['amount'],
                'payment_date' => $data['payment_date'],
                'payment_method' => $data['payment_method'],
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $charge->update([
                'paid_amount' => (float) $charge->paid_amount + (float) $payment->amount,
            ]);
            $this->rentChargeService->reallocateCredit($charge->rental_id);

            $receipt = $this->receiptService->generateReceipt($payment);

            $charge = $charge->fresh();
            $newValues = [
                'paid_amount' => (float) $charge->paid_amount,
                'status' => $charge->status,
            ];

            $recorder = User::findOrFail($data['recorded_by']);
            $this->auditService->log($recorder, 'payment_recorded', $payment, $oldValues, $newValues);

            $this->notificationService->notifyPaymentReceived($payment);
            $this->notificationService->notifyOwnerPaymentReceived($payment);

            return [
                'payment' => $payment->fresh(),
                'receipt' => $receipt,
            ];
        });
    }

    public function getTenantPayments(int $tenantId): Collection
    {
        return Payment::where('tenant_id', $tenantId)
            ->with('rentCharge.room', 'receipt')
            ->orderByDesc('payment_date')
            ->get();
    }

    public function getPaymentWithReceipt(int $paymentId): Payment
    {
        return Payment::with('receipt', 'rentCharge.room', 'tenant', 'recorder')
            ->findOrFail($paymentId);
    }
}
