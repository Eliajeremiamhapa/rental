<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\VerificationDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * The owner's own side of verification: view status, upload identity and
 * ownership documents, remove them, and submit for review. Reachable before the
 * owner is approved, so it deliberately does not sit behind owner.verified.
 */
class OwnerVerificationController extends Controller
{
    /** @var list<string> */
    private const REQUIRED_TYPES = [
        VerificationDocument::TYPE_NATIONAL_ID,
        VerificationDocument::TYPE_OWNERSHIP_PROOF,
    ];

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'verification_status' => $user->verification_status,
                'verified_at' => $user->verified_at,
                'verification_note' => $user->verification_note,
                'required_types' => self::REQUIRED_TYPES,
                'documents' => $user->verificationDocuments
                    ->map(fn (VerificationDocument $d) => $this->present($d))
                    ->values(),
            ],
        ]);
    }

    public function storeDocument(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(self::REQUIRED_TYPES)],
            'file' => 'required|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
        ]);

        $user = $request->user();

        // One document per type: re-uploading replaces the previous copy.
        $existing = $user->verificationDocuments()->where('type', $validated['type'])->first();
        if ($existing) {
            Storage::disk('local')->delete($existing->path);
            $existing->delete();
        }

        $file = $validated['file'];
        $path = $file->store('verification/'.$user->id, 'local');

        $document = $user->verificationDocuments()->create([
            'type' => $validated['type'],
            'original_name' => $file->getClientOriginalName(),
            'path' => $path,
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        // A fresh upload moves a rejected owner back into the review queue.
        if ($user->verification_status === 'rejected') {
            $user->update(['verification_status' => 'pending', 'verification_note' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Document uploaded successfully.',
            'data' => $this->present($document),
        ], 201);
    }

    public function destroyDocument(Request $request, VerificationDocument $document): JsonResponse
    {
        abort_unless($document->user_id === $request->user()->id, 404);

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json([
            'success' => true,
            'message' => 'Document removed.',
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        $present = $user->verificationDocuments()->pluck('type')->all();

        foreach (self::REQUIRED_TYPES as $type) {
            if (! in_array($type, $present, true)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Please upload all required documents before submitting.',
                ], 422);
            }
        }

        $user->update([
            'verification_status' => 'pending',
            'verification_note' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Your documents have been submitted for review. You will be notified once an administrator responds.',
            'data' => ['verification_status' => $user->fresh()->verification_status],
        ]);
    }

    private function present(VerificationDocument $document): array
    {
        return [
            'id' => $document->id,
            'type' => $document->type,
            'original_name' => $document->original_name,
            'mime' => $document->mime,
            'size' => $document->size,
            'uploaded_at' => $document->created_at,
        ];
    }
}
