<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\House;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    /** A real 1x1 PNG, base64-encoded, accepted by the signature validator. */
    private const TINY_PNG_DATA_URI = 'data:image/png;base64,'
        .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function definition(): array
    {
        $rent = 160000.00;

        return [
            'contract_number' => sprintf('UV-%d-%04d', now()->year, fake()->unique()->numberBetween(1, 9999)),
            'rental_id' => Rental::factory(),
            'tenant_id' => Tenant::factory(),
            'house_id' => House::factory(),
            'room_id' => Room::factory(),
            'owner_id' => User::factory()->owner(),
            'status' => 'draft',
            'template_version' => '1.0',
            'language' => 'sw',
            'rent_amount' => $rent,
            'total_amount' => $rent,
            'daily_rate' => round($rent / 30, 2),
            'period_months' => 12,
            'due_day' => 5,
            'service_fee' => 0,
            'deposit_amount' => $rent,
            'currency' => 'TZS',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
        ];
    }

    public function signed(): static
    {
        return $this->state(fn () => [
            'status' => 'signed',
            'signature_token' => null,
            'signer_name' => 'Test Signer',
            'signer_consent_text' => 'Consent recorded for testing.',
            'signer_consent_version' => '1.0',
            'signer_consented_at' => now(),
            'signature_image' => self::TINY_PNG_DATA_URI,
            'document_sha256' => hash('sha256', Str::uuid()->toString()),
            'signed_at' => now(),
        ]);
    }
}
