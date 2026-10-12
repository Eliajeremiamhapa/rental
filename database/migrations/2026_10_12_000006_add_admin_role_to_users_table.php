<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a system "admin" role. Platform staff accounts are provisioned out of
 * band (AdminSeeder), never through public registration.
 *
 * users.role is declared as an enum. On MySQL that is a real column enum and on
 * SQLite it becomes a CHECK constraint; either way an undeclared value makes the
 * insert fail, so the allowed list is widened on every driver that enforces it.
 *
 * previous_role remembers what a user was before admin was granted, so revoking
 * admin restores their original role instead of guessing.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const ROLES = ['owner', 'tenant', 'admin'];

    public function up(): void
    {
        $this->widen(self::ROLES);

        if (! Schema::hasColumn('users', 'previous_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('previous_role')->nullable()->after('role');
            });
        }
    }

    public function down(): void
    {
        // An admin has no meaning once the role value is removed, so demote any
        // remaining admins to owner before narrowing the constraint.
        DB::table('users')->where('role', 'admin')->update(['role' => 'owner']);

        $this->widen(['owner', 'tenant']);

        if (Schema::hasColumn('users', 'previous_role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('previous_role');
            });
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function widen(array $roles): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            $values = implode("','", $roles);
            DB::statement("ALTER TABLE `users` MODIFY `role` ENUM('{$values}') NOT NULL DEFAULT 'tenant'");

            return;
        }

        if ($driver === 'sqlite') {
            Schema::table('users', function (Blueprint $table) use ($roles) {
                $table->enum('role', $roles)->default('tenant')->change();
            });
        }
    }
};
