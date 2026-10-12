<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\House;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * The invariants the contract feature must never break.
 *
 * These run entirely on the test database: create a tenancy, issue a contract
 * through the real HTTP endpoints, sign it, and assert the evidence is intact
 * and the document is frozen. Nothing here touches the dev MySQL database.
 */
class ContractLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private const TINY_PNG = 'data:image/png;base64,'
        .'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private User $owner;

    private User $tenantUser;

    private Tenant $tenant;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $house = House::factory()->forOwner($this->owner)->create();
        $room = Room::factory()->forHouse($house)->create(['rent_amount' => 160000.00]);

        $this->tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $this->tenant = Tenant::factory()->forUser($this->tenantUser)->create([
            'owner_id' => $this->owner->id,
            'full_name' => 'Juma Hassan',
        ]);

        $this->rental = Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $house->id,
            'rent_amount' => 160000.00,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'period_months' => 12,
            'due_day' => 5,
            'deposit_amount' => 160000.00,
            'deposit_status' => 'pending',
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function createPayload(): array
    {
        return [
            'rental_id' => $this->rental->id,
            'rent_amount' => 160000,
            'period_months' => 12,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'due_day' => 5,
            'deposit_amount' => 160000,
            'service_fee' => 0,
            'currency' => 'TZS',
        ];
    }

    private function issueContract(): Contract
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/owner/contracts', $this->createPayload())
            ->assertCreated();

        return Contract::findOrFail($response->json('data.id'));
    }

    private function sendAndFetchToken(Contract $contract): string
    {
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/owner/contracts/{$contract->id}/send")
            ->assertOk()
            ->assertJsonPath('data.notified', true)
            ->assertJsonPath('data.contract.status', 'pending_signature');

        $token = (string) $contract->fresh()->signature_token;
        $this->assertSame(64, strlen($token));

        return $token;
    }

    public function test_owner_can_create_a_draft_contract_from_a_rental(): void
    {
        $contract = $this->issueContract();

        $this->assertSame('draft', $contract->status);
        $this->assertSame(160000.0, (float) $contract->rent_amount);
        $this->assertMatchesRegularExpression('/^UV-\d{4}-\d{4}$/', $contract->contract_number);
        $this->assertNotNull($contract->clause_text);
        $this->assertCount(13, $contract->clause_text);
    }

    public function test_overstay_charge_is_derived_from_the_monthly_rent(): void
    {
        // Daily rate: 160000 / 30 = 5333.33. The overstay charge is that same
        // per-day rate, derived server-side, regardless of what the client sends.
        Sanctum::actingAs($this->owner);

        $payload = $this->createPayload();
        $payload['notice_per_day'] = 999999;

        $contract = Contract::findOrFail(
            $this->postJson('/api/owner/contracts', $payload)->assertCreated()->json('data.id')
        );

        $this->assertEqualsWithDelta(5333.33, (float) $contract->daily_rate, 0.001);
        $this->assertSame((float) $contract->daily_rate, (float) $contract->notice_per_day);

        // The document the tenant is actually shown derives from the same
        // column, so the frozen figure can never disagree with the rent.
        $this->assertSame(
            5333.33,
            (float) $this->get('/api/contracts/sign/'.$this->sendAndFetchToken($contract))
                ->assertOk()
                ->json('data.snapshot.figures.notice_per_day'),
            0.001
        );
    }

    public function test_creating_a_second_live_contract_for_the_same_rental_is_blocked(): void
    {
        $this->issueContract();

        Sanctum::actingAs($this->owner);

        $this->postJson('/api/owner/contracts', $this->createPayload())
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_sending_raises_an_in_app_notice_and_marks_the_contract_pending(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);

        $this->assertSame('pending_signature', $contract->fresh()->status);
        $this->assertSame(64, strlen($token));
        $this->assertDatabaseHas('notifications', [
            'user_id' => $this->tenantUser->id,
            'type' => 'contract_signature',
        ]);
    }

    public function test_sending_twice_is_rejected_while_the_link_is_live(): void
    {
        $contract = $this->issueContract();
        $this->sendAndFetchToken($contract);

        Sanctum::actingAs($this->owner);
        $this->postJson("/api/owner/contracts/{$contract->id}/send")
            ->assertStatus(422);
    }

    public function test_public_view_returns_snapshot_fingerprint_and_consent_statement(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);

        $response = $this->getJson("/api/contracts/sign/{$token}")
            ->assertOk()
            ->assertJsonPath('data.state', 'awaiting_signature');

        $this->assertSame(64, strlen((string) $response->json('data.fingerprint')));
        $this->assertStringContainsString('Juma Hassan', (string) $response->json('data.consent_statement'));
        $this->assertNotNull($response->json('data.branding'));
        $this->assertNotEmpty($response->json('data.snapshot.clauses'));
    }

    public function test_signing_without_consent_is_rejected(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);
        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => false,
        ])->assertStatus(422);

        // Opening the link moves the contract to "viewed"; a rejected consent
        // must not move it any further.
        $this->assertSame('viewed', $contract->fresh()->status);
        $this->assertNull($contract->fresh()->signed_at);
    }

    public function test_signing_with_a_mismatched_name_is_rejected(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);
        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Someone Else',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => true,
        ])->assertStatus(422);

        $this->assertNull($contract->fresh()->signed_at);
    }

    public function test_signing_with_a_stale_fingerprint_is_rejected(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => str_repeat('a', 64),
            'consent' => true,
        ])->assertStatus(409);

        $this->assertNull($contract->fresh()->signed_at);
    }

    public function test_a_valid_signature_freezes_the_contract_and_stores_consent(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);
        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => true,
        ])->assertOk()->assertJsonPath('data.state', 'signed');

        $contract->refresh();
        $this->assertSame('signed', $contract->status);
        $this->assertNotNull($contract->signed_at);
        $this->assertNull($contract->signature_token);
        $this->assertSame($fingerprint, $contract->document_sha256);
        $this->assertStringContainsString('Juma Hassan', (string) $contract->signer_consent_text);
        $this->assertSame('1.0', $contract->signer_consent_version);
        $this->assertNotNull($contract->signer_consented_at);
    }

    public function test_a_spent_link_can_no_longer_be_opened(): void
    {
        $contract = $this->issueContract();
        $token = $this->sendAndFetchToken($contract);
        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => true,
        ])->assertOk();

        $this->getJson("/api/contracts/sign/{$token}")->assertNotFound();
    }

    public function test_signed_contract_cannot_be_edited(): void
    {
        $contract = Contract::factory()->signed()->create([
            'owner_id' => $this->owner->id,
            'tenant_id' => $this->tenant->id,
        ]);

        $this->expectException(LogicException::class);

        $contract->update(['rent_amount' => 1]);
    }

    public function test_a_tenant_cannot_read_another_tenants_contract(): void
    {
        $contract = $this->issueContract();
        $this->sendAndFetchToken($contract);

        $otherUser = User::factory()->tenant()->create(['must_change_password' => false]);
        Tenant::factory()->forUser($otherUser)->create(['owner_id' => $this->owner->id]);

        Sanctum::actingAs($otherUser);

        // 404, not 403: the contract's existence must not leak to another tenant.
        $this->getJson("/api/contracts/{$contract->id}")->assertNotFound();
    }

    public function test_an_owner_cannot_use_the_tenant_contract_route(): void
    {
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/contracts')->assertForbidden();
    }
}
