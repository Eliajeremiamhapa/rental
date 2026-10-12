<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            // Polymorphic owner so a single table backs both houses and rooms.
            $table->morphs('mediable');

            // 'image' holds an uploaded file (path); 'video' holds either an
            // uploaded file (path) or an external embed link (url).
            $table->string('type', 16)->default('image');
            $table->string('path')->nullable();
            $table->string('url', 2048)->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['mediable_type', 'mediable_id', 'sort_order'], 'media_owner_sort_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
