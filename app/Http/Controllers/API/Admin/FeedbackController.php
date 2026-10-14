<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function __construct(
        protected AuditService $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(Feedback::STATUSES)],
            'role' => ['nullable', Rule::in(['owner', 'tenant', 'admin'])],
            'category' => ['nullable', Rule::in(Feedback::CATEGORIES)],
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $feedback = Feedback::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($validated['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->with(['user', 'handler'])
            ->orderByRaw("CASE status WHEN 'new' THEN 0 WHEN 'reviewed' THEN 1 WHEN 'archived' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Feedback retrieved successfully.',
            'data' => $feedback,
        ]);
    }

    public function show(Feedback $feedback): JsonResponse
    {
        $feedback->load(['user', 'handler']);

        return response()->json([
            'success' => true,
            'message' => 'Feedback retrieved successfully.',
            'data' => $feedback,
        ]);
    }

    public function update(Request $request, Feedback $feedback): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in([Feedback::STATUS_REVIEWED, Feedback::STATUS_ARCHIVED])],
            'admin_note' => 'nullable|string|max:5000',
        ]);

        $old = ['status' => $feedback->status, 'admin_note' => $feedback->admin_note];

        DB::transaction(function () use ($validated, $request, $feedback, $old) {
            $updates = [
                'status' => $validated['status'],
                'admin_note' => $validated['admin_note'] ?? $feedback->admin_note,
                'handled_by' => $request->user()->id,
            ];

            if ($validated['status'] === Feedback::STATUS_REVIEWED) {
                $updates['reviewed_at'] = now();
            }

            $feedback->update($updates);

            $this->audit->log($request->user(), 'feedback_reviewed', $feedback, $old, $updates);
        });

        return response()->json([
            'success' => true,
            'message' => 'Feedback updated successfully.',
            'data' => $feedback->fresh()->load(['user', 'handler']),
        ]);
    }
}
