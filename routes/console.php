<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Scene places: GeocodeAddress adds a town when its first artist lands, so
// this only catches what that misses (private venues, one-off shows, imports)
// and re-centres towns. Cheap and idempotent. Needs `schedule:run` in cron.
Schedule::command('riff:sync-places')->daily();
