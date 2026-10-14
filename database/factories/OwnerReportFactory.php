<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\OwnerReport;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerReport>
 */
class OwnerReportFactory extends Factory
{
    protected $model = OwnerReport::class;

    public function definition(): array
    {
        $owner = User::factory()->state(['role' => 'owner']);
        $reporter = User::factory()->state(['role' => 'tenant']);

        return [
            'reporter_id' => $reporter,
            'owner_id' => $owner,
            'house_id' => House::factory(),
            'rental_id' => Rental::factory(),
            'category' => fake()->randomElement(OwnerReport::CATEGORIES),
            'subject' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'status' => 'submitted',
        ];
    }

    public function noContext(): static
    {
        return $this->state(fn () => [
            'house_id' => null,
            'rental_id' => null,
        ]);
    }

    public function inReview(): static
    {
        return $this->state(fn () => [
            'status' => 'in_review',
            'handled_by' => User::factory()->admin(),
        ]);
    }

    public function resolved(): static
    {
        return $this->state(fn () => [
            'status' => 'resolved',
            'handled_by' => User::factory()->admin(),
            'admin_note' => fake()->sentence(),
            'resolved_at' => now(),
        ]);
    }

    public function dismissed(): static
    {
        return $this->state(fn () => [
            'status' => 'dismissed',
            'handled_by' => User::factory()->admin(),
            'admin_note' => fake()->sentence(),
        ]);
    }
}
