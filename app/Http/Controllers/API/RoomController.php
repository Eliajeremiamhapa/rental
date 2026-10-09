<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\House;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoomController extends Controller
{
    use AuthorizesOwnership;

    public function index(Request $request): JsonResponse
    {
        $rooms = Room::query()
            ->ownedBy($request->user())
            ->with(['house', 'currentRental' => function ($q) {
                $q->with('tenant');
            }])
            ->when($request->house_id, function ($q, $houseId) {
                $q->where('house_id', $houseId);
            })
            ->when($request->status, function ($q, $status) {
                $q->where('status', $status);
            })
            ->when($request->search, function ($q, $search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhereHas('house', function ($hq) use ($search) {
                        $hq->where('name', 'like', "%{$search}%");
                    });
            })
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Rooms retrieved successfully.',
            'data' => $rooms,
        ]);
    }

    public function show(Room $room): JsonResponse
    {
        $this->ensureOwned($room);

        $room->load([
            'house',
            'currentRental' => function ($q) {
                $q->with('tenant');
            },
            'rentCharges' => function ($q) {
                $q->orderByDesc('due_date')->limit(10)->with('payments');
            },
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Room retrieved successfully.',
            'data' => $room,
        ]);
    }

    public function store(Request $request, House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'rent_amount' => 'required|numeric|min:0',
        ]);

        $room = $house->rooms()->create([
            ...$validated,
            'status' => 'available',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Room created successfully.',
            'data' => $room->load('house'),
        ], 201);
    }

    public function update(Request $request, Room $room): JsonResponse
    {
        $this->ensureOwned($room);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'rent_amount' => 'sometimes|numeric|min:0',
        ]);

        $room->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Room updated successfully.',
            'data' => $room->fresh()->load('house'),
        ]);
    }

    public function destroy(Room $room): JsonResponse
    {
        $this->ensureOwned($room);

        $hasActiveRental = $room->rentals()
            ->live()
            ->exists();

        if ($hasActiveRental) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate room with active rental. End the tenancy first.',
            ], 422);
        }

        $room->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'Room deactivated successfully.',
        ]);
    }

    public function history(Room $room): JsonResponse
    {
        $this->ensureOwned($room);

        $rentals = $room->rentals()
            ->with(['tenant', 'rentCharges' => function ($q) {
                $q->orderByDesc('period_start');
            }])
            ->orderByDesc('start_date')
            ->get();

        // The client renders this as a "Rent History" table of charge rows
        // (period / expected / paid / balance / status), so flatten the rentals
        // into their individual rent charges instead of nesting them.
        $charges = $rentals->flatMap(fn (Rental $rental) => $rental->rentCharges->map(fn (RentCharge $charge) => [
            'id' => $charge->id,
            'rental_id' => $rental->id,
            'tenant' => $rental->tenant?->full_name,
            'period' => $charge->period_start?->format('M Y'),
            'period_start' => $charge->period_start?->toDateString(),
            'period_end' => $charge->period_end?->toDateString(),
            'due_date' => $charge->due_date?->toDateString(),
            'expected_amount' => (float) $charge->expected_amount,
            'paid_amount' => (float) $charge->paid_amount,
            'balance' => (float) $charge->expected_amount - (float) $charge->paid_amount,
            'status' => $charge->status,
        ]))->values();

        return response()->json([
            'success' => true,
            'message' => 'Room rent history retrieved successfully.',
            'data' => $charges,
        ]);
    }
}
