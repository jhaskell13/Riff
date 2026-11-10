<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Report;
use Database\Seeders\PilotSeeder;
use Database\Seeders\Support\PlaceholderPhoto;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Drops the pilot scene into an existing database without a full rebuild.
 * Refuses to run in production, where invented profiles must never appear.
 */
class SeedPilotArea extends Command
{
    protected $signature = 'riff:seed-pilot {--force : Run even in production}';

    protected $description = 'Seed the Greater Hartford pilot scene (invented artists, for demos and development)';

    public function handle(): int
    {
        if ($this->getLaravel()->environment('production') && ! $this->option('force')) {
            $this->error('Refusing to seed invented artists into production. Pass --force if you really mean it.');

            return self::FAILURE;
        }

        $before = ['artists' => Artist::withTrashed()->count(), 'reports' => Report::count()];

        $this->call('db:seed', ['--class' => PilotSeeder::class, '--force' => true]);

        $added = Artist::withTrashed()->count() - $before['artists'];

        $this->info($added === 0
            ? 'The pilot scene is already here; nothing was added.'
            : "Added {$added} pilot artists and ".(Report::count() - $before['reports']).' reports.');

        $this->notes();

        return self::SUCCESS;
    }

    /** What the seeded data still needs from the environment to look right. */
    private function notes(): void
    {
        if (! PlaceholderPhoto::available()) {
            $this->warn('The gd extension is missing, so seeded artists have no cover art.');
        }

        if (config('riff.media.disk') === 'public' && ! File::exists(public_path('storage'))) {
            $this->warn('public/storage is missing, so seeded cover art will 404. Run: php artisan storage:link');
        }

        $this->line('Sign in as test@example.com / password to see the map, the Artist Zone, and the report queue.');
    }
}
