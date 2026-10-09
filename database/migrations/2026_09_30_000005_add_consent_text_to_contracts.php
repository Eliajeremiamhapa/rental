<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the exact wording the tenant agreed to.
 *
 * The signature, IP, user agent and timestamp prove *that* a tenant agreed,
 * but not *to what*. Storing the rendered consent sentence closes that gap:
 * if the agreement text is ever changed, the record still shows the version
 * that was actually accepted. The version tag lets a reworded statement be
 * told apart from the original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->longText('signer_consent_text')->nullable()->after('signer_consented_at');
            $table->string('signer_consent_version', 20)->nullable()->after('signer_consent_text');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropColumn(['signer_consent_text', 'signer_consent_version']);
        });
    }
};
