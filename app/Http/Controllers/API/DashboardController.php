<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
    ) {}

    public function owner(Request $request): JsonResponse
    {
        $data = $this->dashboardService->getOwnerDashboard($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Owner dashboard data retrieved successfully.',
            'data' => $data,
        ]);
    }

    public function tenant(Request $request): JsonResponse
    {
        $tenant = $request->user()->tenant;

        if (! $tenant) {
            return response()->json([
                'success' => false,
                'message' => 'No tenant profile found.',
            ], 404);
        }

        $data = $this->dashboardService->getTenantDashboard($tenant);

        return response()->json([
            'success' => true,
            'message' => 'Tenant dashboard data retrieved successfully.',
            'data' => $data,
        ]);
    }
}
