<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            // Nullable at the schema level so the backfill below can run on
            // every driver; the model fills it on creating, so in practice
            // it is always set.
            $table->string('slug')->nullable()->unique()->after('name');
        });

        // Slugs an artist used before a rename. Each row keeps an old
        // public URL working: /artists/{old-slug} resolves here and
        // redirects to the current slug.
        Schema::create('artist_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamps();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_slugs');

        Schema::table('artists', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn('slug');
        });
    }

    /**
     * Mirrors App\Support\ArtistSlugs::generate() deliberately inline:
     * migrations must not depend on app code that may change after this
     * migration has already run somewhere.
     */
    private function backfill(): void
    {
        $taken = [];

        DB::table('artists')
            ->orderBy('id')
            ->select(['id', 'name', 'artist_type'])
            ->chunkById(100, function ($artists) use (&$taken) {
                foreach ($artists as $artist) {
                    $base = Str::slug($artist->name);

                    if ($base === '' || ctype_digit($base)) {
                        $suffix = Str::slug((string) $artist->artist_type) ?: 'artist';
                        $base = trim($base.'-'.$suffix, '-');
                    }

                    $slug = $base;
                    $n = 2;

                    while (isset($taken[$slug])) {
                        $slug = $base.'-'.$n;
                        $n++;
                    }

                    $taken[$slug] = true;

                    DB::table('artists')->where('id', $artist->id)->update(['slug' => $slug]);
                }
            });
    }
};
