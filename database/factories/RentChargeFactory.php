<?php

namespace Database\Factories;

use App\Models\Rental;
use App\Models\RentCharge;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<RentCharge>
 */
class RentChargeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'rental_id' => Rental::factory(),
            // A charge always belongs to the tenancy it was raised against, so
            // the tenant and room are taken from that rental rather than created
            // independently. Reports aggregate through room -> house, and a
            // mismatched room would silently drop the charge from its owner.
            'tenant_id' => fn (array $attributes) => Rental::find($attributes['rental_id'])?->tenant_id,
            'room_id' => fn (array $attributes) => Rental::find($attributes['rental_id'])?->room_id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'due_date' => now()->startOfMonth()->toDateString(),
            'expected_amount' => 160000.00,
            'paid_amount' => 0,
            'status' => 'unpaid',
            'paid_at' => null,
        ];
    }

    /**
     * A charge covering one calendar month, the way RentChargeService builds them.
     */
    public function forMonth(string $month): static
    {
        $start = Carbon::parse($month)->startOfMonth();

        return $this->state(fn () => [
            'period_start' => $start->toDateString(),
            'period_end' => $start->copy()->endOfMonth()->toDateString(),
            'due_date' => $start->toDateString(),
        ]);
    }

    public function withPayment(float $amount): static
    {
        return $this->state(fn () => ['paid_amount' => $amount]);
    }

    public function fullyPaid(): static
    {
        return $this->state(fn (array $attributes) => [
            'paid_amount' => (float) $attributes['expected_amount'],
            'status' => 'paid',
            'paid_at' => now(),
        ]);
    }
}
