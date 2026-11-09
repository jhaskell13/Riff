<?php

namespace Database\Seeders;

use App\Models\Genre;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Reconciles the curated vocabulary in config/riff.php into the genres
 * table. Idempotent: adding a name to the config and re-running is the
 * supported way to offer a new genre. Nothing is ever deleted here, so an
 * artist cannot lose a genre because a config line was removed.
 */
class GenreSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('riff.genres', []) as $name) {
            Genre::updateOrCreate(
                ['slug' => Str::slug($name)],
                ['name' => $name, 'is_curated' => true],
            );
        }
    }
}
