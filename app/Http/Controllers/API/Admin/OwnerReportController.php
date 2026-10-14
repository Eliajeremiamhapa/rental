<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\OwnerReport;
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

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(OwnerReport::STATUSES)],
            'category' => ['nullable', Rule::in(OwnerReport::CATEGORIES)],
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $reports = OwnerReport::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($wq) use ($search) {
                    $wq->where('subject', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhereHas('reporter', fn ($rq) => $rq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('owner', fn ($oq) => $oq->where('name', 'like', "%{$search}%"));
                });
            })
            ->with(['reporter', 'owner', 'house', 'rental', 'handler', 'media'])
            ->orderByRaw("CASE status WHEN 'submitted' THEN 0 WHEN 'in_review' THEN 1 WHEN 'resolved' THEN 2 WHEN 'dismissed' THEN 3 ELSE 4 END")
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Owner reports retrieved successfully.',
            'data' => $reports,
        ]);
    }

    public function show(OwnerReport $ownerReport): JsonResponse
    {
        $ownerReport->load(['reporter', 'owner', 'house', 'rental', 'handler', 'media']);

        return response()->json([
            'success' => true,
            'message' => 'Owner report retrieved successfully.',
            'data' => $ownerReport,
        ]);
    }

    public function update(Request $request, OwnerReport $ownerReport): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([OwnerReport::STATUS_IN_REVIEW, OwnerReport::STATUS_RESOLVED, OwnerReport::STATUS_DISMISSED])],
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $old = ['status' => $ownerReport->status, 'admin_note' => $ownerReport->admin_note];

        DB::transaction(function () use ($validated, $request, $ownerReport, $old) {
            $updates = [
                'status' => $validated['status'],
                'admin_note' => $validated['admin_note'] ?? $ownerReport->admin_note,
                'handled_by' => $request->user()->id,
            ];

            if (in_array($validated['status'], [OwnerReport::STATUS_RESOLVED, OwnerReport::STATUS_DISMISSED], true)) {
                $updates['resolved_at'] = now();
            }

            $ownerReport->update($updates);

            $this->audit->log($request->user(), 'owner_report_reviewed', $ownerReport, $old, $updates);
            $this->notifications->notifyOwnerReportUpdated($ownerReport);
        });

        return response()->json([
            'success' => true,
            'message' => 'Owner report updated successfully.',
            'data' => $ownerReport->fresh()->load(['reporter', 'owner', 'house', 'rental', 'handler', 'media']),
        ]);
    }
}
