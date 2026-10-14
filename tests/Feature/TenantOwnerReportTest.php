<?php

namespace Tests\Feature;

use App\Models\OwnerReport;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tenants may only report owners they have a standing relationship with, and
 * only see their own reports. Filing a report should alert administrators
 * without ever notifying the reported owner.
 */
class TenantOwnerReportTest extends TestCase
{
    use RefreshDatabase;

    private function tenantUserWithLandlord(User $owner): User
    {
        $user = User::factory()->tenant()->create(['must_change_password' => false]);
        Tenant::factory()->forUser($user)->create(['owner_id' => $owner->id]);

        return $user;
    }

    public function test_a_tenant_can_report_their_landlord_and_admins_are_notified(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);
        $tenantUser = $this->tenantUserWithLandlord($owner);

        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        Sanctum::actingAs($tenantUser);

        $this->postJson('/api/owner-reports', [
            'owner_id' => $owner->id,
            'category' => OwnerReport::CATEGORY_SCAM,
            'subject' => 'Took deposit and disappeared',
            'description' => 'After paying the deposit the owner stopped responding.',
        ])->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('owner_reports', [
            'reporter_id' => $tenantUser->id,
            'owner_id' => $owner->id,
            'status' => OwnerReport::STATUS_SUBMITTED,
        ]);

        // Admins are alerted, the reported owner is not.
        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'owner_report_received',
        ]);
        $this->assertDatabaseMissing('notifications', [
            'user_id' => $owner->id,
            'type' => 'owner_report_received',
        ]);
    }

    public function test_a_tenant_cannot_report_an_unrelated_owner(): void
    {
        $landlord = User::factory()->owner()->create(['must_change_password' => false]);
        $stranger = User::factory()->owner()->create(['must_change_password' => false]);
        $tenantUser = $this->tenantUserWithLandlord($landlord);

        Sanctum::actingAs($tenantUser);

        $this->postJson('/api/owner-reports', [
            'owner_id' => $stranger->id,
            'category' => OwnerReport::CATEGORY_OTHER,
            'subject' => 'Something',
            'description' => 'A report about an unrelated owner.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('owner_reports', 0);
    }

    public function test_a_user_without_a_tenant_profile_cannot_file_a_report(): void
    {
        // A tenant-role account that never completed a tenant profile.
        $tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $owner = User::factory()->owner()->create(['must_change_password' => false]);

        Sanctum::actingAs($tenantUser);

        $this->postJson('/api/owner-reports', [
            'owner_id' => $owner->id,
            'category' => OwnerReport::CATEGORY_OTHER,
            'subject' => 'Something',
            'description' => 'No tenant profile here.',
        ])->assertStatus(422);

        $this->assertDatabaseCount('owner_reports', 0);
    }

    public function test_the_index_only_returns_the_tenants_own_reports(): void
    {
        $landlord = User::factory()->owner()->create(['must_change_password' => false]);
        $tenantUser = $this->tenantUserWithLandlord($landlord);

        $mine = OwnerReport::factory()->create([
            'reporter_id' => $tenantUser->id,
            'owner_id' => $landlord->id,
        ]);
        OwnerReport::factory()->create();

        Sanctum::actingAs($tenantUser);

        $response = $this->getJson('/api/owner-reports')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($mine->id, $response->json('data.0.id'));
    }

    public function test_a_tenant_can_attach_evidence_to_their_own_report(): void
    {
        Storage::fake('public');

        $landlord = User::factory()->owner()->create(['must_change_password' => false]);
        $tenantUser = $this->tenantUserWithLandlord($landlord);
        $report = OwnerReport::factory()->create([
            'reporter_id' => $tenantUser->id,
            'owner_id' => $landlord->id,
        ]);

        Sanctum::actingAs($tenantUser);

        $this->postJson("/api/owner-reports/{$report->id}/evidence", [
            'file' => UploadedFile::fake()->image('proof.png'),
        ])->assertCreated();

        $this->assertSame(1, $report->media()->count());
    }

    public function test_a_tenant_cannot_attach_evidence_to_another_tenants_report(): void
    {
        Storage::fake('public');

        $landlord = User::factory()->owner()->create(['must_change_password' => false]);
        $report = OwnerReport::factory()->create(['owner_id' => $landlord->id]);
        $other = $this->tenantUserWithLandlord($landlord);

        Sanctum::actingAs($other);

        $this->postJson("/api/owner-reports/{$report->id}/evidence", [
            'file' => UploadedFile::fake()->image('proof.png'),
        ])->assertNotFound();
    }

    public function test_eligible_owners_lists_only_owners_the_tenant_has_standing_with(): void
    {
        $landlord = User::factory()->owner()->create(['must_change_password' => false]);
        User::factory()->owner()->create(['must_change_password' => false]);
        $tenantUser = $this->tenantUserWithLandlord($landlord);

        Sanctum::actingAs($tenantUser);

        $response = $this->getJson('/api/owner-reports/eligible-owners')->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($landlord->id, $response->json('data.0.id'));
    }
}
