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

            // Overpayment is allowed: the full received amount is recorded against
            // the targeted charge, and any excess cascades into the next unpaid
            // charges of the same rental (chronological). If there is no later
            // charge left, the surplus stays on the targeted charge as a credit
            // (paid_amount may exceed expected_amount) and is consumed when the
            // next period's charge is generated.
            $chain = RentCharge::query()
                ->where('rental_id', $charge->rental_id)
                ->whereIn('status', ['unpaid', 'partial'])
                ->where('period_start', '>=', $charge->period_start->toDateString())
                ->orderBy('period_start')
                ->lockForUpdate()
                ->get();

            $amount = (float) $data['amount'];
            $allocations = []; // [charge_id => amount]

            foreach ($chain as $c) {
                if ($amount <= 0) {
                    break;
                }

                $balance = (float) $c->expected_amount - (float) $c->paid_amount;
                if ($balance <= 0) {
                    continue;
                }

                $applied = min($amount, $balance);
                $amount -= $applied;
                $allocations[$c->id] = ($allocations[$c->id] ?? 0) + $applied;
            }

            // Surplus that no later charge can absorb stays on the targeted charge as credit.
            if ($amount > 0) {
                $allocations[$charge->id] = ($allocations[$charge->id] ?? 0) + $amount;
            }

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

            foreach ($allocations as $chargeId => $applied) {
                $c = RentCharge::query()->whereKey($chargeId)->lockForUpdate()->firstOrFail();
                $c->update(['paid_amount' => (float) $c->paid_amount + $applied]);
                $this->rentChargeService->updateChargeStatus($c->fresh());
            }

            $receipt = $this->receiptService->generateReceipt($payment);

            $oldValues = ['paid_amount' => $charge->paid_amount, 'status' => $charge->status];
            $newValues = ['paid_amount' => $charge->fresh()->paid_amount, 'status' => $charge->fresh()->status];

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
