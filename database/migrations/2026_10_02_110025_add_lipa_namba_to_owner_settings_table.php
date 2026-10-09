<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('owner_settings', function (Blueprint $table) {
            $table->string('lipa_namba', 30)->nullable()->after('payment_accounts');
            $table->string('lipa_namba_name', 120)->nullable()->after('lipa_namba');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('owner_settings', function (Blueprint $table) {
            $table->dropColumn(['lipa_namba', 'lipa_namba_name']);
        });
    }
};
