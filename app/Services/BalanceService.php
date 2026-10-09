<?php

namespace App\Services;

use App\Models\RentCharge;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BalanceService
{
    public function calculateTenantBalance(int $tenantId): array
    {
        $result = RentCharge::where('tenant_id', $tenantId)
            ->selectRaw('SUM(expected_amount) as total_expected')
            ->selectRaw('SUM(paid_amount) as total_paid')
            ->selectRaw('SUM(expected_amount) - SUM(paid_amount) as total_balance')
            ->first();

        $overdue = RentCharge::where('tenant_id', $tenantId)
            ->where('status', 'overdue')
            ->sum(DB::raw('expected_amount - paid_amount'));

        return [
            'total_expected' => (float) ($result->total_expected ?? 0),
            'total_paid' => (float) ($result->total_paid ?? 0),
            'total_balance' => (float) ($result->total_balance ?? 0),
            'total_overdue' => (float) $overdue,
        ];
    }

    public function calculateRoomBalance(int $roomId): array
    {
        $result = RentCharge::where('room_id', $roomId)
            ->selectRaw('SUM(expected_amount) as total_expected')
            ->selectRaw('SUM(paid_amount) as total_paid')
            ->selectRaw('SUM(expected_amount) - SUM(paid_amount) as total_balance')
            ->first();

        $overdue = RentCharge::where('room_id', $roomId)
            ->where('status', 'overdue')
            ->sum(DB::raw('expected_amount - paid_amount'));

        return [
            'total_expected' => (float) ($result->total_expected ?? 0),
            'total_paid' => (float) ($result->total_paid ?? 0),
            'total_balance' => (float) ($result->total_balance ?? 0),
            'total_overdue' => (float) $overdue,
        ];
    }

    public function calculateHouseBalance(int $houseId): array
    {
        $result = RentCharge::whereHas('room', fn ($q) => $q->where('house_id', $houseId))
            ->selectRaw('SUM(expected_amount) as total_expected')
            ->selectRaw('SUM(paid_amount) as total_paid')
            ->selectRaw('SUM(expected_amount) - SUM(paid_amount) as total_balance')
            ->first();

        $overdue = RentCharge::whereHas('room', fn ($q) => $q->where('house_id', $houseId))
            ->where('status', 'overdue')
            ->sum(DB::raw('expected_amount - paid_amount'));

        return [
            'total_expected' => (float) ($result->total_expected ?? 0),
            'total_paid' => (float) ($result->total_paid ?? 0),
            'total_balance' => (float) ($result->total_balance ?? 0),
            'total_overdue' => (float) $overdue,
        ];
    }

    public function calculateTotalOutstanding(): array
    {
        $result = RentCharge::selectRaw('SUM(expected_amount) as total_expected')
            ->selectRaw('SUM(paid_amount) as total_paid')
            ->selectRaw('SUM(expected_amount) - SUM(paid_amount) as total_balance')
            ->first();

        $overdue = RentCharge::where('status', 'overdue')
            ->sum(DB::raw('expected_amount - paid_amount'));

        return [
            'total_expected' => (float) ($result->total_expected ?? 0),
            'total_paid' => (float) ($result->total_paid ?? 0),
            'total_balance' => (float) ($result->total_balance ?? 0),
            'total_overdue' => (float) $overdue,
        ];
    }

    public function getOutstandingByDimension(string $dimension, int $id): Collection
    {
        $query = RentCharge::whereIn('status', ['unpaid', 'partial', 'overdue'])
            ->with(['tenant', 'room']);

        match ($dimension) {
            'tenant' => $query->where('tenant_id', $id),
            'room' => $query->where('room_id', $id),
            'house' => $query->whereHas('room', fn ($q) => $q->where('house_id', $id)),
            default => null,
        };

        return $query->get();
    }
}
