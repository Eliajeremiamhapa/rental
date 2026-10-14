<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\OwnerReport;
use App\Models\Rental;
use App\Models\RentalRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class OwnerReportController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    public function eligibleOwners(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        // Collect distinct owner IDs the tenant has standing with.
        $ownerIds = collect();

        if ($tenant && $tenant->owner_id) {
            $ownerIds->push($tenant->owner_id);
        }

        if ($tenant) {
            $houseOwnerIds = Rental::query()
                ->where('tenant_id', $tenant->id)
                ->with('house')
                ->get()
                ->pluck('house.user_id')
                ->filter();
            $ownerIds = $ownerIds->concat($houseOwnerIds);

            $rentalRequestOwnerIds = RentalRequest::query()
                ->where('tenant_id', $tenant->id)
                ->pluck('owner_id');
            $ownerIds = $ownerIds->concat($rentalRequestOwnerIds);
        }

        $ownerIds = $ownerIds->filter()->unique()->values();

        if ($ownerIds->isEmpty()) {
            return response()->json([
                'success' => true,
                'message' => 'No eligible owners found.',
                'data' => [],
            ]);
        }

        $owners = User::query()
            ->where('role', 'owner')
            ->whereIn('id', $ownerIds->all())
            ->with('settings')
            ->orderBy('name')
            ->get()
            ->map(function (User $owner) use ($tenant) {
                $context = null;
                if ($tenant && $tenant->owner_id === $owner->id) {
                    $context = 'Current landlord';
                }

                return [
                    'id' => $owner->id,
                    'name' => $owner->name,
                    'company_name' => $owner->settings?->company_name,
                    'context' => $context,
                ];
            });

        return response()->json([
            'success' => true,
            'message' => 'Eligible owners retrieved successfully.',
            'data' => $owners,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = $user->tenant;

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'A tenant profile is required to file an owner report.',
            ], 422);
        }

        // Build eligible owner IDs as above (lightweight).
        $ownerIds = collect();
        if ($tenant->owner_id) {
            $ownerIds->push($tenant->owner_id);
        }
        $houseOwnerIds = Rental::query()->where('tenant_id', $tenant->id)->with('house')->get()->pluck('house.user_id')->filter();
        $ownerIds = $ownerIds->concat($houseOwnerIds);
        $rentalRequestOwnerIds = RentalRequest::query()->where('tenant_id', $tenant->id)->pluck('owner_id');
        $ownerIds = $ownerIds->concat($rentalRequestOwnerIds);
        $ownerIds = $ownerIds->filter()->unique()->values()->all();

        $validated = $request->validate([
            'owner_id' => ['required', 'integer', Rule::in($ownerIds)],
            'house_id' => 'nullable|exists:houses,id',
            'rental_id' => 'nullable|exists:rentals,id',
            'category' => ['required', Rule::in(OwnerReport::CATEGORIES)],
            'subject' => 'required|string|max:150',
            'description' => 'required|string|max:5000',
        ]);

        // Prevent identical open duplicate (submitted or in_review).
        $duplicate = OwnerReport::query()
            ->where('reporter_id', $user->id)
            ->where('owner_id', $validated['owner_id'])
            ->whereIn('status', [OwnerReport::STATUS_SUBMITTED, OwnerReport::STATUS_IN_REVIEW])
            ->where('category', $validated['category'])
            ->where('subject', $validated['subject'])
            ->exists();

        if ($duplicate) {
            return response()->json([
                'success' => false,
                'message' => 'You already have an open report of this type against this owner with the same subject.',
            ], 422);
        }

        $report = DB::transaction(function () use ($validated, $user) {
            $report = OwnerReport::create([
                'reporter_id' => $user->id,
                'owner_id' => $validated['owner_id'],
                'house_id' => $validated['house_id'] ?? null,
                'rental_id' => $validated['rental_id'] ?? null,
                'category' => $validated['category'],
                'subject' => $validated['subject'],
                'description' => $validated['description'],
                'status' => OwnerReport::STATUS_SUBMITTED,
            ]);

            $this->notifications->notifyOwnerReportReceived($report);
            $this->audit->log($user, 'owner_report_submitted', $report);

            return $report;
        });

        return response()->json([
            'success' => true,
            'message' => 'Owner report submitted successfully.',
            'data' => $report->fresh()->load(['owner', 'house', 'rental', 'media']),
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $reports = OwnerReport::query()
            ->ownedBy($request->user())
            ->with(['owner', 'house', 'rental', 'media'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Your owner reports retrieved successfully.',
            'data' => $reports,
        ]);
    }

    public function addEvidence(Request $request, OwnerReport $ownerReport): JsonResponse
    {
        $this->ensureReporter($ownerReport);

        if ($request->hasFile('file')) {
            $request->validate([
                'file' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);

            $folder = "owner-reports/{$ownerReport->id}";
            $path = $request->file('file')->store($folder, 'public');

            $nextOrder = (int) $ownerReport->media()->max('sort_order') + 1;

            $media = $ownerReport->media()->create([
                'type' => 'image',
                'path' => $path,
                'url' => null,
                'sort_order' => $request->integer('sort_order', $nextOrder),
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'An image file is required.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Evidence uploaded successfully.',
            'data' => $media->fresh(),
        ], 201);
    }

    protected function ensureReporter(OwnerReport $report): void
    {
        abort_if(! $report, 404);

        $user = auth()->user();
        abort_unless($report->reporter_id === $user->id, 404);
    }
}
