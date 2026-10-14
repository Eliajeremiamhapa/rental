<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Feedback;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FeedbackController extends Controller
{
    public function __construct(
        protected NotificationService $notifications,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'category' => ['required', Rule::in(Feedback::CATEGORIES)],
            'rating' => 'nullable|integer|min:1|max:5',
            'message' => 'required|string|max:5000',
        ]);

        $feedback = Feedback::create([
            'user_id' => $user->id,
            'role' => $user->role,
            'category' => $validated['category'],
            'rating' => $validated['rating'] ?? null,
            'message' => $validated['message'],
            'status' => Feedback::STATUS_NEW,
        ]);

        $this->notifications->notifyFeedbackReceived($feedback);

        return response()->json([
            'success' => true,
            'message' => 'Feedback submitted successfully.',
            'data' => $feedback,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $feedback = Feedback::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Your feedback retrieved successfully.',
            'data' => $feedback,
        ]);
    }
}
