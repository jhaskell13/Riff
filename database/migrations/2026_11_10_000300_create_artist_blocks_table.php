<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artists a listener does not want to see; shaped like artist_follows.
     * A block changes only the blocker's own map and feed -- reporting is
     * the channel for "this should not be here at all".
     */
    public function up(): void
    {
        Schema::create('artist_blocks', function (Blueprint $table) {
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
        Schema::dropIfExists('artist_blocks');
    }
};
