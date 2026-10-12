<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Notices around the rental-request flow need their own notification category.
 *
 * notifications.type is declared as an enum. On MySQL that is a real column
 * enum and on SQLite it becomes a CHECK constraint; either way an undeclared
 * value makes the whole insert fail. The allowed list therefore has to be
 * widened on every driver that enforces it.
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
        'system',
    ];

    public function up(): void
    {
        $this->widen(self::TYPES);
    }

    public function down(): void
    {
        $this->widen(array_values(array_diff(self::TYPES, [
            'rental_request_received',
            'rental_request_accepted',
            'rental_request_declined',
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
