<?php

namespace App\Http\Controllers\API\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\VerificationDocument;
use App\Services\AuditService;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * System-administrator review of owner accounts. The queue lists owners who are
 * not yet approved; approving flips the account into the verified state that
 * unlocks the whole /owner area, rejecting records a reason the owner can act on
 * and re-submit against.
 */
class VerificationController extends Controller
{
    public function __construct(
        protected AuditService $audit,
        protected NotificationService $notifications,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in(['unsubmitted', 'pending', 'approved', 'rejected'])],
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $owners = User::query()
            ->where('role', 'owner')
            ->when(
                $validated['status'] ?? null,
                fn ($q, $status) => $q->where('verification_status', $status),
                fn ($q) => $q->whereIn('verification_status', ['unsubmitted', 'pending']),
            )
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($wq) use ($search) {
                    $wq->where('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with(['verificationDocuments', 'settings'])
            ->withCount('houses')
            ->orderByRaw("CASE verification_status WHEN 'pending' THEN 0 WHEN 'unsubmitted' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END")
            ->orderByDesc('created_at')
            ->paginate((int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'message' => 'Owner verifications retrieved successfully.',
            'data' => $owners,
        ]);
    }

    public function approve(Request $request, User $user): JsonResponse
    {
        if (! $user->isOwner()) {
            return $this->error('Only owner accounts can be verified.', 422);
        }

        $present = $user->verificationDocuments()->pluck('type')->all();

        foreach ([VerificationDocument::TYPE_NATIONAL_ID, VerificationDocument::TYPE_OWNERSHIP_PROOF] as $type) {
            if (! in_array($type, $present, true)) {
                return $this->error('This owner has not uploaded all required documents yet.', 422);
            }
        }

        $old = ['verification_status' => $user->verification_status];

        $user->update([
            'verification_status' => 'approved',
            'verified_at' => now(),
            'verified_by' => $request->user()->id,
            'verification_note' => null,
        ]);

        $this->audit->log($request->user(), 'owner_verified', $user, $old, ['verification_status' => 'approved']);
        $this->notifications->notifyOwnerVerified($user);

        return response()->json([
            'success' => true,
            'message' => 'Owner verified successfully.',
            'data' => $user->fresh(),
        ]);
    }

    public function reject(Request $request, User $user): JsonResponse
    {
        if (! $user->isOwner()) {
            return $this->error('Only owner accounts can be reviewed.', 422);
        }

        $validated = $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        $old = ['verification_status' => $user->verification_status];

        $user->update([
            'verification_status' => 'rejected',
            'verification_note' => $validated['reason'],
        ]);

        $this->audit->log($request->user(), 'owner_rejected', $user, $old, ['verification_status' => 'rejected']);
        $this->notifications->notifyOwnerRejected($user, $validated['reason']);

        return response()->json([
            'success' => true,
            'message' => 'Owner verification rejected.',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Stream a private verification document to an administrator. The file is
     * never on the public disk, so this admin-guarded route is the only way in.
     */
    public function streamDocument(VerificationDocument $document)
    {
        $disk = Storage::disk('local');

        abort_unless($disk->exists($document->path), 404);

        return $disk->download($document->path, $document->original_name);
    }

    protected function error(string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
