<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Rental;
use App\Models\RentCharge;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\User;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The numbers an owner acts on must be the numbers the database holds.
 *
 * Every assertion here corresponds to a way the reports previously reported
 * something untrue: future rent counted as arrears, occupancy above 100%, a
 * hand-picked date range silently dropping the months inside it, and a
 * fabricated "Expenses"/"Net" column for which no expenses table exists.
 */
class ReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private House $house;

    private Room $room;

    private Tenant $tenant;

    private Rental $rental;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->house = House::factory()->forOwner($this->owner)->create();
        $this->room = Room::factory()->forHouse($this->house)->create(['rent_amount' => 160000.00]);
        $this->tenant = Tenant::factory()->forUser(
            User::factory()->tenant()->create(['must_change_password' => false])
        )->create([
            'owner_id' => $this->owner->id,
            'full_name' => 'Juma Hassan',
        ]);
        $this->rental = Rental::factory()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $this->room->id,
            'house_id' => $this->house->id,
            'status' => 'active',
        ]);
    }

    private function service(): ReportService
    {
        return (new ReportService)->ownerId($this->owner->id);
    }

    private function charge(array $attributes = []): RentCharge
    {
        return RentCharge::factory()->create(array_merge([
            'rental_id' => $this->rental->id,
        ], $attributes));
    }

    public function test_arrears_excludes_rent_that_is_not_due_yet(): void
    {
        // Rent is charged ahead, so the next few months sit as "unpaid" long
        // before they are owed. Reporting those as arrears chased tenants for
        // money they had not yet been asked for.
        foreach ([1, 2] as $offset) {
            $this->charge([
                'period_start' => now()->addMonths($offset)->startOfMonth()->toDateString(),
                'period_end' => now()->addMonths($offset)->endOfMonth()->toDateString(),
                'due_date' => now()->addMonths($offset)->startOfMonth()->toDateString(),
                'expected_amount' => 100000,
                'paid_amount' => 0,
                'status' => 'unpaid',
            ]);
        }

        $this->assertCount(0, $this->service()->arrearsReport());
    }

    public function test_arrears_includes_partially_paid_charge_past_its_due_date(): void
    {
        $due = now()->subDays(40)->startOfMonth()->toDateString();

        $this->charge([
            'period_start' => $due,
            'period_end' => now()->subDays(40)->endOfMonth()->toDateString(),
            'due_date' => $due,
            'expected_amount' => 200000,
            'paid_amount' => 50000,
            'status' => 'partial',
        ]);

        $rows = $this->service()->arrearsReport();

        $this->assertCount(1, $rows);
        $this->assertSame(150000.0, $rows[0]['overdue_amount']);
        $this->assertSame('Juma Hassan', $rows[0]['tenant_name']);
        // Was a fixed 1 before; it has to reflect how late the payment actually is.
        $this->assertGreaterThanOrEqual(40, $rows[0]['days_overdue']);
    }

    public function test_arrears_ignores_settled_charges(): void
    {
        $due = now()->subMonth()->startOfMonth()->toDateString();

        $this->charge([
            'period_start' => $due,
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'due_date' => $due,
            'expected_amount' => 100000,
            'paid_amount' => 100000,
            'status' => 'paid',
        ]);

        $this->assertCount(0, $this->service()->arrearsReport());
    }

    public function test_reports_never_include_another_owners_data(): void
    {
        $otherOwner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);
        $otherHouse = House::factory()->forOwner($otherOwner)->create();
        $otherRoom = Room::factory()->forHouse($otherHouse)->create();
        $otherTenant = Tenant::factory()->forUser(
            User::factory()->tenant()->create(['must_change_password' => false])
        )->create([
            'owner_id' => $otherOwner->id,
        ]);
        $otherRental = Rental::factory()->create([
            'tenant_id' => $otherTenant->id,
            'room_id' => $otherRoom->id,
            'house_id' => $otherHouse->id,
        ]);

        RentCharge::factory()->create([
            'rental_id' => $otherRental->id,
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'due_date' => now()->subMonth()->startOfMonth()->toDateString(),
            'expected_amount' => 999999,
            'paid_amount' => 0,
            'status' => 'overdue',
        ]);

        $this->assertCount(0, $this->service()->arrearsReport());
        $this->assertSame(0.0, $this->service()->rentCollectionReport()['total_expected']);
        $this->assertCount(1, $this->service()->houseReport());
        $this->assertSame(0.0, (float) $this->service()->houseReport()->first()['expected_income']);
    }

    public function test_room_occupancy_rate_cannot_exceed_one_hundred_percent(): void
    {
        // A room created recently but let for longer than it has existed is
        // exactly the input that used to produce 150%.
        $room = Room::factory()->forHouse($this->house)->create([
            'created_at' => now()->subMonth(),
            'rent_amount' => 100000,
        ]);
        $rental = Rental::factory()->create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $this->house->id,
            'status' => 'active',
            'start_date' => now()->subMonths(6)->toDateString(),
        ]);

        $row = $this->service()->roomReport($room->id)->first();

        $this->assertNotNull($row);
        $this->assertLessThanOrEqual(100, $row['occupancy_rate']);
        $this->assertGreaterThanOrEqual(0, $row['occupancy_rate']);
        $this->assertLessThanOrEqual($row['total_months'], $row['occupied_months']);
        $this->assertSame($room->id, $rental->room_id);
    }

    public function test_room_occupancy_counts_historical_tenancies(): void
    {
        // A past tenancy that has since ended must still count toward the room's
        // occupancy; a report that looked only at today's live rental would miss
        // the three months of the finished tenancy.
        $room = Room::factory()->forHouse($this->house)->create(['rent_amount' => 90000]);
        $pastTenant = Tenant::factory()->forUser(
            User::factory()->tenant()->create(['must_change_password' => false])
        )->create(['owner_id' => $this->owner->id, 'full_name' => 'Asha Omar']);

        Rental::create([
            'tenant_id' => $pastTenant->id,
            'room_id' => $room->id,
            'house_id' => $this->house->id,
            'rent_amount' => 90000,
            'period_months' => 3,
            'start_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'end_date' => now()->subMonths(4)->endOfMonth()->toDateString(),
            'due_day' => 5,
            'status' => 'expired',
        ]);

        Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $this->house->id,
            'rent_amount' => 90000,
            'period_months' => 12,
            'start_date' => now()->subMonths(3)->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->endOfYear()->toDateString(),
            'due_day' => 5,
            'status' => 'active',
        ]);

        $row = $this->service()->roomReport(
            $room->id,
            now()->subMonths(6)->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        )->first();

        $this->assertSame(7, $row['total_months']);
        $this->assertSame(7, $row['occupied_months']);
        $this->assertSame(100.0, $row['occupancy_rate']);
    }

    public function test_room_occupancy_respects_the_date_window(): void
    {
        // Two months back the room was empty: the setUp tenancy starts this
        // month, so an empty month must read 0%, not the room's lifetime rate.
        $empty = $this->service()->roomReport(
            $this->room->id,
            now()->subMonths(2)->startOfMonth()->toDateString(),
            now()->subMonths(2)->endOfMonth()->toDateString(),
        )->first();

        $this->assertSame(1, $empty['total_months']);
        $this->assertSame(0, $empty['occupied_months']);
        $this->assertSame(0.0, $empty['occupancy_rate']);

        // The current month is covered by the setUp tenancy.
        $tenanted = $this->service()->roomReport(
            $this->room->id,
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        )->first();

        $this->assertSame(1, $tenanted['total_months']);
        $this->assertSame(1, $tenanted['occupied_months']);
        $this->assertSame(100.0, $tenanted['occupancy_rate']);
    }

    public function test_future_start_rental_does_not_inflate_occupancy(): void
    {
        $room = Room::factory()->forHouse($this->house)->create();

        Rental::create([
            'tenant_id' => $this->tenant->id,
            'room_id' => $room->id,
            'house_id' => $this->house->id,
            'rent_amount' => 160000,
            'period_months' => 6,
            'start_date' => now()->addMonths(2)->startOfMonth()->toDateString(),
            'end_date' => now()->addMonths(7)->endOfMonth()->toDateString(),
            'due_day' => 5,
            'status' => 'active',
        ]);

        $row = $this->service()->roomReport($room->id)->first();

        $this->assertSame(0, $row['occupied_months']);
        $this->assertSame(0.0, $row['occupancy_rate']);
        $this->assertLessThanOrEqual($row['total_months'], $row['occupied_months']);
    }

    public function test_rent_collection_date_range_includes_periods_that_overlap_it(): void
    {
        // A tenancy running 15 Aug - 14 Sep genuinely overlaps 10-20 Sep.
        $this->charge([
            'period_start' => '2026-08-15',
            'period_end' => '2026-09-14',
            'due_date' => '2026-08-15',
            'expected_amount' => 100000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        // A period entirely outside the window must stay out of it.
        $this->charge([
            'period_start' => '2026-07-01',
            'period_end' => '2026-07-31',
            'due_date' => '2026-07-01',
            'expected_amount' => 700000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $report = $this->service()->rentCollectionReport('2026-09-10', '2026-09-20');

        $this->assertSame(100000.0, $report['total_expected']);
    }

    public function test_rent_collection_totals_match_the_charges(): void
    {
        $this->charge([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-01',
            'expected_amount' => 100000,
            'paid_amount' => 100000,
            'status' => 'paid',
        ]);

        // One charge per tenancy per period, so a second room is needed to hold
        // the second month of the same house.
        $room2 = Room::factory()->forHouse($this->house)->create();
        $tenant2 = Tenant::factory()->forUser(
            User::factory()->tenant()->create(['must_change_password' => false])
        )->create(['owner_id' => $this->owner->id]);
        $rental2 = Rental::factory()->create([
            'tenant_id' => $tenant2->id,
            'room_id' => $room2->id,
            'house_id' => $this->house->id,
        ]);

        RentCharge::factory()->create([
            'rental_id' => $rental2->id,
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-01',
            'expected_amount' => 50000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $report = $this->service()->rentCollectionReport();

        $this->assertSame(150000.0, $report['total_expected']);
        $this->assertSame(100000.0, $report['total_collected']);
        $this->assertSame(50000.0, $report['total_outstanding']);
        $this->assertSame(1, $report['rows']->count());
        $this->assertSame('2026-08', $report['rows']->first()['period']);
    }

    public function test_monthly_report_does_not_invent_expenses_or_net(): void
    {
        $this->charge([
            'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
            'due_date' => now()->subMonths(2)->startOfMonth()->toDateString(),
            'expected_amount' => 100000,
            'paid_amount' => 100000,
            'status' => 'paid',
        ]);

        $report = $this->service()->monthlyReport();

        // There is no expenses table, so these keys must not exist: a Net equal
        // to Income reports perfect profitability for every month forever.
        $this->assertArrayNotHasKey('total_expenses', $report);
        $this->assertArrayNotHasKey('total_net', $report);

        $row = $report['rows']->first();
        $this->assertArrayNotHasKey('expenses', $row);
        $this->assertArrayNotHasKey('net', $row);
        $this->assertSame(100000.0, $row['income']);
        $this->assertSame(100000.0, $row['expected']);
        $this->assertSame(100.0, $row['charge_paid_rate']);
    }

    public function test_monthly_report_defaults_to_a_twelve_month_window(): void
    {
        $this->charge([
            'period_start' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'period_end' => now()->subMonths(6)->endOfMonth()->toDateString(),
            'due_date' => now()->subMonths(6)->startOfMonth()->toDateString(),
            'expected_amount' => 100000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $months = $this->service()->monthlyReport()['rows']->pluck('month')->all();

        // The old default was the current month only, which dropped this row.
        $this->assertContains(now()->subMonths(6)->format('Y-m'), $months);
    }

    public function test_charge_paid_rate_is_a_share_of_settled_charges(): void
    {
        foreach ([1, 2] as $offset) {
            $this->charge([
                'period_start' => now()->subMonths($offset)->startOfMonth()->toDateString(),
                'period_end' => now()->subMonths($offset)->endOfMonth()->toDateString(),
                'due_date' => now()->subMonths($offset)->startOfMonth()->toDateString(),
                'expected_amount' => 100000,
                'paid_amount' => $offset === 1 ? 100000 : 0,
                'status' => $offset === 1 ? 'paid' : 'unpaid',
            ]);
        }

        $report = $this->service()->monthlyReport();
        $this->assertSame(100000.0, $report['total_income']);
        $this->assertSame(200000.0, $report['total_expected']);

        $rates = $report['rows']->pluck('charge_paid_rate', 'month')->all();
        $this->assertSame(100.0, $rates[now()->subMonth()->format('Y-m')]);
        $this->assertSame(0.0, $rates[now()->subMonths(2)->format('Y-m')]);
    }

    public function test_tenant_balance_matches_charged_minus_paid(): void
    {
        $this->charge([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-01',
            'expected_amount' => 300000,
            'paid_amount' => 120000,
            'status' => 'partial',
        ]);

        $row = $this->service()->tenantReport($this->tenant->id)->first();

        $this->assertSame(180000.0, $row['balance']);
        $this->assertSame($this->room->name, $row['room_name']);
        $this->assertSame($this->house->name, $row['house_name']);
    }

    public function test_house_income_endpoint_applies_the_date_window(): void
    {
        Sanctum::actingAs($this->owner);

        $this->charge([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-01',
            'expected_amount' => 100000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);
        $this->charge([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-01',
            'expected_amount' => 400000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        // The report screen offers a date range for this report; it used to be
        // ignored entirely and always returned every month.
        $response = $this->getJson('/api/owner/reports/house-income?from_date=2026-09-01&to_date=2026-09-30');

        $response->assertOk();
        $this->assertSame(400000.0, (float) $response->json('data.0.expected_income'));
    }

    public function test_room_performance_endpoint_applies_the_date_window(): void
    {
        Sanctum::actingAs($this->owner);

        $this->charge([
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'due_date' => '2026-08-01',
            'expected_amount' => 100000,
            'paid_amount' => 90000,
            'status' => 'partial',
        ]);

        $response = $this->getJson('/api/owner/reports/room-performance?room_id='.$this->room->id.'&from_date=2026-09-01&to_date=2026-09-30');

        $response->assertOk();
        $this->assertSame(0.0, (float) $response->json('data.0.total_revenue'));
    }

    public function test_monthly_report_endpoint_summary_matches_rows(): void
    {
        Sanctum::actingAs($this->owner);

        $this->charge([
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'due_date' => '2026-09-01',
            'expected_amount' => 250000,
            'paid_amount' => 250000,
            'status' => 'paid',
        ]);

        $response = $this->getJson('/api/owner/reports/monthly?from_date=2026-09-01&to_date=2026-09-30');

        $response->assertOk();
        $this->assertSame(250000.0, (float) $response->json('summary.total_income'));
        $this->assertSame(0.0, (float) $response->json('summary.total_outstanding'));
        $this->assertNull($response->json('summary.total_expenses'));
        $this->assertNull($response->json('summary.total_net'));
    }

    public function test_arrears_endpoint_omits_future_charges(): void
    {
        Sanctum::actingAs($this->owner);

        $this->charge([
            'period_start' => now()->addMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->addMonth()->endOfMonth()->toDateString(),
            'due_date' => now()->addMonth()->startOfMonth()->toDateString(),
            'expected_amount' => 100000,
            'paid_amount' => 0,
            'status' => 'unpaid',
        ]);

        $this->getJson('/api/owner/reports/arrears')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
