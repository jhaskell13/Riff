<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Following an artist is the first commitment a listener makes after a
     * discovery. The unique pair makes a repeated follow idempotent; counts
     * are read with withCount('followers') rather than a cached column, so
     * no followers_count column exists to shadow the relation.
     */
    public function up(): void
    {
        Schema::create('artist_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'artist_id']);
            $table->index('artist_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_follows');
    }
};
