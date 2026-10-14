<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\NotificationService;
use App\Services\RentChargeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        protected RentChargeService $rentChargeService,
        protected NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rentals = Rental::query()
            ->ownedBy($request->user())
            ->with(['tenant', 'room', 'room.house'])
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->tenant_id, function ($q, $tenantId) {
                $q->where('tenant_id', $tenantId);
            })
            ->when($request->room_id, function ($q, $roomId) {
                $q->where('room_id', $roomId);
            })
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Rentals retrieved successfully.',
            'data' => $rentals,
        ]);
    }

    public function show(Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        $rental->load(['tenant', 'room.house', 'rentCharges' => function ($q) {
            $q->orderByDesc('period_start')->with('payments');
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Rental retrieved successfully.',
            'data' => $rental,
        ]);
    }

    /**
     * The tenant (or their trusted agent) confirms a reserved room is genuine.
     * Purely informational — it never blocks signing — but it clears the
     * due-diligence note and records the affirmation for dispute history.
     */
    public function confirmProceed(Request $request, Rental $rental): JsonResponse
    {
        abort_unless($rental->tenant?->user_id === $request->user()->id, 404);

        $rental->confirmProceed();
        $this->notifications->notifyReservationConfirmed($rental);

        return response()->json([
            'success' => true,
            'message' => 'Thank you. You can proceed with the next steps whenever you are ready.',
            'data' => $rental->fresh()->load(['tenant', 'room.house']),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->isOwner(), 403, 'Only owners can create rentals.');

        $validated = $request->validate([
            'tenant_id' => 'required|exists:tenants,id',
            'room_id' => 'required|exists:rooms,id',
            'rent_amount' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'period_months' => 'required|integer|min:1',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_notes' => 'nullable|string',
            'rent_amount_note' => 'nullable|string',
        ]);

        $room = Room::query()
            ->ownedBy($user)
            ->findOrFail($validated['room_id']);

        if ($room->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'Room is not available for a new rental.',
            ], 422);
        }

        $tenant = Tenant::query()
            ->ownedBy($user)
            ->findOrFail($validated['tenant_id']);

        $startDate = Carbon::parse($validated['start_date']);
        $dueDay = (int) $startDate->format('d');
        $endDate = $startDate->copy()->addMonths($validated['period_months'])->subDay();

        return DB::transaction(function () use ($validated, $room, $dueDay, $endDate) {
            $rental = Rental::create([
                ...$validated,
                'house_id' => $room->house_id,
                'end_date' => $endDate->toDateString(),
                'due_day' => $dueDay,
                'deposit_status' => isset($validated['deposit_amount']) ? 'paid' : 'none',
                'status' => 'active',
            ]);

            $this->rentChargeService->generateChargesForRental($rental);

            $room->update(['status' => 'occupied']);

            return response()->json([
                'success' => true,
                'message' => 'Rental created successfully.',
                'data' => $rental->load(['tenant', 'room.house']),
            ], 201);
        });
    }

    public function update(Request $request, Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        $validated = $request->validate([
            'rent_amount' => 'sometimes|numeric|min:0',
            'period_months' => 'sometimes|integer|min:1',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_status' => 'nullable|in:paid,pending,held,returned,deducted,none',
            'deposit_notes' => 'nullable|string',
            'rent_amount_note' => 'nullable|string',
        ]);

        $rental->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Rental updated successfully.',
            'data' => $rental->fresh()->load(['tenant', 'room.house']),
        ]);
    }

    public function renew(Request $request, Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        $validated = $request->validate([
            'rent_amount' => 'nullable|numeric|min:0',
            'start_date' => 'required|date|after:today',
            'period_months' => 'required|integer|min:1',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_notes' => 'nullable|string',
            'rent_amount_note' => 'nullable|string',
        ]);

        $startDate = Carbon::parse($validated['start_date']);
        $dueDay = (int) $startDate->format('d');
        $endDate = $startDate->copy()->addMonths($validated['period_months'])->subDay();

        return DB::transaction(function () use ($validated, $rental, $startDate, $dueDay, $endDate) {
            $rental->update(['status' => 'expired']);

            $newRental = Rental::create([
                'tenant_id' => $rental->tenant_id,
                'room_id' => $rental->room_id,
                'house_id' => $rental->house_id,
                'rent_amount' => $validated['rent_amount'] ?? $rental->rent_amount,
                'rent_amount_note' => $validated['rent_amount_note'] ?? null,
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'period_months' => $validated['period_months'],
                'due_day' => $dueDay,
                'deposit_amount' => $validated['deposit_amount'] ?? 0,
                'deposit_status' => isset($validated['deposit_amount']) ? 'paid' : 'none',
                'deposit_notes' => $validated['deposit_notes'] ?? null,
                'status' => 'active',
            ]);

            $this->rentChargeService->generateChargesForRental($newRental);

            return response()->json([
                'success' => true,
                'message' => 'Rental renewed successfully.',
                'data' => $newRental->load(['tenant', 'room.house', 'rentCharges']),
            ], 201);
        });
    }

    public function terminate(Request $request, Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        if (! in_array($rental->status, Rental::LIVE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Rental is not running.',
            ], 422);
        }

        $validated = $request->validate([
            'terminated_reason' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($rental, $validated) {
            $rental->update([
                'status' => 'terminated',
                'terminated_at' => now(),
                'terminated_reason' => $validated['terminated_reason'] ?? null,
            ]);

            $rental->room->update(['status' => 'available']);

            return response()->json([
                'success' => true,
                'message' => 'Rental terminated successfully.',
                'data' => $rental->fresh()->load(['tenant', 'room.house']),
            ]);
        });
    }

    public function charges(Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        $charges = $rental->rentCharges()
            ->with('payments')
            ->orderByDesc('period_start')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Rent charges retrieved successfully.',
            'data' => $charges,
        ]);
    }

    public function destroy(Rental $rental): JsonResponse
    {
        $this->ensureOwned($rental);

        if (in_array($rental->status, Rental::LIVE_STATUSES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'A running rental cannot be deleted. Terminate it instead.',
            ], 422);
        }

        return DB::transaction(function () use ($rental) {
            // Remove dependent records before deleting the rental.
            foreach ($rental->rentCharges as $charge) {
                foreach ($charge->payments as $payment) {
                    $payment->receipt()?->delete();
                    $payment->delete();
                }
                $charge->delete();
            }

            $rental->delete();

            return response()->json([
                'success' => true,
                'message' => 'Rental deleted successfully.',
            ]);
        });
    }
}
