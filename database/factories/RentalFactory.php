<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rental>
 */
class RentalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => Room::factory(),
            'house_id' => House::factory(),
            'rent_amount' => 160000.00,
            'rent_amount_note' => 'Msingi wa mwezi',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'period_months' => 12,
            'due_day' => 5,
            'deposit_amount' => 160000.00,
            'deposit_status' => 'held',
            'status' => 'active',
        ];
    }
}
