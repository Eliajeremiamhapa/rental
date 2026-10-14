<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_reports', function (Blueprint $table) {
            $table->id();
            // The tenant who filed the report and the owner they reported.
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            // Optional context: which house/tenancy the report relates to.
            $table->foreignId('house_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('category', [
                'scam',
                'harassment',
                'misrepresentation',
                'deposit_dispute',
                'maintenance_neglect',
                'unfair_terms',
                'other',
            ]);
            $table->string('subject', 150);
            $table->text('description');
            $table->enum('status', ['submitted', 'in_review', 'resolved', 'dismissed'])->default('submitted');
            // Outcome note written by an administrator; shown back to the reporter.
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
            $table->index(['reporter_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_reports');
    }
};
