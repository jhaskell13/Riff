<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artists a listener does not want to see. The mirror image of
     * artist_follows, and shaped like it: the unique pair makes blocking
     * idempotent, and the artist_id index serves the map's exclusion
     * subquery.
     *
     * A block is one listener's own view of Riff, not a judgement on the
     * artist -- the pin stays on everyone else's map. Reporting is the
     * channel for "this should not be here at all".
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
