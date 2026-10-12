<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\RentalRequest;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RentalRequest>
 */
class RentalRequestFactory extends Factory
{
    protected $model = RentalRequest::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'room_id' => Room::factory(),
            'house_id' => House::factory(),
            'owner_id' => User::factory(),
            'message' => fake()->sentence(),
            'desired_start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'period_months' => 12,
            'status' => 'pending',
        ];
    }
}
