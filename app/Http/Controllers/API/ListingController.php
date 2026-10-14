<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\House;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public (unauthenticated) marketplace browsing. Only active houses with at
 * least one available room are listed, and only safe display fields are
 * exposed — no tenant data, balances, or internal statuses ever leave here.
 */
class ListingController extends Controller
{
    public function houses(Request $request): JsonResponse
    {
        $houses = House::query()
            ->where('status', 'active')
            // A suspended or unverified owner must not advertise, so their
            // houses are filtered out of the public marketplace entirely.
            ->whereHas('owner', fn ($q) => $q
                ->where('is_active', true)
                ->where('verification_status', 'approved'))
            ->withCount(['rooms as available_rooms' => fn ($q) => $q->where('status', 'available')])
            ->withMin(['rooms as min_rent' => fn ($q) => $q->where('status', 'available')], 'rent_amount')
            ->with(['owner:id,name,phone,role,verification_status', 'media'])
            ->when($request->search, fn ($q, $search) => $q->where(function ($wq) use ($search) {
                $wq->where('name', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            }))
            ->when($request->filled('min_rent'), fn ($q) => $q->whereHas('rooms', function ($rq) use ($request) {
                $rq->where('status', 'available')
                    ->where('rent_amount', '>=', (float) $request->min_rent);
            }))
            ->when($request->filled('max_rent'), fn ($q) => $q->whereHas('rooms', function ($rq) use ($request) {
                $rq->where('status', 'available')
                    ->where('rent_amount', '<=', (float) $request->max_rent);
            }))
            ->whereHas('rooms', fn ($q) => $q->where('status', 'available'))
            ->when($request->filled('owner_id'), fn ($q) => $q->where('user_id', (int) $request->integer('owner_id')))
            ->orderBy('min_rent')
            ->get();

        $data = $houses->map(fn (House $house) => [
            'id' => $house->id,
            'name' => $house->name,
            'address' => $house->address,
            'description' => $house->description,
            'contact_info' => $house->contact_info,
            'available_rooms' => (int) $house->available_rooms,
            'min_rent' => (float) ($house->min_rent ?? 0),
            'images' => $house->media->where('type', 'image')->map(fn (Media $m) => $m->publicUrl())->values(),
            'video' => optional($house->media->firstWhere('type', 'video'))->publicUrl(),
            'owner' => [
                'id' => $house->owner?->id,
                'name' => $house->owner?->name,
                'phone' => $house->owner?->phone,
                'verified' => (bool) $house->owner?->isVerifiedOwner(),
            ],
        ]);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function house(Request $request, House $house): JsonResponse
    {
        if ($house->status !== 'active') {
            abort(404, 'House not found.');
        }

        $house->loadMissing(['owner:id,name,phone,is_active,role,verification_status', 'media']);

        // Suspended or unverified owners are hidden from the marketplace, so
        // their detail page disappears alongside their listings.
        if (! $house->owner?->is_active || ! $house->owner?->isVerifiedOwner()) {
            abort(404, 'House not found.');
        }

        $rooms = $house->rooms()
            ->where('status', 'available')
            ->with('media')
            ->orderBy('rent_amount')
            ->get(['id', 'name', 'description', 'rent_amount', 'status']);

        return response()->json([
            'success' => true,
            'data' => [
                'house' => [
                    'id' => $house->id,
                    'name' => $house->name,
                    'address' => $house->address,
                    'description' => $house->description,
                    'contact_info' => $house->contact_info,
                    'images' => $house->media->where('type', 'image')->map(fn (Media $m) => $m->publicUrl())->values(),
                    'video' => optional($house->media->firstWhere('type', 'video'))->publicUrl(),
                    'owner' => [
                        'id' => $house->owner?->id,
                        'name' => $house->owner?->name,
                        'phone' => $house->owner?->phone,
                        'verified' => (bool) $house->owner?->isVerifiedOwner(),
                    ],
                ],
                'rooms' => $rooms->map(fn ($room) => [
                    'id' => $room->id,
                    'name' => $room->name,
                    'description' => $room->description,
                    'rent_amount' => (float) $room->rent_amount,
                    'images' => $room->media->where('type', 'image')->map(fn (Media $m) => $m->publicUrl())->values(),
                    'video' => optional($room->media->firstWhere('type', 'video'))->publicUrl(),
                ]),
            ],
        ]);
    }
}
