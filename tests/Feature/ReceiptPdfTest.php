<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The receipt "download" endpoint must hand back a real PDF, not the HTML
 * document it used to return. These tests generate a receipt through the real
 * payment flow and assert the binary response and its ownership guard.
 */
class ReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $tenantUser;

    private Tenant $tenant;

    private RentCharge $charge;

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

        $rental = Rental::create([
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

        $this->charge = RentCharge::factory()->create([
            'rental_id' => $rental->id,
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'expected_amount' => 160000,
        ]);
    }

    private function recordPaymentAndGetReceiptId(): int
    {
        Sanctum::actingAs($this->owner);

        return (int) $this->postJson('/api/payments', [
            'tenant_id' => $this->tenant->id,
            'rent_charge_id' => $this->charge->id,
            'amount' => 160000,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.receipt.id');
    }

    public function test_download_returns_a_pdf_document(): void
    {
        $receiptId = $this->recordPaymentAndGetReceiptId();

        $response = $this->get("/api/receipts/{$receiptId}/download");

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_a_tenant_can_download_their_own_receipt(): void
    {
        $receiptId = $this->recordPaymentAndGetReceiptId();

        Sanctum::actingAs($this->tenantUser);

        $this->get("/api/receipts/{$receiptId}/download")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_another_owner_cannot_download_a_receipt(): void
    {
        $receiptId = $this->recordPaymentAndGetReceiptId();

        $intruder = User::factory()->owner()->create(['must_change_password' => false]);
        Sanctum::actingAs($intruder);

        $this->get("/api/receipts/{$receiptId}/download")->assertNotFound();
    }
}
