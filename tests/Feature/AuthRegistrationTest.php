<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tenant_can_register_and_is_logged_in_automatically(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Asha Omar',
            'role' => 'tenant',
            'phone' => '0712345678',
            'email' => 'asha@example.com',
            'password' => 'secretpass1',
            'password_confirmation' => 'secretpass1',
            'id_number' => '19800101123456',
            'address' => 'Morogoro',
        ])->assertCreated();

        $this->assertNotNull($response->json('data.token'));
        $this->assertSame('tenant', $response->json('data.user.role'));

        $user = User::where('phone', '0712345678')->firstOrFail();
        $this->assertTrue(Hash::check('secretpass1', $user->password));
        $this->assertFalse((bool) $user->must_change_password);
        $this->assertTrue((bool) $user->is_active);

        // The tenant profile exists up-front but belongs to no owner yet.
        $tenant = Tenant::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Asha Omar', $tenant->full_name);
        $this->assertNull($tenant->owner_id);
        $this->assertSame('active', $tenant->status);

        // The returned token actually works.
        $this->withHeader('Authorization', 'Bearer '.$response->json('data.token'))
            ->getJson('/api/profile')
            ->assertOk()
            ->assertJsonPath('data.phone', '0712345678');
    }

    public function test_an_owner_can_register_without_a_tenant_profile(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Halima Musa',
            'role' => 'owner',
            'phone' => '0755123456',
            'password' => 'secretpass1',
            'password_confirmation' => 'secretpass1',
        ])->assertCreated();

        $user = User::where('phone', '0755123456')->firstOrFail();
        $this->assertSame('owner', $user->role);
        $this->assertDatabaseMissing('tenants', ['user_id' => $user->id]);
        $this->assertNull($response->json('data.user.tenant'));
    }

    public function test_registration_rejects_a_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '0712345678']);

        $this->postJson('/api/register', [
            'name' => 'Asha Omar',
            'role' => 'tenant',
            'phone' => '0712345678',
            'password' => 'secretpass1',
            'password_confirmation' => 'secretpass1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_registration_requires_confirmed_password(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Asha Omar',
            'role' => 'tenant',
            'phone' => '0712345678',
            'password' => 'secretpass1',
            'password_confirmation' => 'differentpass',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    public function test_registration_requires_a_valid_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Asha Omar',
            'role' => 'admin',
            'phone' => '0712345678',
            'password' => 'secretpass1',
            'password_confirmation' => 'secretpass1',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }
}
