<?php

namespace Database\Factories;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feedback>
 */
class FeedbackFactory extends Factory
{
    protected $model = Feedback::class;

    public function definition(): array
    {
        $role = fake()->randomElement(['owner', 'tenant']);

        return [
            'user_id' => User::factory()->state(['role' => $role]),
            'role' => $role,
            'category' => fake()->randomElement(Feedback::CATEGORIES),
            'rating' => fake()->optional()->numberBetween(1, 5),
            'message' => fake()->paragraph(),
            'status' => 'new',
        ];
    }
}
