<?php

namespace Tests\Feature;

use App\Models\OwnerReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Administrators work the owner-report queue: list and filter reports, move
 * them through the status workflow, and notify the reporter on change. The
 * reported owner is deliberately kept in the dark.
 */
class AdminOwnerReportTest extends TestCase
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

    public function test_admin_routes_reject_non_admin_users(): void
    {
        Sanctum::actingAs(User::factory()->owner()->create(['must_change_password' => false]));

        $this->getJson('/api/admin/owner-reports')->assertForbidden();
    }

    public function test_admin_can_list_and_filter_owner_reports(): void
    {
        OwnerReport::factory()->create(['category' => OwnerReport::CATEGORY_SCAM]);
        OwnerReport::factory()->inReview()->create(['category' => OwnerReport::CATEGORY_HARASSMENT]);

        Sanctum::actingAs($this->admin);

        $all = $this->getJson('/api/admin/owner-reports')->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        $filtered = $this->getJson('/api/admin/owner-reports?category=scam')->assertOk();
        $this->assertSame(1, $filtered->json('data.total'));

        $byStatus = $this->getJson('/api/admin/owner-reports?status=in_review')->assertOk();
        $this->assertSame(1, $byStatus->json('data.total'));
    }

    public function test_updating_a_report_records_the_outcome_and_notifies_the_reporter(): void
    {
        $report = OwnerReport::factory()->create();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/owner-reports/{$report->id}", [
            'status' => OwnerReport::STATUS_RESOLVED,
            'admin_note' => 'Owner suspended and deposit refunded.',
        ])->assertOk();

        $report->refresh();
        $this->assertSame(OwnerReport::STATUS_RESOLVED, $report->status);
        $this->assertSame($this->admin->id, $report->handled_by);
        $this->assertNotNull($report->resolved_at);
        $this->assertSame('Owner suspended and deposit refunded.', $report->admin_note);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $report->reporter_id,
            'type' => 'owner_report_updated',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'owner_report_reviewed',
            'auditable_id' => $report->id,
        ]);
    }

    public function test_the_submitted_status_cannot_be_set_again(): void
    {
        $report = OwnerReport::factory()->create();

        Sanctum::actingAs($this->admin);

        $this->patchJson("/api/admin/owner-reports/{$report->id}", [
            'status' => OwnerReport::STATUS_SUBMITTED,
        ])->assertStatus(422);
    }
}
