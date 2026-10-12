<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Request-driven tenancies start "pending" so the room is reserved but not
 * billable until the tenant signs the contract.
 *
 * rentals.status is declared as an enum. On MySQL that is a real column enum
 * and on SQLite it becomes a CHECK constraint; either way an undeclared value
 * makes the whole insert fail. The allowed list therefore has to be widened on
 * every driver that enforces it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const STATUSES = ['active', 'expiring_soon', 'expired', 'terminated', 'pending'];

    public function up(): void
    {
        $this->widen(self::STATUSES);
    }

    public function down(): void
    {
        $this->widen(array_values(array_diff(self::STATUSES, ['pending'])));
    }

    /**
     * @param  list<string>  $statuses
     */
    private function widen(array $statuses): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $values = implode("','", $statuses);
            DB::statement("ALTER TABLE `rentals` MODIFY `status` ENUM('{$values}') NOT NULL DEFAULT 'active'");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('rentals', function (Blueprint $table) use ($statuses) {
                $table->enum('status', $statuses)->default('active')->change();
            });
        }
    }
};
