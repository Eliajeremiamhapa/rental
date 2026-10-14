<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feedback', function (Blueprint $table) {
            $table->id();
            // The submitter and a snapshot of the role they held when submitting,
            // so the inbox still reads correctly if their account later changes.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->enum('category', [
                'bug',
                'feature_request',
                'suggestion',
                'compliment',
                'complaint',
                'other',
            ]);
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('message');
            $table->enum('status', ['new', 'reviewed', 'archived'])->default('new');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['role']);
            $table->index(['category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feedback');
    }
};
