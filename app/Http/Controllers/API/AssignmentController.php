<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\RentChargeService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AssignmentController extends Controller
{
    public function __construct(
        protected RentChargeService $rentChargeService,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => 'required|exists:rooms,id',
            'tenant_id' => 'required|exists:tenants,id',
            'rent_amount' => 'required|numeric|min:0',
            'start_date' => 'required|date',
            'period_months' => 'required|integer|min:1',
            'deposit_amount' => 'nullable|numeric|min:0',
            'deposit_notes' => 'nullable|string',
            'rent_amount_note' => 'nullable|string',
        ]);

        $room = Room::ownedBy($request->user())->findOrFail($validated['room_id']);
        $tenant = Tenant::ownedBy($request->user())->findOrFail($validated['tenant_id']);

        if ($room->status !== 'available') {
            return response()->json([
                'success' => false,
                'message' => 'Room is not available for assignment.',
            ], 422);
        }

        if ($tenant->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Tenant is not active.',
            ], 422);
        }

        $startDate = Carbon::parse($validated['start_date']);
        $dueDay = (int) $startDate->format('d');
        $endDate = $startDate->copy()->addMonths($validated['period_months'])->subDay();

        return DB::transaction(function () use ($validated, $room, $tenant, $startDate, $endDate, $dueDay) {
            $rental = Rental::create([
                'tenant_id' => $tenant->id,
                'room_id' => $room->id,
                'house_id' => $room->house_id,
                'rent_amount' => $validated['rent_amount'],
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

            $this->rentChargeService->generateChargesForRental($rental);

            $room->update(['status' => 'occupied']);

            return response()->json([
                'success' => true,
                'message' => 'Tenant assigned to room successfully.',
                'data' => $rental->load(['tenant', 'room.house', 'rentCharges']),
            ], 201);
        });
    }

    public function endTenancy(Request $request, int $id): JsonResponse
    {
        $rental = Rental::ownedBy($request->user())
            ->with(['room', 'tenant', 'rentCharges'])
            ->findOrFail($id);

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

            $totalExpected = (float) $rental->rentCharges->sum('expected_amount');
            $totalPaid = (float) $rental->rentCharges->sum('paid_amount');
            $balance = $totalExpected - $totalPaid;

            return response()->json([
                'success' => true,
                'message' => 'Tenancy ended successfully.',
                'data' => [
                    'rental' => $rental->fresh()->load(['tenant', 'room', 'rentCharges']),
                    'final_balance' => $balance,
                ],
            ]);
        });
    }

    public function history(Request $request): JsonResponse
    {
        $query = Rental::ownedBy($request->user())
            ->with(['tenant', 'room.house'])
            ->orderByDesc('created_at');

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->room_id) {
            $query->where('room_id', $request->room_id);
        }

        if ($request->tenant_id) {
            $query->where('tenant_id', $request->tenant_id);
        }

        $assignments = $query->get();

        return response()->json([
            'success' => true,
            'message' => 'Assignment history retrieved successfully.',
            'data' => $assignments,
        ]);
    }
}
