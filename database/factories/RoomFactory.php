<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
{
    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'name' => 'R'.fake()->unique()->randomNumber(3),
            'description' => fake()->sentence(),
            'rent_amount' => 160000.00,
            'status' => 'available',
        ];
    }

    public function occupied(): static
    {
        return $this->state(fn () => ['status' => 'occupied']);
    }

    public function available(): static
    {
        return $this->state(fn () => ['status' => 'available']);
    }

    public function forHouse(House $house): static
    {
        return $this->state(fn () => ['house_id' => $house->id]);
    }
}
