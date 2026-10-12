<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\House;
use App\Models\Rental;
use App\Models\RentalRequest;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The marketplace flow: a self-registered tenant requests a listed room, an
 * owner accepts (reserving it), a contract is issued, and only the tenant's
 * signature activates the tenancy and its rent charges.
 */
class RentalRequestFlowTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG = 'data:image/png;base64,'
        .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private User $owner;

    private User $tenantUser;

    private Tenant $tenant;

    private House $house;

    private Room $room;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->house = House::factory()->forOwner($this->owner)->create(['status' => 'active']);
        $this->room = Room::factory()->forHouse($this->house)->create([
            'rent_amount' => 120000.00,
            'status' => 'available',
        ]);

        $this->tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $this->tenant = Tenant::factory()->forUser($this->tenantUser)->create([
            'owner_id' => null,
            'full_name' => 'Juma Hassan',
            'phone' => $this->tenantUser->phone,
        ]);
    }

    /** @return array<string, mixed> */
    private function requestPayload(): array
    {
        return [
            'room_id' => $this->room->id,
            'message' => 'Ninapenda kukodi chumba hiki.',
            'desired_start_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'period_months' => 12,
        ];
    }

    private function createRequest(): RentalRequest
    {
        Sanctum::actingAs($this->tenantUser);

        $this->postJson('/api/rental-requests', $this->requestPayload())->assertCreated();

        return RentalRequest::where('tenant_id', $this->tenant->id)->firstOrFail();
    }

    private function acceptRequest(RentalRequest $rentalRequest): Rental
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson("/api/owner/rental-requests/{$rentalRequest->id}/accept", [
            'start_date' => $rentalRequest->desired_start_date->toDateString(),
            'response_message' => 'Tumekubali ombi lako.',
        ])->assertOk();

        return Rental::findOrFail($response->json('data.rental.id'));
    }

    /** @return array<string, mixed> */
    private function contractPayload(Rental $rental): array
    {
        return [
            'rental_id' => $rental->id,
            'rent_amount' => 120000,
            'period_months' => 12,
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
            'due_day' => $rental->due_day,
            'deposit_amount' => 0,
            'service_fee' => 0,
            'currency' => 'TZS',
        ];
    }

    private function issueAndSendContract(Rental $rental): string
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/owner/contracts', $this->contractPayload($rental))
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/owner/contracts/{$id}/send")->assertOk();

        return (string) Contract::findOrFail($id)->signature_token;
    }

    public function test_a_tenant_can_request_an_available_room(): void
    {
        $rentalRequest = $this->createRequest();

        $this->assertSame('pending', $rentalRequest->status);
        $this->assertSame(120000.0, (float) $rentalRequest->room->rent_amount);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->owner->id,
            'type' => 'rental_request_received',
        ]);
    }

    public function test_a_duplicate_pending_request_for_the_same_room_is_rejected(): void
    {
        $this->createRequest();

        Sanctum::actingAs($this->tenantUser);
        $this->postJson('/api/rental-requests', $this->requestPayload())
            ->assertStatus(422);
    }

    public function test_a_tenant_cannot_request_a_room_that_is_no_longer_available(): void
    {
        $this->room->update(['status' => 'occupied']);

        Sanctum::actingAs($this->tenantUser);
        $this->postJson('/api/rental-requests', $this->requestPayload())
            ->assertStatus(422);
    }

    public function test_an_owner_sees_only_requests_against_their_own_houses(): void
    {
        $this->createRequest();

        $otherOwner = User::factory()->owner()->create(['must_change_password' => false]);
        Sanctum::actingAs($otherOwner);

        $this->getJson('/api/owner/rental-requests')->assertOk()->assertJsonCount(0, 'data');

        $requestId = RentalRequest::firstOrFail()->id;
        $this->getJson("/api/owner/rental-requests/{$requestId}")->assertNotFound();
    }

    public function test_accepting_reserves_the_room_and_creates_a_pending_rental(): void
    {
        $rentalRequest = $this->createRequest();
        $rental = $this->acceptRequest($rentalRequest);

        $this->assertSame('pending', $rental->status);
        $this->assertSame('reserved', $this->room->fresh()->status);
        $this->assertSame('accepted', $rentalRequest->fresh()->status);
        $this->assertSame($this->owner->id, $this->tenant->fresh()->owner_id);

        // No charges exist until the tenant signs.
        $this->assertDatabaseCount('rent_charges', 0);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenantUser->id,
            'type' => 'rental_request_accepted',
        ]);
    }

    public function test_accepting_requires_an_available_room(): void
    {
        $rentalRequest = $this->createRequest();
        $this->room->update(['status' => 'occupied']);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/owner/rental-requests/{$rentalRequest->id}/accept")
            ->assertStatus(422);
    }

    public function test_a_tenant_already_renting_with_another_owner_cannot_be_adopted(): void
    {
        $firstOwner = User::factory()->owner()->create(['must_change_password' => false]);
        $firstHouse = House::factory()->forOwner($firstOwner)->create();
        $firstRoom = Room::factory()->forHouse($firstHouse)->create(['status' => 'occupied']);

        $this->tenant->update(['owner_id' => $firstOwner->id]);
        Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $firstRoom->id,
            'house_id' => $firstHouse->id,
            'rent_amount' => 100000,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(11)->endOfMonth()->toDateString(),
            'period_months' => 12,
            'due_day' => 5,
            'status' => 'active',
        ]);

        $rentalRequest = $this->createRequest();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/owner/rental-requests/{$rentalRequest->id}/accept")
            ->assertStatus(422);
    }

    public function test_signing_the_contract_activates_the_rental_and_generates_charges(): void
    {
        $rentalRequest = $this->createRequest();
        $rental = $this->acceptRequest($rentalRequest);
        $token = $this->issueAndSendContract($rental);

        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => true,
        ])->assertOk()->assertJsonPath('data.state', 'signed');

        $rental->refresh();
        $this->assertSame('active', $rental->status);
        $this->assertSame('occupied', $this->room->fresh()->status);
        $this->assertSame(12, $rental->rentCharges()->count());
        $this->assertSame(1440000.0, (float) $rental->rentCharges()->sum('expected_amount'));
    }

    public function test_declining_the_contract_releases_the_room_back_to_available(): void
    {
        $rentalRequest = $this->createRequest();
        $rental = $this->acceptRequest($rentalRequest);
        $token = $this->issueAndSendContract($rental);

        $this->postJson("/api/contracts/sign/{$token}/decline", [
            'reason' => 'Nimekuta chanzo kingine.',
        ])->assertOk();

        $this->assertSame('terminated', $rental->fresh()->status);
        $this->assertSame('available', $this->room->fresh()->status);
    }

    public function test_declining_a_request_notifies_the_tenant(): void
    {
        $rentalRequest = $this->createRequest();

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/owner/rental-requests/{$rentalRequest->id}/decline", [
            'response_message' => 'Chumba hiki kimeisha.',
        ])->assertOk();

        $this->assertSame('declined', $rentalRequest->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenantUser->id,
            'type' => 'rental_request_declined',
        ]);
    }

    public function test_a_tenant_only_ever_sees_their_own_requests(): void
    {
        $this->createRequest();

        $outsiderUser = User::factory()->tenant()->create(['must_change_password' => false]);
        Tenant::factory()->forUser($outsiderUser)->create(['owner_id' => $this->owner->id]);

        Sanctum::actingAs($outsiderUser);
        $this->getJson('/api/rental-requests')->assertOk()->assertJsonCount(0, 'data');
    }
}
