<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widens the allowed values of notifications.type on MySQL.
     *
     * On PostgreSQL the column is stored as plain varchar and does not carry
     * an enum constraint, so the ALTER is a no-op there (Laravel's enum
     * ->change() grammar is MySQL-specific and Postgres cannot parse it).
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', [
                'rent_expiring',
                'rent_due',
                'rent_overdue',
                'payment_received',
                'owner_rent_expiring',
                'owner_rent_due',
                'owner_payment_received',
                'system',
            ])->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->enum('type', [
                'rent_expiring',
                'rent_due',
                'rent_overdue',
                'payment_received',
                'owner_rent_expiring',
                'owner_rent_due',
                'system',
            ])->change();
        });
    }
};
