<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\RentalRequest;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalRequestController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    /**
     * A tenant requests to rent a room that is listed as available.
     */
    public function store(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'A tenant profile is required to request a room.',
            ], 422);
        }

        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'message' => 'nullable|string|max:1000',
            'desired_start_date' => 'nullable|date|after_or_equal:today',
            'period_months' => 'nullable|integer|min:1|max:60',
        ]);

        $room = Room::findOrFail($validated['room_id']);
        $house = $room->house;

        if (! $house || $house->status !== 'active') {
            abort(404, 'Room not found.');
        }

        if ($room->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'This room is no longer available for rent.',
            ], 422);
        }

        $duplicate = RentalRequest::query()
            ->where('tenant_id', $tenant->id)
            ->where('room_id', $room->id)
            ->where('status', RentalRequest::STATUS_PENDING)
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending request for this room.',
            ], 422);
        }

        $rentalRequest = DB::transaction(function () use ($validated, $tenant, $room, $house) {
            $rentalRequest = RentalRequest::create([
                'tenant_id' => $tenant->id,
                'room_id' => $room->id,
                'house_id' => $house->id,
                'owner_id' => $house->user_id,
                'message' => $validated['message'] ?? null,
                'desired_start_date' => $validated['desired_start_date'] ?? null,
                'period_months' => $validated['period_months'] ?? 12,
                'status' => RentalRequest::STATUS_PENDING,
            ]);

            $this->notifications->notifyOwnerRentalRequestReceived($rentalRequest);

            return $rentalRequest;
        });

        return response()->json([
            'success' => true,
            'message' => 'Your request has been sent to the owner. You will be notified once they respond.',
            'data' => $rentalRequest->load(['room.house', 'house']),
        ], 201);
    }

    /**
     * The tenant's own requests, oldest first is newest-first by default.
     */
    public function tenantIndex(Request $request): JsonResponse
    {
        $requests = RentalRequest::query()
            ->ownedByTenant($request->user())
            ->with(['room.house', 'house', 'tenant.user'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    /**
     * Requests received by the houses of the signed-in owner.
     */
    public function ownerIndex(Request $request): JsonResponse
    {
        $requests = RentalRequest::query()
            ->ownedByOwner($request->user())
            ->with(['room', 'house', 'tenant.user'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'accepted' THEN 1 WHEN 'declined' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests,
        ]);
    }

    public function ownerShow(Request $request, RentalRequest $rentalRequest): JsonResponse
    {
        $this->ensureOwned($rentalRequest);

        $rentalRequest->load([
            'room.house',
            'house',
            'tenant.user',
            'tenant.rentals' => fn ($q) => $q->latest()->limit(5),
        ]);

        return response()->json([
            'success' => true,
            'data' => $rentalRequest,
        ]);
    }

    /**
     * Accepting adopts the tenant (one owner at a time), reserves the room,
     * and creates a status-"pending" rental. No charges exist yet; they are
     * generated when the tenant signs the contract.
     */
    public function accept(Request $request, RentalRequest $rentalRequest): JsonResponse
    {
        $this->ensureOwned($rentalRequest);

        if ($rentalRequest->status !== RentalRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been responded to.',
            ], 422);
        }

        $validated = $request->validate([
            'start_date' => 'nullable|date',
            'rent_amount' => 'nullable|numeric|min:0',
            'period_months' => 'nullable|integer|min:1|max:60',
            'deposit_amount' => 'nullable|numeric|min:0',
            'response_message' => 'nullable|string|max:500',
        ]);

        $owner = $request->user();
        $room = $rentalRequest->room;
        $tenant = $rentalRequest->tenant;

        if (! $room || $room->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'This room is no longer available.',
            ], 422);
        }

        if (! $tenant) {
            abort(404, 'Tenant not found.');
        }

        // One owner at a time: a tenant already renting elsewhere cannot be
        // re-adopted until that tenancy ends.
        if ($tenant->owner_id && $tenant->owner_id !== $owner->id) {
            $hasLiveRental = Rental::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('status', Rental::LIVE_STATUSES)
                ->exists();

            if ($hasLiveRental) {
                return response()->json([
                    'success' => false,
                    'message' => 'This tenant is already renting with another owner.',
                ], 422);
            }
        }

        $startDate = $validated['start_date']
            ? Carbon::parse($validated['start_date'])
            : ($rentalRequest->desired_start_date
                ? Carbon::parse($rentalRequest->desired_start_date)
                : now()->addMonth()->startOfMonth());

        $periodMonths = (int) ($validated['period_months'] ?? $rentalRequest->period_months ?? 12);
        $dueDay = (int) $startDate->format('d');
        $endDate = $startDate->copy()->addMonths($periodMonths)->subDay();

        [$rental, $rentalRequest] = DB::transaction(function () use ($validated, $rentalRequest, $owner, $room, $tenant, $startDate, $endDate, $dueDay, $periodMonths) {
            $tenant->update(['owner_id' => $owner->id]);

            $rental = Rental::create([
                'tenant_id' => $tenant->id,
                'room_id' => $room->id,
                'house_id' => $room->house_id,
                'rent_amount' => $validated['rent_amount'] ?? $room->rent_amount,
                'rent_amount_note' => 'Request-driven tenancy',
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'period_months' => $periodMonths,
                'due_day' => $dueDay,
                'deposit_amount' => $validated['deposit_amount'] ?? 0,
                'deposit_status' => isset($validated['deposit_amount']) && (float) $validated['deposit_amount'] > 0 ? 'pending' : 'none',
                'status' => 'pending',
            ]);

            $room->update(['status' => 'reserved']);

            $rentalRequest->update([
                'status' => RentalRequest::STATUS_ACCEPTED,
                'response_message' => $validated['response_message'] ?? null,
                'response_at' => now(),
            ]);

            $this->notifications->notifyTenantRequestAccepted($rentalRequest);

            return [$rental, $rentalRequest];
        });

        return response()->json([
            'success' => true,
            'message' => 'Request accepted. Create and send a contract so the tenant can sign and start paying.',
            'data' => [
                'rental' => $rental->load(['tenant.user', 'room.house']),
                'rental_request' => $rentalRequest->fresh()->load(['room', 'house', 'tenant.user']),
            ],
        ]);
    }

    public function decline(Request $request, RentalRequest $rentalRequest): JsonResponse
    {
        $this->ensureOwned($rentalRequest);

        if ($rentalRequest->status !== RentalRequest::STATUS_PENDING) {
            return response()->json([
                'success' => false,
                'message' => 'This request has already been responded to.',
            ], 422);
        }

        $validated = $request->validate([
            'response_message' => 'nullable|string|max:1000',
        ]);

        $rentalRequest->update([
            'status' => RentalRequest::STATUS_DECLINED,
            'response_message' => $validated['response_message'] ?? null,
            'response_at' => now(),
        ]);

        $this->notifications->notifyTenantRequestDeclined($rentalRequest);

        return response()->json([
            'success' => true,
            'message' => 'Request declined.',
            'data' => $rentalRequest->fresh()->load(['room', 'house', 'tenant.user']),
        ]);
    }

    protected function ensureOwned(RentalRequest $rentalRequest): void
    {
        $user = auth()->user();

        $owned = RentalRequest::query()
            ->ownedBy($user)
            ->whereKey($rentalRequest->getKey())
            ->exists();

        abort_unless($owned, 404);
    }
}
