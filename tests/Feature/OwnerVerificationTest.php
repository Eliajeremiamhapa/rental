<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\User;
use App\Models\VerificationDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Owners cannot list anything until an administrator has reviewed their
 * identity and ownership documents. This covers the owner's own upload flow,
 * the admin review queue, and the gate that keeps unverified owners out of the
 * owner area and off the public marketplace.
 */
class OwnerVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function uploadRequiredDocuments(User $owner): void
    {
        Sanctum::actingAs($owner);

        $this->postJson('/api/owner/verification/documents', [
            'type' => VerificationDocument::TYPE_NATIONAL_ID,
            'file' => UploadedFile::fake()->image('id.png'),
        ])->assertCreated();

        $this->postJson('/api/owner/verification/documents', [
            'type' => VerificationDocument::TYPE_OWNERSHIP_PROOF,
            'file' => UploadedFile::fake()->create('title-deed.pdf', 120, 'application/pdf'),
        ])->assertCreated();
    }

    public function test_an_unverified_owner_is_blocked_from_the_owner_area(): void
    {
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        Sanctum::actingAs($owner);

        $this->getJson('/api/owner/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('code', 'owner_not_verified');
    }

    public function test_an_unverified_owner_can_upload_documents_and_submit(): void
    {
        Storage::fake('local');
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);

        $this->uploadRequiredDocuments($owner);

        Sanctum::actingAs($owner);
        $this->postJson('/api/owner/verification/submit')->assertOk();

        $this->assertSame('pending', $owner->fresh()->verification_status);
        $this->assertDatabaseCount('verification_documents', 2);
    }

    public function test_submitting_without_all_documents_is_rejected(): void
    {
        Storage::fake('local');
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/owner/verification/submit')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame('unsubmitted', $owner->fresh()->verification_status);
    }

    public function test_reuploading_a_document_replaces_the_previous_copy(): void
    {
        Storage::fake('local');
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        Sanctum::actingAs($owner);

        $this->postJson('/api/owner/verification/documents', [
            'type' => VerificationDocument::TYPE_NATIONAL_ID,
            'file' => UploadedFile::fake()->image('old.png'),
        ])->assertCreated();

        $this->postJson('/api/owner/verification/documents', [
            'type' => VerificationDocument::TYPE_NATIONAL_ID,
            'file' => UploadedFile::fake()->image('new.png'),
        ])->assertCreated();

        $this->assertSame(1, $owner->verificationDocuments()->count());
        $this->assertSame('new.png', $owner->verificationDocuments()->first()->original_name);
    }

    public function test_an_admin_can_approve_an_owner_and_unlock_the_owner_area(): void
    {
        Storage::fake('local');
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        $this->uploadRequiredDocuments($owner);

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$owner->id}/approve")->assertOk();

        $owner->refresh();
        $this->assertSame('approved', $owner->verification_status);
        $this->assertNotNull($owner->verified_at);
        $this->assertSame($admin->id, $owner->verified_by);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'owner_verified',
        ]);

        Sanctum::actingAs($owner);
        $this->getJson('/api/owner/dashboard')->assertOk();
    }

    public function test_an_admin_cannot_approve_an_owner_without_documents(): void
    {
        $owner = User::factory()->pendingOwner()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$owner->id}/approve")->assertStatus(422);
        $this->assertSame('pending', $owner->fresh()->verification_status);
    }

    public function test_rejection_records_a_reason_and_lets_the_owner_resubmit(): void
    {
        Storage::fake('local');
        $owner = User::factory()->pendingOwner()->create(['must_change_password' => false]);
        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/users/{$owner->id}/reject", [
            'reason' => 'The ID photo is blurry.',
        ])->assertOk();

        $owner->refresh();
        $this->assertSame('rejected', $owner->verification_status);
        $this->assertSame('The ID photo is blurry.', $owner->verification_note);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $owner->id,
            'type' => 'owner_rejected',
        ]);

        // Re-uploading a document puts the owner back into the review queue.
        Sanctum::actingAs($owner);
        $this->postJson('/api/owner/verification/documents', [
            'type' => VerificationDocument::TYPE_NATIONAL_ID,
            'file' => UploadedFile::fake()->image('clear.png'),
        ])->assertCreated();

        $this->assertSame('pending', $owner->fresh()->verification_status);
    }

    public function test_the_review_queue_lists_owners_awaiting_review(): void
    {
        User::factory()->pendingOwner()->create(['must_change_password' => false]);
        User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        User::factory()->owner()->create(['must_change_password' => false]);

        $admin = User::factory()->admin()->create(['must_change_password' => false]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/owner-verifications')
            ->assertOk()
            ->assertJsonCount(2, 'data.data');
    }

    public function test_an_unverified_owners_houses_are_hidden_from_the_marketplace(): void
    {
        $owner = User::factory()->unverifiedOwner()->create(['must_change_password' => false]);
        $house = House::factory()->forOwner($owner)->create(['status' => 'active']);

        $this->getJson('/api/listings/houses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/listings/houses/{$house->id}")->assertNotFound();
    }
}
