<?php

namespace Database\Factories;

use App\Models\OwnerSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerSetting>
 */
class OwnerSettingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->owner(),
            'company_name' => fake()->company(),
            'contact_phone' => '+2557'.fake()->unique()->numerify('########'),
            'contact_email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'currency' => 'TZS',
            'payment_accounts' => [],
        ];
    }
}
