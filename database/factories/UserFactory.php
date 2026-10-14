<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'phone' => '+2557'.fake()->unique()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'tenant',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function owner(): static
    {
        // Approved by default so tests and seeds that just want "an owner who
        // can list" are not forced to walk the verification flow. Use
        // unverifiedOwner()/pendingOwner()/rejectedOwner() to exercise the gate.
        return $this->state(fn () => [
            'role' => 'owner',
            'verification_status' => 'approved',
            'verified_at' => now(),
        ]);
    }

    public function unverifiedOwner(): static
    {
        return $this->state(fn () => [
            'role' => 'owner',
            'verification_status' => 'unsubmitted',
            'verified_at' => null,
        ]);
    }

    public function pendingOwner(): static
    {
        return $this->state(fn () => [
            'role' => 'owner',
            'verification_status' => 'pending',
            'verified_at' => null,
        ]);
    }

    public function rejectedOwner(): static
    {
        return $this->state(fn () => [
            'role' => 'owner',
            'verification_status' => 'rejected',
            'verified_at' => null,
        ]);
    }

    public function tenant(): static
    {
        return $this->state(fn () => ['role' => 'tenant']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
