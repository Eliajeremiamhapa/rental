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
        Schema::create('rentals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->decimal('rent_amount', 12, 2);
            $table->string('rent_amount_note')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->integer('period_months')->default(1);
            $table->integer('due_day');
            $table->decimal('deposit_amount', 12, 2)->nullable()->default(0);
            $table->enum('deposit_status', ['pending', 'paid', 'held', 'returned', 'deducted', 'none'])->default('pending');
            $table->text('deposit_notes')->nullable();
            $table->enum('status', ['active', 'expiring_soon', 'expired', 'terminated'])->default('active');
            $table->string('terminated_reason')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('rentals');
    }
};
