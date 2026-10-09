<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<House>
 */
class HouseFactory extends Factory
{
    public function definition(): array
    {
        $owner = User::factory()->state(['role' => 'owner']);

        return [
            'user_id' => $owner,
            'name' => 'House '.fake()->unique()->randomNumber(3),
            'address' => fake()->streetAddress(),
            'description' => fake()->sentence(),
            'contact_info' => '+255'.fake()->numerify('7########'),
            'status' => 'active',
        ];
    }

    public function forOwner(User $owner): static
    {
        return $this->state(fn () => ['user_id' => $owner->id]);
    }
}
