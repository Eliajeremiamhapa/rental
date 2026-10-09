<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\House;
use App\Services\BalanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        protected BalanceService $balanceService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $houses = $user->houses()
            ->withCount([
                'rooms',
                'rooms as occupied_rooms' => function ($q) {
                    $q->where('status', 'occupied');
                },
                'rooms as available_rooms' => function ($q) {
                    $q->where('status', 'available');
                },
                'rooms as maintenance_rooms' => function ($q) {
                    $q->where('status', 'maintenance');
                },
            ])
            ->withSum(['rooms as total_monthly_rent'], 'rent_amount')
            ->when($request->search, function ($q, $search) {
                // Grouped so the OR cannot escape the user_id ownership constraint.
                $q->where(function ($wq) use ($search) {
                    $wq->where('name', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('updated_at')
            ->get();

        $houses->each(function ($house) {
            $house->occupancy_rate = $house->rooms_count > 0
                ? round(($house->occupied_rooms / $house->rooms_count) * 100, 1)
                : 0;
            $house->total_monthly_rent = (float) ($house->total_monthly_rent ?? 0);
            $balance = $this->balanceService->calculateHouseBalance($house->id);
            $house->total_expected = $balance['total_expected'];
            $house->total_collected = $balance['total_paid'];
            $house->total_outstanding = $balance['total_balance'];
            $house->total_overdue = $balance['total_overdue'];
        });

        return response()->json([
            'success' => true,
            'message' => 'Houses retrieved successfully.',
            'data' => $houses,
        ]);
    }

    public function show(House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $house->load(['rooms' => function ($q) {
            $q->withCount('rentals');
        }]);

        $balance = $this->balanceService->calculateHouseBalance($house->id);

        $totalRooms = $house->rooms->count();
        $occupied = $house->rooms->where('status', 'occupied')->count();
        $available = $house->rooms->where('status', 'available')->count();
        $maintenance = $house->rooms->where('status', 'maintenance')->count();

        $house->rooms_count = $totalRooms;
        $house->occupied_rooms = $occupied;
        $house->available_rooms = $available;
        $house->maintenance_rooms = $maintenance;
        $house->occupancy_rate = $totalRooms > 0 ? round(($occupied / $totalRooms) * 100, 1) : 0;
        $house->total_monthly_rent = (float) $house->rooms->sum('rent_amount');
        $house->total_expected = $balance['total_expected'];
        $house->total_collected = $balance['total_paid'];
        $house->total_outstanding = $balance['total_balance'];
        $house->total_overdue = $balance['total_overdue'];

        return response()->json([
            'success' => true,
            'message' => 'House retrieved successfully.',
            'data' => $house,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'required|string',
            'description' => 'nullable|string',
            'contact_info' => 'nullable|string',
        ]);

        $house = $request->user()->houses()->create([
            ...$validated,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'House created successfully.',
            'data' => $house,
        ], 201);
    }

    public function update(Request $request, House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'address' => 'sometimes|string',
            'description' => 'nullable|string',
            'contact_info' => 'nullable|string',
        ]);

        $house->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'House updated successfully.',
            'data' => $house->fresh(),
        ]);
    }

    public function destroy(House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $activeRentals = $house->rooms()
            ->whereHas('rentals', function ($q) {
                $q->live();
            })->count();

        if ($activeRentals > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot deactivate house with active rentals. Terminate all tenancies first.',
            ], 422);
        }

        $house->update(['status' => 'inactive']);

        return response()->json([
            'success' => true,
            'message' => 'House deactivated successfully.',
        ]);
    }

    public function rooms(House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $rooms = $house->rooms()
            ->with(['currentRental' => function ($q) {
                $q->with('tenant');
            }])
            ->orderBy('name')
            ->get();

        $rooms->each(function ($room) {
            $room->outstanding = $room->currentRental
                ? $this->balanceService->calculateRoomBalance($room->id)['total_balance']
                : 0;
        });

        return response()->json([
            'success' => true,
            'message' => 'Rooms retrieved successfully.',
            'data' => $rooms,
        ]);
    }

    public function summary(House $house): JsonResponse
    {
        $this->ensureOwned($house);

        $balance = $this->balanceService->calculateHouseBalance($house->id);

        $data = [
            'house' => $house->only(['id', 'name', 'address', 'status']),
            'total_rooms' => $house->totalRooms(),
            'occupied_rooms' => $house->occupiedRooms(),
            'available_rooms' => $house->availableRooms(),
            'occupancy_rate' => $house->totalRooms() > 0
                ? round(($house->occupiedRooms() / $house->totalRooms()) * 100, 2)
                : 0,
            'total_expected' => $balance['total_expected'],
            'total_collected' => $balance['total_paid'],
            'total_outstanding' => $balance['total_balance'],
            'total_overdue' => $balance['total_overdue'],
        ];

        return response()->json([
            'success' => true,
            'message' => 'House summary retrieved successfully.',
            'data' => $data,
        ]);
    }
}
