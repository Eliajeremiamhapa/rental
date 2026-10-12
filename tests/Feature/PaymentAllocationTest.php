<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RentChargeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A payment belongs to the tenancy, not to the single charge the payer pointed
 * at. Money must settle charges oldest-debt-first so a lump sum covering several
 * months pays them all, and so an overpayment cannot be stranded as credit on a
 * charge the following period can never reach.
 */
class PaymentAllocationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Tenant $tenant;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['must_change_password' => false]);
        $house = House::factory()->forOwner($this->owner)->create();
        $room = Room::factory()->forHouse($house)->create(['rent_amount' => 70000]);

        $tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $this->tenant = Tenant::factory()->forUser($tenantUser)->create([
            'owner_id' => $this->owner->id,
            'full_name' => 'Mwijage Hamis',
        ]);

        $this->rental = Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $house->id,
            'rent_amount' => 70000,
            'start_date' => '2026-11-05',
            'end_date' => '2027-02-04',
            'period_months' => 3,
            'due_day' => 5,
            'deposit_amount' => 0,
            'deposit_status' => 'none',
            'status' => 'active',
        ]);
    }

    /** @return array<int, RentCharge> charges keyed by period order (oldest first) */
    private function charges(): array
    {
        return $this->rental->rentCharges()
            ->orderBy('period_start')
            ->get()
            ->values()
            ->all();
    }

    private function pay(RentCharge $charge, float $amount): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson('/api/payments', [
            'tenant_id' => $this->tenant->id,
            'rent_charge_id' => $charge->id,
            'amount' => $amount,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ])->assertCreated();
    }

    private function assertCharge(RentCharge $charge, float $paid, string $status): void
    {
        $charge = $charge->fresh();

        $this->assertSame($paid, (float) $charge->paid_amount, "charge #{$charge->id} paid");
        $this->assertSame($status, $charge->status, "charge #{$charge->id} status");
    }

    public function test_lump_sum_recorded_against_a_later_period_settles_the_oldest_debt_first(): void
    {
        app(RentChargeService::class)->generateChargesForRental($this->rental);
        [$november, $december, $january] = $this->charges();

        // The exact reported case: 140000 (two months) received on one period,
        // recorded after a first month was already paid against a later period.
        $this->pay($january, 70000);
        $this->assertCharge($november, 70000, 'paid');
        $this->assertCharge($december, 0, 'unpaid');
        $this->assertCharge($january, 0, 'unpaid');

        $this->pay($december, 140000);
        $this->assertCharge($november, 70000, 'paid');
        $this->assertCharge($december, 70000, 'paid');
        $this->assertCharge($january, 70000, 'paid');
    }

    public function test_overpayment_is_absorbed_by_a_period_generated_later(): void
    {
        $this->rental->update(['period_months' => 1]);
        app(RentChargeService::class)->generateChargesForRental($this->rental);
        [$first] = $this->charges();

        $this->pay($first, 140000);
        $this->assertCharge($first, 140000, 'paid');

        // A second month is billed after the overpayment was received.
        $this->rental->update(['period_months' => 2]);
        app(RentChargeService::class)->generateRecurringCharges($this->rental);

        [$first, $second] = $this->charges();
        $this->assertCharge($first, 70000, 'paid');
        $this->assertCharge($second, 70000, 'paid');
    }

    public function test_partial_payment_marks_the_charge_partial(): void
    {
        app(RentChargeService::class)->generateChargesForRental($this->rental);
        [$november] = $this->charges();

        $this->pay($november, 30000);

        $this->assertCharge($november, 30000, 'partial');
    }

    public function test_reallocation_does_not_demote_an_overdue_charge(): void
    {
        $past = now()->subMonth()->toDateString();
        $overdue = RentCharge::factory()->create([
            'rental_id' => $this->rental->id,
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->rental->room_id,
            'period_start' => $past,
            'expected_amount' => 70000,
            'paid_amount' => 0,
            'status' => 'overdue',
            'due_date' => $past,
        ]);
        $future = RentCharge::factory()->create([
            'rental_id' => $this->rental->id,
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->rental->room_id,
            'period_start' => now()->addMonth()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'expected_amount' => 70000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // Paying the later period settles the older overdue debt first, without
        // clearing its overdue flag while it remains only partly paid.
        $this->pay($future, 10000);

        $this->assertCharge($overdue, 10000, 'overdue');
        $this->assertCharge($future, 0, 'unpaid');
    }
}
