<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Services\RentChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RentChargeController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        protected RentChargeService $rentChargeService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $charges = RentCharge::query()
            ->ownedBy($request->user())
            ->with(['tenant', 'room.house', 'rental'])
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->tenant_id, function ($q, $tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->when($request->room_id, function ($q, $roomId) {
                $q->where('room_id', $roomId);
            })
            ->orderByDesc('due_date')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Rent charges retrieved successfully.',
            'data' => $charges,
        ]);
    }

    public function show(RentCharge $rentalCharge): JsonResponse
    {
        $this->ensureOwned($rentalCharge);

        $rentalCharge->load(['tenant', 'room.house', 'rental', 'payments' => function ($q) {
            $q->orderByDesc('payment_date');
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Rent charge retrieved successfully.',
            'data' => $rentalCharge,
        ]);
    }

    public function generate(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403, 'Only owners can generate charges.');

        $validated = $request->validate([
            'rental_id' => 'required|exists:rentals,id',
        ]);

        $rental = Rental::query()
            ->ownedBy($user)
            ->findOrFail($validated['rental_id']);

        if (! in_array($rental->status, Rental::LIVE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot generate charges for a rental that is not running.',
            ], 422);
        }

        $charges = $this->rentChargeService->generateChargesForRental($rental);

        return response()->json([
            'success' => true,
            'message' => count($charges).' charge(s) generated successfully.',
            'data' => $charges,
        ], 201);
    }

    public function upcoming(Request $request): JsonResponse
    {
        $days = (int) ($request->days ?? 7);
        $charges = $this->rentChargeService->getUpcomingCharges($days, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Upcoming charges retrieved successfully.',
            'data' => $charges,
        ]);
    }

    public function overdue(Request $request): JsonResponse
    {
        $charges = $this->rentChargeService->getOverdueCharges($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Overdue charges retrieved successfully.',
            'data' => $charges,
        ]);
    }
}
