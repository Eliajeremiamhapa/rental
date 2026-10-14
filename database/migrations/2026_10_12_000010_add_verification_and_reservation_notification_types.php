<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * New notification categories for owner verification and the reservation
 * window. notifications.type is a real enum on MySQL and a CHECK constraint on
 * SQLite, so an undeclared value makes the insert fail on both: the allowed
 * list is widened on every driver that enforces it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TYPES = [
        'rent_expiring',
        'rent_due',
        'rent_overdue',
        'payment_received',
        'owner_rent_expiring',
        'owner_rent_due',
        'owner_rent_overdue',
        'owner_payment_received',
        'contract_for_signature',
        'contract_signature',
        'rental_request_received',
        'rental_request_accepted',
        'rental_request_declined',
        'owner_verified',
        'owner_rejected',
        'reservation_expiring',
        'reservation_released',
        'visit_confirmed',
        'system',
    ];

    public function up(): void
    {
        $this->widen(self::TYPES);
    }

    public function down(): void
    {
        $this->widen(array_values(array_diff(self::TYPES, [
            'owner_verified',
            'owner_rejected',
            'reservation_expiring',
            'reservation_released',
            'visit_confirmed',
        ])));
    }

    /**
     * @param  list<string>  $types
     */
    private function widen(array $types): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $values = implode("','", $types);
            DB::statement("ALTER TABLE `notifications` MODIFY `type` ENUM('{$values}') NOT NULL");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('notifications', function (Blueprint $table) use ($types) {
                $table->enum('type', $types)->change();
            });
        }
    }
};
