<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every rental must be billed exactly period_months monthly installments.
 *
 * The old generator walked calendar months, so a tenancy that started
 * mid-month was charged N+1 full months, and an N-month rental created
 * through "Assign tenant" got a single charge. These tests lock the invariant:
 * a N-month rental starting on any day produces N non-overlapping charges.
 */
class RentChargeGenerationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    /** @return array{house: House, room: Room, tenant: Tenant} */
    private function setupAssets(): array
    {
        $house = House::factory()->forOwner($this->owner)->create();
        $room = Room::factory()->forHouse($house)->create(['rent_amount' => 100000.00]);

        $tenant = Tenant::factory()->forUser(
            User::factory()->tenant()->create(['must_change_password' => false])
        )->create([
            'owner_id' => $this->owner->id,
            'full_name' => 'Juma Hassan',
        ]);

        return ['house' => $house, 'room' => $room, 'tenant' => $tenant];
    }

    private function rental(array $attributes): Rental
    {
        return Rental::create(array_merge([
            'rent_amount' => 100000.00,
            'start_date' => '2026-10-10',
            'end_date' => '2026-12-09',
            'period_months' => 2,
            'due_day' => 10,
            'deposit_amount' => 0,
            'deposit_status' => 'none',
            'status' => 'active',
        ], $attributes));
    }

    public function test_mid_month_rental_generates_exactly_period_months_charges(): void
    {
        $assets = $this->setupAssets();

        $rental = $this->rental([
            'tenant_id' => $assets['tenant']->id,
            'room_id' => $assets['room']->id,
            'house_id' => $assets['house']->id,
        ]);

        app(RentChargeService::class)->generateChargesForRental($rental);

        $charges = $rental->rentCharges()->orderBy('period_start')->get();

        $this->assertCount(2, $charges);
        $this->assertSame(200000.0, (float) $rental->rentCharges()->sum('expected_amount'));
        $this->assertSame(
            ['2026-10-10', '2026-11-10'],
            $charges->pluck('period_start')->map(fn ($d) => $d->toDateString())->all(),
        );
        $this->assertSame(
            ['2026-11-09', '2026-12-09'],
            $charges->pluck('period_end')->map(fn ($d) => $d->toDateString())->all(),
        );
    }

    public function test_month_start_rental_generates_exactly_period_months_charges(): void
    {
        $assets = $this->setupAssets();

        $rental = $this->rental([
            'tenant_id' => $assets['tenant']->id,
            'room_id' => $assets['room']->id,
            'house_id' => $assets['house']->id,
            'start_date' => '2026-10-01',
            'end_date' => '2026-11-30',
            'period_months' => 2,
            'due_day' => 1,
        ]);

        app(RentChargeService::class)->generateChargesForRental($rental);

        $this->assertCount(2, $rental->rentCharges()->get());
        $this->assertSame(200000.0, (float) $rental->rentCharges()->sum('expected_amount'));
    }

    public function test_recurring_generation_never_duplicates_existing_periods(): void
    {
        $assets = $this->setupAssets();

        $start = now()->subMonths(2)->startOfMonth()->addDays(10);
        $end = $start->copy()->addMonths(2)->subDay();

        $rental = $this->rental([
            'tenant_id' => $assets['tenant']->id,
            'room_id' => $assets['room']->id,
            'house_id' => $assets['house']->id,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'period_months' => 2,
            'due_day' => (int) $start->format('d'),
        ]);

        app(RentChargeService::class)->generateChargesForRental($rental);
        $this->assertCount(2, $rental->rentCharges()->get());

        $created = app(RentChargeService::class)->generateRecurringCharges($rental, 6);

        $this->assertCount(0, $created);

        $charges = $rental->rentCharges()->get();
        $this->assertCount($charges->pluck('period_start')->unique()->count(), $charges);

        // No overlapping/duplicate period may ever appear on the record.
        $this->assertSame(2, $charges->pluck('period_start')->unique()->count());
    }

    public function test_assignment_endpoint_and_rental_endpoint_generate_the_same_charge_set(): void
    {
        $assets = $this->setupAssets();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/owner/assignments', [
            'room_id' => $assets['room']->id,
            'tenant_id' => $assets['tenant']->id,
            'rent_amount' => 100000,
            'start_date' => '2026-10-10',
            'period_months' => 2,
            'deposit_amount' => 50000,
        ])->assertCreated();

        $assigned = Rental::where('room_id', $assets['room']->id)->firstOrFail();
        $this->assertSame(2, $assigned->rentCharges()->count());
        $this->assertSame(200000.0, (float) $assigned->rentCharges()->sum('expected_amount'));

        // Reuse the same room (the previous rental is expired by the booted hook),
        // so the same tenant/room combination is created via /api/rentals.
        $room2 = Room::factory()->forHouse($assets['house'])->create(['rent_amount' => 100000.00]);

        $this->postJson('/api/rentals', [
            'tenant_id' => $assets['tenant']->id,
            'room_id' => $room2->id,
            'rent_amount' => 100000,
            'start_date' => '2026-10-10',
            'period_months' => 2,
            'deposit_amount' => 0,
        ])->assertCreated();

        $created = Rental::where('room_id', $room2->id)->firstOrFail();
        $this->assertSame(2, $created->rentCharges()->count());
        $this->assertSame(200000.0, (float) $created->rentCharges()->sum('expected_amount'));

        $this->assertSame(
            $created->rentCharges()->orderBy('period_start')->pluck('period_start')->map(fn ($d) => $d->toDateString())->all(),
            $assigned->rentCharges()->orderBy('period_start')->pluck('period_start')->map(fn ($d) => $d->toDateString())->all(),
        );
    }
}
