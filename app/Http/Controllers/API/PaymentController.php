<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\RentCharge;
use App\Models\Tenant;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        protected PaymentService $paymentService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $payments = Payment::query()
            ->ownedBy($request->user())
            ->with(['tenant', 'rentCharge.room.house', 'receipt', 'recorder'])
            ->when($request->tenant_id, function ($q, $tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->when($request->payment_method, function ($q, $method) {
                $q->where('payment_method', $method);
            })
            ->when($request->start_date, function ($q, $date) {
                $q->where('payment_date', '>=', $date);
            })
            ->when($request->end_date, function ($q, $date) {
                $q->where('payment_date', '<=', $date);
            })
            ->orderByDesc('payment_date')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Payments retrieved successfully.',
            'data' => $payments,
        ]);
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->ensureOwned($payment);

        $payment->load(['tenant', 'rentCharge.room.house', 'receipt', 'recorder']);

        return response()->json([
            'success' => true,
            'message' => 'Payment retrieved successfully.',
            'data' => $payment,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'rent_charge_id' => 'required|exists:rent_charges,id',
            'amount' => 'required|numeric|min:0.01',
            'payment_date' => 'required|date',
            'payment_method' => 'required|in:cash,mobile_money,bank_transfer,cheque,card,other',
            'reference_number' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $user = $request->user();

        $charge = RentCharge::query()
            ->ownedBy($user)
            ->findOrFail($validated['rent_charge_id']);

        if ($user->isOwner()) {
            Tenant::query()
                ->ownedBy($user)
                ->findOrFail($validated['tenant_id']);
        } elseif ((int) $charge->tenant_id !== (int) $validated['tenant_id']) {
            abort(404, 'Payment not found.');
        }

        try {
            $result = $this->paymentService->recordPayment([
                ...$validated,
                'rent_charge_id' => $charge->id,
                'recorded_by' => $user->id,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment recorded successfully.',
                'data' => $result,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function tenantHistory(Request $request, int $tenantId): JsonResponse
    {
        $user = $request->user();

        if ($user->isOwner()) {
            Tenant::query()
                ->ownedBy($user)
                ->findOrFail($tenantId);
        } elseif (! $user->tenant || (int) $user->tenant->id !== $tenantId) {
            abort(404, 'Not found.');
        }

        $payments = $this->paymentService->getTenantPayments($tenantId);

        return response()->json([
            'success' => true,
            'message' => 'Tenant payment history retrieved successfully.',
            'data' => $payments,
        ]);
    }
}
