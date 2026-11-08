<?php

namespace App\Console\Commands;

use App\Jobs\GeocodeAddress;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Console\Command;

class GeocodeMissingCoordinates extends Command
{
    protected $signature = 'riff:geocode-missing';

    protected $description = 'Queue geocoding for every artist and user without stored coordinates';

    public function handle(): int
    {
        $queued = 0;

        foreach ([Artist::class, User::class] as $model) {
            $model::query()
                ->where(fn ($query) => $query->whereNull('lat')->orWhereNull('lng'))
                ->chunkById(100, function ($records) use (&$queued) {
                    foreach ($records as $record) {
                        GeocodeAddress::dispatch($record);
                        $queued++;
                    }
                });
        }

        $this->info("Queued {$queued} geocoding jobs.");

        return self::SUCCESS;
    }
}
