<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Genres move from a free-text column to a curated vocabulary with a
     * pivot, so the map's genre filter is an indexed join instead of a LIKE
     * over whatever artists typed. is_curated/created_by leave room for
     * user-created tags later without another schema change.
     */
    public function up(): void
    {
        Schema::create('genres', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_curated')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('artist_genre', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->unique(['artist_id', 'genre_id']);
            $table->index('genre_id');
        });

        $this->seedCuratedGenres();
        $this->migrateExistingGenres();

        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['genre']);
            $table->dropColumn('genre');
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('genre')->nullable()->index();
        });

        // Restore the single free-text genre from the pivot, keeping the
        // lowest-id genre so the column is deterministic.
        $rows = DB::table('artist_genre')
            ->join('genres', 'genres.id', '=', 'artist_genre.genre_id')
            ->orderBy('artist_genre.genre_id')
            ->get(['artist_genre.artist_id', 'genres.name']);

        foreach ($rows->groupBy('artist_id') as $artistId => $group) {
            DB::table('artists')->where('id', $artistId)->update(['genre' => $group->first()->name]);
        }

        Schema::dropIfExists('artist_genre');
        Schema::dropIfExists('genres');
    }

    private function seedCuratedGenres(): void
    {
        $now = now();

        foreach (config('riff.genres', []) as $name) {
            DB::table('genres')->insert([
                'name' => $name,
                'slug' => Str::slug($name),
                'is_curated' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Carry every existing artist's genre onto the pivot. Values that do not
     * match the curated vocabulary are kept as uncurated genres rather than
     * dropped -- losing an artist's own description of their music would be
     * worse than a slightly untidy list.
     */
    private function migrateExistingGenres(): void
    {
        $slugs = DB::table('genres')->pluck('id', 'slug');
        $now = now();

        DB::table('artists')
            ->whereNotNull('genre')
            ->where('genre', '!=', '')
            ->orderBy('id')
            ->select(['id', 'genre'])
            ->chunk(500, function ($artists) use (&$slugs, $now) {
                $links = [];

                foreach ($artists as $artist) {
                    $slug = Str::slug($artist->genre);

                    if ($slug === '') {
                        continue;
                    }

                    if (! isset($slugs[$slug])) {
                        $slugs[$slug] = DB::table('genres')->insertGetId([
                            'name' => $artist->genre,
                            'slug' => $slug,
                            'is_curated' => false,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    $links[] = ['artist_id' => $artist->id, 'genre_id' => $slugs[$slug]];
                }

                if ($links !== []) {
                    DB::table('artist_genre')->insert($links);
                }
            });
    }
};
