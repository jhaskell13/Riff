<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Covering indexes for the map API, replacing the Phase 1 originals.
     *
     * artists_map_index adds deleted_at so the soft-delete check is an
     * equality prefix alongside show_on_map; the viewport is then a range on
     * lat with lng read straight from the index. Counting and clustering an
     * area become index-only scans: on 50,000 artists, ~53 ms -> ~5 ms for
     * the count and ~176 ms -> ~27 ms for the clusters.
     *
     * artist_genre (genre_id, artist_id) answers "which artists carry any of
     * these genres?" without touching the pivot rows.
     *
     * Each new index is created before the old one is dropped: on MySQL,
     * artist_genre.genre_id's foreign key needs an index at every moment,
     * and the composite takes over that role.
     */
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->index(['show_on_map', 'deleted_at', 'lat', 'lng'], 'artists_map_index');
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['show_on_map', 'lat', 'lng']);
        });

        Schema::table('artist_genre', function (Blueprint $table) {
            $table->index(['genre_id', 'artist_id']);
        });

        Schema::table('artist_genre', function (Blueprint $table) {
            $table->dropIndex(['genre_id']);
        });
    }

    public function down(): void
    {
        Schema::table('artist_genre', function (Blueprint $table) {
            $table->index('genre_id');
        });

        Schema::table('artist_genre', function (Blueprint $table) {
            $table->dropIndex(['genre_id', 'artist_id']);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->index(['show_on_map', 'lat', 'lng']);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex('artists_map_index');
        });
    }
};
