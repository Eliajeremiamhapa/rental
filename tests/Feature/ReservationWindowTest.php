<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Notification;
use App\Models\Rental;
use App\Models\RentalRequest;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Accepting a request holds the room for a due-diligence window. First-time
 * tenants get the full window so they can confirm the room is genuine; a
 * returning tenant is trusted. A room can never stay stuck on "reserved":
 * the scheduled command releases it when the window passes.
 */
class ReservationWindowTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tenantUser;

    private Tenant $tenant;

    private House $house;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create(['must_change_password' => false]);
        $this->house = House::factory()->forOwner($this->owner)->create(['status' => 'active']);
        $this->room = Room::factory()->forHouse($this->house)->create([
            'rent_amount' => 100000,
            'status' => 'available',
        ]);

        $this->tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $this->tenant = Tenant::factory()->forUser($this->tenantUser)->create([
            'owner_id' => null,
            'full_name' => 'Juma Hassan',
            'phone' => $this->tenantUser->phone,
        ]);
    }

    private function createRequest(): RentalRequest
    {
        Sanctum::actingAs($this->tenantUser);

        $this->postJson('/api/rental-requests', [
            'room_id' => $this->room->id,
            'message' => 'Naomba kukodi chumba hiki.',
            'desired_start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'period_months' => 12,
        ])->assertCreated();

        return RentalRequest::where('tenant_id', $this->tenant->id)->latest('id')->firstOrFail();
    }

    private function accept(RentalRequest $request): Rental
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/owner/rental-requests/{$request->id}/accept", [
            'start_date' => $request->desired_start_date->toDateString(),
        ])->assertOk();

        return Rental::findOrFail($response->json('data.rental.id'));
    }

    public function test_accepting_a_first_time_request_opens_a_due_diligence_window(): void
    {
        $rental = $this->accept($this->createRequest());

        $this->assertTrue($rental->due_diligence_required);
        $this->assertSame('reserved', $this->room->fresh()->status);
        $this->assertNotNull($this->room->fresh()->reserved_until);

        $hours = now()->diffInHours($this->room->fresh()->reserved_until);
        $this->assertEqualsWithDelta(72, $hours, 1);
    }

    public function test_a_returning_tenant_does_not_get_a_due_diligence_window(): void
    {
        Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->room->id,
            'house_id' => $this->house->id,
            'rent_amount' => 100000,
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->subMonth()->toDateString(),
            'period_months' => 12,
            'due_day' => 5,
            'status' => 'terminated',
        ]);

        $rental = $this->accept($this->createRequest());

        $this->assertFalse($rental->due_diligence_required);
    }

    public function test_a_tenant_cannot_hold_two_reservations_at_once(): void
    {
        $this->accept($this->createRequest());

        $this->room = Room::factory()->forHouse($this->house)->create([
            'rent_amount' => 90000,
            'status' => 'available',
        ]);

        $second = $this->createRequest();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/owner/rental-requests/{$second->id}/accept", [
            'start_date' => $second->desired_start_date->toDateString(),
        ])->assertStatus(422);
    }

    public function test_an_expired_reservation_is_released_by_the_command(): void
    {
        $rental = $this->accept($this->createRequest());
        $this->room->update(['reserved_until' => now()->subHour()]);

        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame('available', $this->room->fresh()->status);
        $this->assertNull($this->room->fresh()->reserved_until);
        $this->assertSame('terminated', $rental->fresh()->status);
        $this->assertSame('cancelled', RentalRequest::firstOrFail()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenantUser->id,
            'type' => 'reservation_released',
        ]);
    }

    public function test_the_command_warns_a_tenant_whose_reservation_is_ending(): void
    {
        $this->accept($this->createRequest());
        $this->room->update(['reserved_until' => now()->addHours(3)]);

        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenantUser->id,
            'type' => 'reservation_expiring',
        ]);

        // Running again must not send a duplicate warning.
        $this->artisan('reservations:release-expired')->assertSuccessful();

        $this->assertSame(1, Notification::where('user_id', $this->tenantUser->id)
            ->where('type', 'reservation_expiring')
            ->count());
    }

    public function test_a_tenant_can_confirm_they_are_ready_to_proceed(): void
    {
        $rental = $this->accept($this->createRequest());

        Sanctum::actingAs($this->tenantUser);
        $this->postJson("/api/rentals/{$rental->id}/confirm-proceed")->assertOk();

        $this->assertNotNull($rental->fresh()->visit_confirmed_at);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->owner->id,
            'type' => 'visit_confirmed',
        ]);
    }

    public function test_another_tenant_cannot_confirm_someone_elses_reservation(): void
    {
        $rental = $this->accept($this->createRequest());

        $outsider = User::factory()->tenant()->create(['must_change_password' => false]);
        Tenant::factory()->forUser($outsider)->create(['owner_id' => $this->owner->id]);

        Sanctum::actingAs($outsider);
        $this->postJson("/api/rentals/{$rental->id}/confirm-proceed")->assertNotFound();
    }
}
