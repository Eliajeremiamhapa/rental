<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);
    }

    public function test_admin_routes_reject_unauthenticated_and_non_admin_users(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);
        $tenant = User::factory()->tenant()->create(['must_change_password' => false]);

        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->actingAs($owner, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($tenant, 'sanctum')->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_users(): void
    {
        User::factory()->owner()->create(['name' => 'Owner One', 'must_change_password' => false]);
        User::factory()->tenant()->create(['name' => 'Tenant Two', 'must_change_password' => false]);

        $all = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/users')
            ->assertOk();
        $this->assertGreaterThanOrEqual(3, $all->json('data.total'));

        $owners = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/users?role=owner')
            ->assertOk();
        $this->assertSame(1, $owners->json('data.total'));
        $this->assertSame('Owner One', $owners->json('data.data.0.name'));

        $search = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/users?search=Tenant Two')
            ->assertOk();
        $this->assertSame(1, $search->json('data.total'));
    }

    public function test_admin_can_suspend_and_activate_a_user(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);
        $owner->createToken('test');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$owner->id}/suspend")
            ->assertOk();

        $owner->refresh();
        $this->assertFalse($owner->is_active);
        $this->assertSame(0, $owner->tokens()->count());
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'system',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$owner->id}/activate")
            ->assertOk();
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_suspending_an_owner_hides_their_listings(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);
        $house = House::factory()->forOwner($owner)->create(['status' => 'active']);
        Room::factory()->forHouse($house)->create(['rent_amount' => 80000, 'status' => 'available']);

        $this->getJson('/api/listings/houses')->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$owner->id}/suspend")
            ->assertOk();

        $this->getJson('/api/listings/houses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/listings/houses/{$house->id}")->assertNotFound();
    }

    public function test_admin_can_grant_and_revoke_admin_restoring_previous_role(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$owner->id}/grant-admin")
            ->assertOk();

        $owner->refresh();
        $this->assertSame('admin', $owner->role);
        $this->assertSame('owner', $owner->previous_role);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$owner->id}/revoke-admin")
            ->assertOk();

        $owner->refresh();
        $this->assertSame('owner', $owner->role);
        $this->assertNull($owner->previous_role);
    }

    public function test_admin_cannot_suspend_or_revoke_themselves(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$this->admin->id}/suspend")
            ->assertStatus(422);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$this->admin->id}/revoke-admin")
            ->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_admin_can_reset_a_user_password(): void
    {
        $tenant = User::factory()->tenant()->create([
            'must_change_password' => false,
            'is_active' => true,
            'password' => 'originalpass1',
        ]);
        $tenant->createToken('test');

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/admin/users/{$tenant->id}/reset-password")
            ->assertOk();

        $tenant->refresh();
        $this->assertTrue((bool) $tenant->must_change_password);
        $this->assertSame(0, $tenant->tokens()->count());
        $this->assertFalse(Hash::check('originalpass1', $tenant->password));

        // SMS is unconfigured in tests, so the temporary password is returned.
        $temporary = $response->json('data.temporary_password');
        $this->assertNotEmpty($temporary);
        $this->assertTrue(Hash::check($temporary, $tenant->password));
    }
}
