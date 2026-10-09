<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        protected ReportService $reportService,
    ) {}

    protected function withOwner(): ReportService
    {
        return $this->reportService->ownerId(auth()->id());
    }

    public function rentCollection(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $data = $this->withOwner()->rentCollectionReport(
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Rent collection report retrieved successfully.',
            'data' => $data['rows'],
            'summary' => [
                'total_expected' => $data['total_expected'],
                'total_collected' => $data['total_collected'],
                'total_outstanding' => $data['total_outstanding'],
            ],
        ]);
    }

    public function tenantSummary(Request $request): JsonResponse
    {
        $latest = $this->withOwner()->tenantReport($request->integer('tenant_id'));

        return response()->json([
            'success' => true,
            'message' => 'Tenant summary report retrieved successfully.',
            'data' => $latest,
        ]);
    }

    public function roomPerformance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $data = $this->withOwner()->roomReport(
            $request->integer('room_id') ?: null,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Room performance report retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function houseIncome(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $data = $this->withOwner()->houseReport(
            $request->integer('house_id') ?: null,
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'House income report retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function arrears(Request $request): JsonResponse
    {
        $data = $this->withOwner()->arrearsReport();

        return response()->json([
            'success' => true,
            'message' => 'Arrears report retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function expiry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'within_days' => 'nullable|integer|min:1|max:365',
        ]);

        $data = $this->withOwner()->expiryReport(
            (int) ($validated['within_days'] ?? 60),
        );

        return response()->json([
            'success' => true,
            'message' => 'Expiry report retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function monthly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date|after_or_equal:from_date',
        ]);

        $data = $this->withOwner()->monthlyReport(
            $validated['from_date'] ?? null,
            $validated['to_date'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Monthly report retrieved successfully.',
            'data' => $data['rows'],
            'summary' => [
                'total_income' => $data['total_income'],
                'total_expected' => $data['total_expected'],
                'total_outstanding' => $data['total_outstanding'],
            ],
        ]);
    }
}
