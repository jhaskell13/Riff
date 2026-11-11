<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 1 artist profile: the fields a listener needs to decide whether
     * to stop on a map pin -- what kind of act this is, where to hear them,
     * and a photo. image_url becomes a derived accessor over image_path so
     * the stored value is a disk path we control, never a client-supplied
     * remote URL.
     */
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('artist_type')->default('band')->after('name');

            // Uploaded photo, relative to the configured media disk.
            $table->string('image_path')->nullable()->after('email');

            // Off-site presence. Each is validated against its own host.
            $table->string('bandcamp_url')->nullable()->after('website');
            $table->string('spotify_url')->nullable()->after('bandcamp_url');
            $table->string('soundcloud_url')->nullable()->after('spotify_url');
            $table->string('youtube_url')->nullable()->after('soundcloud_url');
            $table->string('instagram_url')->nullable()->after('youtube_url');

            // The one playable thing on the profile. music_provider is set
            // from music_url by the model, never by the client, and doubles
            // as the "has playable music" flag.
            $table->string('music_url')->nullable();
            $table->string('music_provider')->nullable()->index();

            // An artist can keep a public profile but leave the map.
            $table->boolean('show_on_map')->default(true);

            $table->index('artist_type');
            $table->index(['show_on_map', 'lat', 'lng']);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('image_url')->nullable();
        });

        // Every index must go before its columns: SQLite rebuilds the table
        // on drop column and refuses to leave an index behind pointing at a
        // column that no longer exists.
        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['artist_type']);
            $table->dropIndex(['music_provider']);
            $table->dropIndex(['show_on_map', 'lat', 'lng']);
        });

        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn([
                'artist_type',
                'image_path',
                'bandcamp_url',
                'spotify_url',
                'soundcloud_url',
                'youtube_url',
                'instagram_url',
                'music_url',
                'music_provider',
                'show_on_map',
            ]);
        });
    }
};
