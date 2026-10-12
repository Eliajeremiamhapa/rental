<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private House $house;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->house = House::factory()->forOwner($this->owner)->create([
            'name' => 'Upanga Apartments',
            'address' => 'Upanga, Dar es Salaam',
            'contact_info' => '0711 000 111',
            'status' => 'active',
        ]);
    }

    public function test_public_listings_show_only_active_houses_with_available_rooms(): void
    {
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 80000, 'status' => 'available']);
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 120000, 'status' => 'occupied']);

        $inactive = House::factory()->forOwner($this->owner)->create([
            'name' => 'Closed House',
            'status' => 'inactive',
        ]);
        Room::factory()->forHouse($inactive)->create(['rent_amount' => 50000, 'status' => 'available']);

        // No auth token at all: this is a public endpoint.
        $response = $this->getJson('/api/listings/houses')->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Upanga Apartments', $response->json('data.0.name'));
        $this->assertSame(1, $response->json('data.0.available_rooms'));
        $this->assertSame(80000.0, (float) $response->json('data.0.min_rent'));
        $this->assertSame('0711 000 111', $response->json('data.0.contact_info'));
        $this->assertSame($this->owner->name, $response->json('data.0.owner.name'));
    }

    public function test_public_listings_never_expose_internal_fields(): void
    {
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 80000, 'status' => 'available']);

        $row = $this->getJson('/api/listings/houses')->json('data.0');

        $this->assertArrayNotHasKey('user_id', $row);
        $this->assertArrayNotHasKey('status', $row);
        $this->assertArrayNotHasKey('rentals', $row);
        $this->assertCount(2, $row['owner']);
    }

    public function test_public_listings_support_search_and_rent_filtering(): void
    {
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 80000, 'status' => 'available']);
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 120000, 'status' => 'available']);

        $cheap = House::factory()->forOwner($this->owner)->create(['name' => 'Kariakoo Studio', 'status' => 'active']);
        Room::factory()->forHouse($cheap)->create(['rent_amount' => 60000, 'status' => 'available']);

        $query = $this->getJson('/api/listings/houses?search=Kariakoo')->json('data');
        $this->assertCount(1, $query);
        $this->assertSame('Kariakoo Studio', $query[0]['name']);

        $budget = $this->getJson('/api/listings/houses?max_rent=90000')->json('data');
        $this->assertCount(2, $budget);
    }

    public function test_public_house_detail_lists_only_available_rooms(): void
    {
        $room = Room::factory()->forHouse($this->house)->create(['rent_amount' => 80000, 'status' => 'available']);
        Room::factory()->forHouse($this->house)->create(['rent_amount' => 120000, 'status' => 'occupied']);

        $response = $this->getJson("/api/listings/houses/{$this->house->id}")->assertOk();

        $this->assertCount(1, $response->json('data.rooms'));
        $this->assertSame($room->id, $response->json('data.rooms.0.id'));
        $this->assertSame(80000.0, (float) $response->json('data.rooms.0.rent_amount'));
        $this->assertSame($this->owner->name, $response->json('data.house.owner.name'));
    }

    public function test_public_house_detail_hides_inactive_houses(): void
    {
        $inactive = House::factory()->forOwner($this->owner)->create(['status' => 'inactive']);

        $this->getJson("/api/listings/houses/{$inactive->id}")->assertNotFound();
    }
}
