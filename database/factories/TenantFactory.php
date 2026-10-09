<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->state(['role' => 'tenant']),
            'full_name' => fake()->name(),
            'phone' => '07'.fake()->numerify('########'),
            'id_number' => fake()->numerify('##########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'emergency_contact_name' => fake()->name(),
            'emergency_contact_phone' => '07'.fake()->numerify('########'),
            'status' => 'active',
        ];
    }

    public function forUser(User $user): static
    {
        return $this->state(fn () => [
            'user_id' => $user->id,
            'phone' => $user->phone ?? '07'.fake()->numerify('########'),
            'full_name' => $user->name ?? fake()->name(),
        ]);
    }
}
