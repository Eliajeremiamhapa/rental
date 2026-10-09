<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contract notices need their own notification category.
 *
 * notifications.type is declared as an enum. On MySQL that is a real column
 * enum and on SQLite it becomes a CHECK constraint; either way an undeclared
 * value makes the whole insert fail, which would mean an owner issues a
 * contract and the tenant is never told. The allowed list therefore has to be
 * widened on every driver that enforces it.
 *
 * PostgreSQL stores the column as free text (see the drop-check migration), so
 * there is nothing to alter there.
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
        'owner_payment_received',
        'system',
        'contract_signature',
    ];

    public function up(): void
    {
        $this->widen(self::TYPES);
    }

    public function down(): void
    {
        $this->widen(array_values(array_diff(self::TYPES, ['contract_signature'])));
    }

    /**
     * @param  list<string>  $types
     */
    private function widen(array $types): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // A raw ALTER is the exact statement MySQL needs and avoids the
            // enum ->change() grammar differences between Laravel versions.
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
