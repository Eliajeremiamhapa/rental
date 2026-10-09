<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->default('UVIMO');
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('address')->nullable();
            $table->string('currency', 3)->default('TZS');

            // Mobile-money numbers shown in clause 13. Kept as owner-level settings
            // so a contract never has to be hand-edited for these, which is where
            // fraud begins: a mistyped agent number turns a rent receipt into a
            // request to pay a stranger.
            $table->json('payment_accounts')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_settings');
    }
};
