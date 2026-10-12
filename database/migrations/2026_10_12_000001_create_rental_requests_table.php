<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_id')->constrained()->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->text('message')->nullable();
            $table->date('desired_start_date')->nullable();
            $table->unsignedInteger('period_months')->default(12);
            $table->enum('status', ['pending', 'accepted', 'declined', 'cancelled'])->default('pending');
            $table->string('response_message')->nullable();
            $table->timestamp('response_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_requests');
    }
};
