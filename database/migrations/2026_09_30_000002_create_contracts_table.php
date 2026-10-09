<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->id();
            $table->string('contract_number')->unique();

            $table->foreignId('rental_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();

            $table->enum('status', [
                'draft',
                'pending_signature',
                'viewed',
                'signed',
                'declined',
                'void',
            ])->default('draft');

            // Frozen when the contract is issued so later edits to the shared
            // template or the rental record can never rewrite what was agreed.
            $table->string('template_version')->default('1.0');
            $table->string('language', 5)->default('sw');

            // Owner-editable content. "description" is the owner's own additional
            // terms; "clause_overrides" maps a clause key to the owner's wording
            // while "clause_text" keeps the untouched standard text for the same
            // key, so a negotiated-away clause is always visible afterwards.
            $table->longText('description')->nullable();
            $table->json('clause_overrides')->nullable();
            $table->json('clause_text')->nullable();
            $table->json('extra_clauses')->nullable();

            // Fully rendered document at issue time. This is what gets hashed and
            // signed; nothing is re-rendered afterwards.
            $table->longText('content_snapshot')->nullable();

            // Money and period, copied from the rental so the letter cannot drift
            // away from the tenancy it was issued against.
            $table->decimal('rent_amount', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('daily_rate', 12, 2);
            $table->unsignedInteger('period_months');
            $table->unsignedInteger('notice_months')->nullable();
            $table->decimal('notice_per_day', 12, 2)->default(0);
            $table->decimal('service_fee', 12, 2)->default(0);
            $table->unsignedTinyInteger('due_day')->default(25);
            $table->unsignedTinyInteger('payment_commitment_months')->nullable();
            $table->unsignedTinyInteger('short_commitment_months')->nullable();
            $table->string('currency', 3)->default('TZS');
            $table->date('start_date');
            $table->date('end_date');
            $table->decimal('deposit_amount', 12, 2)->default(0);

            // Single-use signing invitation. "contract appears only for the first
            // time" is enforced here: one token, cleared the moment it is signed.
            $table->string('signature_token', 80)->nullable()->unique();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            $table->string('signer_name')->nullable();
            $table->string('signer_phone')->nullable();
            $table->string('signer_ip', 45)->nullable();
            $table->string('signer_user_agent', 512)->nullable();
            $table->timestamp('signer_consented_at')->nullable();
            $table->longText('signature_image')->nullable();
            $table->string('document_sha256', 64)->nullable();
            $table->timestamp('signed_at')->nullable();

            $table->timestamp('owner_signed_at')->nullable();
            $table->longText('owner_signature_image')->nullable();

            $table->text('declined_reason')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->text('voided_reason')->nullable();
            $table->timestamp('voided_at')->nullable();

            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contracts');
    }
};
