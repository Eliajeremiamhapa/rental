<?php

namespace Tests\Feature;

use App\Models\Feedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Owners and tenants can submit product feedback (with an optional rating).
 * Administrators review it from an inbox; submitters only ever see their own.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_owner_can_submit_feedback_and_admins_are_notified(): void
    {
        $owner = User::factory()->owner()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);

        Sanctum::actingAs($owner);

        $this->postJson('/api/feedback', [
            'category' => Feedback::CATEGORY_FEATURE_REQUEST,
            'rating' => 5,
            'message' => 'Please add bulk rent reminders.',
        ])->assertCreated();

        $this->assertDatabaseHas('feedback', [
            'user_id' => $owner->id,
            'role' => 'owner',
            'category' => Feedback::CATEGORY_FEATURE_REQUEST,
            'rating' => 5,
            'status' => Feedback::STATUS_NEW,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $admin->id,
            'type' => 'feedback_received',
        ]);
    }

    public function test_a_tenant_can_submit_feedback_without_a_rating(): void
    {
        $tenant = User::factory()->tenant()->create(['must_change_password' => false]);

        Sanctum::actingAs($tenant);

        $this->postJson('/api/feedback', [
            'category' => Feedback::CATEGORY_BUG,
            'message' => 'The receipt download fails on my phone.',
        ])->assertCreated();

        $this->assertDatabaseHas('feedback', [
            'user_id' => $tenant->id,
            'role' => 'tenant',
            'rating' => null,
        ]);
    }

    public function test_an_out_of_range_rating_is_rejected(): void
    {
        $tenant = User::factory()->tenant()->create(['must_change_password' => false]);

        Sanctum::actingAs($tenant);

        $this->postJson('/api/feedback', [
            'category' => Feedback::CATEGORY_COMPLIMENT,
            'rating' => 9,
            'message' => 'Great work.',
        ])->assertStatus(422);
    }

    public function test_the_index_only_returns_the_users_own_feedback(): void
    {
        $tenant = User::factory()->tenant()->create(['must_change_password' => false]);
        Feedback::factory()->create(['user_id' => $tenant->id]);
        Feedback::factory()->create();

        Sanctum::actingAs($tenant);

        $response = $this->getJson('/api/feedback')->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_an_admin_can_review_feedback_and_filter_by_role(): void
    {
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        Feedback::factory()->create(['role' => 'owner']);
        Feedback::factory()->create(['role' => 'tenant']);

        Sanctum::actingAs($admin);

        $all = $this->getJson('/api/admin/feedback')->assertOk();
        $this->assertSame(2, $all->json('data.total'));

        $owners = $this->getJson('/api/admin/feedback?role=owner')->assertOk();
        $this->assertSame(1, $owners->json('data.total'));

        $first = $owners->json('data.data.0.id');

        $this->patchJson("/api/admin/feedback/{$first}", [
            'status' => Feedback::STATUS_REVIEWED,
            'admin_note' => 'Logged on the roadmap.',
        ])->assertOk();

        $feedback = Feedback::find($first);
        $this->assertSame(Feedback::STATUS_REVIEWED, $feedback->status);
        $this->assertNotNull($feedback->reviewed_at);
        $this->assertSame($admin->id, $feedback->handled_by);
    }
}
