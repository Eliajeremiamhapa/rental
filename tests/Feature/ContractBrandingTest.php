<?php

namespace Tests\Feature;

use App\Http\Concerns\ResolvesBrandingAssets;
use App\Models\Contract;
use App\Models\House;
use App\Models\Rental;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Branding is shared between receipts and contracts, and the two signature
 * slots must never be confused: the landlord side always uses the one company
 * stamp, the tenant side always uses the tenant's own captured signature.
 */
class ContractBrandingTest extends TestCase
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

        $this->owner = User::factory()->owner()->create(['must_change_password' => false]);
        $house = House::factory()->forOwner($this->owner)->create();
        $room = Room::factory()->forHouse($house)->create(['rent_amount' => 160000]);

        $this->tenantUser = User::factory()->tenant()->create(['must_change_password' => false]);
        $this->tenant = Tenant::factory()->forUser($this->tenantUser)->create([
            'owner_id' => $this->owner->id,
            'full_name' => 'Juma Hassan',
        ]);

        $this->rental = Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $house->id,
            'rent_amount' => 160000,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'period_months' => 12,
            'due_day' => 5,
            'deposit_amount' => 160000,
            'deposit_status' => 'pending',
            'status' => 'active',
        ]);
    }

    private function issueAndSend(): Contract
    {
        Sanctum::actingAs($this->owner);

        $id = $this->postJson('/api/owner/contracts', [
            'rental_id' => $this->rental->id,
            'rent_amount' => 160000,
            'period_months' => 12,
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'due_day' => 5,
            'deposit_amount' => 160000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/owner/contracts/{$id}/send")->assertOk();

        return Contract::findOrFail($id);
    }

    public function test_asset_url_resolution_across_input_shapes(): void
    {
        $resolver = $this->resolver();

        $this->assertNull($resolver->url(null));
        $this->assertNull($resolver->url(''));

        $this->assertSame(
            'https://cdn.example.com/logo.png',
            $resolver->url('https://cdn.example.com/logo.png')
        );

        // Absolute filesystem paths are not browser-reachable.
        $this->assertNull($resolver->url('/var/www/branding/logo.png'));
        $this->assertNull($resolver->url('C:\\branding\\logo.png'));

        // Real files under public/ become root-relative URLs.
        $this->assertSame('/branding/logo.png', $resolver->url('branding/logo.png'));
        $this->assertSame('/branding/logo.png', $resolver->url('public/branding/logo.png'));
        // A leading slash is read as an absolute filesystem path, so it is not
        // a browser URL. Public files must be configured without it.
        $this->assertNull($resolver->url('/branding/signature.png'));
        $this->assertSame('/branding/signature.png', $resolver->url('branding/signature.png'));

        // Missing files resolve to null rather than a broken image.
        $this->assertNull($resolver->url('branding/does-not-exist.png'));
    }

    public function test_branding_payload_uses_configured_shared_assets(): void
    {
        config()->set('services.receipt.company_name', 'Uvimo');
        config()->set('services.receipt.logo_path', 'branding/logo.png');
        config()->set('services.receipt.signature_path', 'branding/signature.png');

        $payload = $this->resolver()->payload();

        $this->assertSame('Uvimo', $payload['company_name']);
        $this->assertSame('/branding/logo.png', $payload['logo_url']);
        $this->assertSame('/branding/signature.png', $payload['signature_url']);
        // No owner logo, so there is no server-side mark to hand back: the
        // client falls back to its bundled symbol.
        $this->assertNull($payload['logo_mark_url']);
    }

    public function test_platform_branding_has_no_mark_url(): void
    {
        $data = $this->getJson('/api/branding')->assertOk()->json('data');

        $this->assertArrayHasKey('logo_mark_url', $data);
        $this->assertNull($data['logo_mark_url']);
    }

    public function test_owner_uploaded_logo_is_also_returned_as_the_mark(): void
    {
        $this->owner->settings()->create(['logo_path' => 'branding/logo.png']);

        $data = $this->getJson('/api/branding?owner_id='.$this->owner->id)
            ->assertOk()
            ->json('data');

        $this->assertSame('/branding/logo.png', $data['logo_url']);
        $this->assertSame('/branding/logo.png', $data['logo_mark_url']);
    }

    public function test_public_owner_lookup_returns_logo_but_never_signature(): void
    {
        // No auth token: this is the anonymous marketplace lookup.
        $data = $this->getJson('/api/branding?owner_id='.$this->owner->id)
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['logo_url']);
        $this->assertArrayNotHasKey('signature_url', $data);
    }

    public function test_tenant_sees_their_landlord_branding(): void
    {
        $this->owner->settings()->create(['company_name' => 'Landlord Co']);

        Sanctum::actingAs($this->tenantUser);

        $data = $this->getJson('/api/branding/me')->assertOk()->json('data');

        $this->assertSame('Landlord Co', $data['company_name']);
        $this->assertArrayHasKey('signature_url', $data);
    }

    public function test_public_branding_stays_platform_branded_for_a_signed_in_user(): void
    {
        config()->set('services.receipt.company_name', 'Platform Co');
        $this->owner->settings()->create(['company_name' => 'Landlord Co']);

        Sanctum::actingAs($this->tenantUser);

        // The unscoped public endpoint must never leak the landlord's brand
        // onto the platform (system owner) pages.
        $data = $this->getJson('/api/branding')->assertOk()->json('data');

        $this->assertSame('Platform Co', $data['company_name']);
    }

    public function test_owner_sees_their_own_branding_on_me(): void
    {
        $this->owner->settings()->create(['company_name' => 'Landlord Co']);

        Sanctum::actingAs($this->owner);

        $data = $this->getJson('/api/branding/me')->assertOk()->json('data');

        $this->assertSame('Landlord Co', $data['company_name']);
    }

    public function test_public_signing_view_carries_branding(): void
    {
        $contract = $this->issueAndSend();
        $token = (string) $contract->fresh()->signature_token;

        $data = $this->getJson("/api/contracts/sign/{$token}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['branding']['logo_url']);
        $this->assertNotEmpty($data['branding']['signature_url']);
    }

    public function test_owner_detail_carries_branding_and_never_a_tenant_signature_before_signing(): void
    {
        $contract = $this->issueAndSend();

        Sanctum::actingAs($this->owner);

        $data = $this->getJson("/api/owner/contracts/{$contract->id}")
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['branding']['logo_url']);
        $this->assertArrayHasKey('signature_url', $data['branding']);
    }

    public function test_a_signed_contract_separates_the_tenant_signature_from_the_shared_owner_stamp(): void
    {
        $contract = $this->issueAndSend();
        $token = (string) $contract->fresh()->signature_token;
        $fingerprint = (string) $this->getJson("/api/contracts/sign/{$token}")->json('data.fingerprint');

        $this->postJson("/api/contracts/sign/{$token}", [
            'signer_name' => 'Juma Hassan',
            'signature_image' => self::TINY_PNG,
            'fingerprint' => $fingerprint,
            'consent' => true,
        ])->assertOk();

        $contract->refresh();

        // The tenant's captured signature is stored on the contract itself.
        $this->assertSame(self::TINY_PNG, $contract->signature_image);

        // The owner's slot stays empty on the record; the landlord signature
        // comes from the shared company stamp at render time.
        $this->assertNull($contract->owner_signature_image);

        $signed = $this->getJson("/api/contracts/sign/{$token}");
        $signed->assertNotFound(); // token spent

        Sanctum::actingAs($this->tenantUser);
        $tenantView = $this->getJson("/api/contracts/{$contract->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(self::TINY_PNG, $tenantView['signature_image']);
        $this->assertNotEmpty($tenantView['branding']['signature_url']);
        $this->assertNotSame($tenantView['signature_image'], $tenantView['branding']['signature_url']);
    }

    /**
     * Expose the protected trait methods for direct testing.
     */
    private function resolver(): object
    {
        return new class
        {
            use ResolvesBrandingAssets;

            public function url(?string $path): ?string
            {
                return $this->assetUrl($path);
            }

            /** @return array<string, mixed> */
            public function payload(): array
            {
                return $this->brandingPayload();
            }
        };
    }
}
