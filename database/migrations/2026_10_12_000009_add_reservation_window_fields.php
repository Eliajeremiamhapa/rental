<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A first-time tenant gets a due-diligence window: an accepted request holds the
 * room for a few days so the tenant (or a trusted agent) can confirm the room
 * exists and the owner is genuine. reserved_until is the deadline; a scheduled
 * job releases the room if nothing progresses. The rental records whether the
 * window applied and when the tenant confirmed they were satisfied.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->timestamp('reserved_until')->nullable()->after('status');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->boolean('due_diligence_required')->default(false)->after('status');
            $table->timestamp('visit_confirmed_at')->nullable()->after('due_diligence_required');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('reserved_until');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['due_diligence_required', 'visit_confirmed_at']);
        });
    }
};
