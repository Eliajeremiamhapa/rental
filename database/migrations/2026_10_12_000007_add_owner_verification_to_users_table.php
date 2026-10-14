<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owners must be approved by a system administrator before they can list or
 * manage anything. The status is a separate axis from is_active: is_active
 * means "suspended", verification_status means "has an admin vouched for you
 * yet". Tenants and admins leave it null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('verification_status', ['unsubmitted', 'pending', 'approved', 'rejected'])
                ->nullable()
                ->after('role');
            $table->timestamp('verified_at')->nullable()->after('verification_status');
            $table->unsignedBigInteger('verified_by')->nullable()->after('verified_at');
            $table->text('verification_note')->nullable()->after('verified_by');
        });

        // Grandfather every owner that already exists, so shipping the gate
        // cannot lock out landlords who were listing before verification.
        DB::table('users')->where('role', 'owner')->update(['verification_status' => 'approved']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['verification_status', 'verified_at', 'verified_by', 'verification_note']);
        });
    }
};
