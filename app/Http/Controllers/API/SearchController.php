<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\House;
use App\Models\Payment;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    /**
     * Provide a case-insensitive LIKE matcher that works on both MySQL and
     * PostgreSQL. MySQL uses a case-insensitive collation by default, but
     * PostgreSQL's LIKE is case-sensitive, so we fall back to ILIKE there.
     */
    protected function likeValue(string $value): string
    {
        return "%{$value}%";
    }

    protected function likeOperator(): string
    {
        return DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    public function index(Request $request): JsonResponse
    {
        $query = $request->input('q', '');

        if (strlen($query) < 2) {
            return response()->json([
                'success' => false,
                'message' => 'Search query must be at least 2 characters.',
            ], 422);
        }

        $user = $request->user();
        $like = $this->likeOperator();
        $term = $this->likeValue($query);

        $tenants = Tenant::query()
            ->ownedBy($user)
            ->where(function ($wq) use ($like, $term) {
                $wq->where('full_name', $like, $term)
                    ->orWhere('phone', $like, $term)
                    ->orWhere('id_number', $like, $term);
            })
            ->limit(5)
            ->get()
            ->map(fn ($t) => ['type' => 'tenant', 'id' => $t->id, 'name' => $t->full_name, 'subtitle' => $t->phone]);

        $rooms = Room::query()
            ->ownedBy($user)
            ->with('house')
            ->where(fn ($wq) => $wq->where('name', $like, $term))
            ->limit(5)
            ->get()
            ->map(fn ($r) => ['type' => 'room', 'id' => $r->id, 'name' => $r->name, 'subtitle' => $r->house?->name]);

        $houses = House::query()
            ->ownedBy($user)
            ->where(function ($wq) use ($like, $term) {
                $wq->where('name', $like, $term)
                    ->orWhere('address', $like, $term);
            })
            ->limit(5)
            ->get()
            ->map(fn ($h) => ['type' => 'house', 'id' => $h->id, 'name' => $h->name, 'subtitle' => $h->address]);

        $payments = Payment::query()
            ->ownedBy($user)
            ->with('tenant')
            ->where(fn ($wq) => $wq->where('reference_number', $like, $term))
            ->limit(5)
            ->get()
            ->map(fn ($p) => ['type' => 'payment', 'id' => $p->id, 'name' => $p->reference_number, 'subtitle' => $p->tenant?->full_name]);

        return response()->json([
            'success' => true,
            'message' => 'Search results retrieved successfully.',
            'data' => [
                'tenants' => $tenants,
                'rooms' => $rooms,
                'houses' => $houses,
                'payments' => $payments,
            ],
        ]);
    }

    public function tenants(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $like = $this->likeOperator();
        $term = $this->likeValue($query);

        $tenants = Tenant::query()
            ->ownedBy($request->user())
            ->when($query, function ($q) use ($like, $term) {
                $q->where(function ($wq) use ($like, $term) {
                    $wq->where('full_name', $like, $term)
                        ->orWhere('phone', $like, $term)
                        ->orWhere('id_number', $like, $term);
                });
            })
            ->with(['user', 'rentals' => function ($q) {
                $q->live()->with('room');
            }])
            ->orderByDesc('updated_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Tenant search results retrieved successfully.',
            'data' => $tenants,
        ]);
    }

    public function rooms(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $like = $this->likeOperator();
        $term = $this->likeValue($query);

        $rooms = Room::query()
            ->ownedBy($request->user())
            ->when($query, function ($q) use ($like, $term) {
                $q->where('name', $like, $term)
                    ->orWhereHas('house', function ($hq) use ($like, $term) {
                        $hq->where('name', $like, $term);
                    });
            })
            ->with(['house', 'currentRental' => function ($q) {
                $q->with('tenant');
            }])
            ->orderByDesc('updated_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Room search results retrieved successfully.',
            'data' => $rooms,
        ]);
    }

    public function houses(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $like = $this->likeOperator();
        $term = $this->likeValue($query);

        $houses = House::query()
            ->ownedBy($request->user())
            ->when($query, function ($q) use ($like, $term) {
                $q->where('name', $like, $term)
                    ->orWhere('address', $like, $term);
            })
            ->withCount(['rooms', 'rooms as occupied_rooms' => function ($q) {
                $q->where('status', 'occupied');
            }])
            ->orderByDesc('updated_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'House search results retrieved successfully.',
            'data' => $houses,
        ]);
    }

    public function payments(Request $request): JsonResponse
    {
        $query = $request->input('q', '');
        $like = $this->likeOperator();
        $term = $this->likeValue($query);

        $payments = Payment::query()
            ->ownedBy($request->user())
            ->when($query, function ($q) use ($like, $term) {
                $q->where('reference_number', $like, $term)
                    ->orWhereHas('tenant', function ($tq) use ($like, $term) {
                        $tq->where('full_name', $like, $term);
                    });
            })
            ->with(['tenant', 'rentCharge.room', 'receipt'])
            ->orderByDesc('payment_date')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'success' => true,
            'message' => 'Payment search results retrieved successfully.',
            'data' => $payments,
        ]);
    }
}
