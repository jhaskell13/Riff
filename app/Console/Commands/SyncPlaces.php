<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Place;
use App\Models\Show;
use App\Models\Venue;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Bring the places table in line with where the scene actually is: every
 * town with an artist on the map, a private venue, or a one-off show gets
 * a scene page, centred on the coordinates already stored for it. Those
 * are all town-level (a public venue's street-level point is skipped), and
 * nothing is geocoded. Idempotent; rows are never deleted.
 */
class SyncPlaces extends Command
{
    protected $signature = 'riff:sync-places';

    protected $description = 'Create or refresh scene places from towns that have artists, venues, or one-off shows';

    public function handle(): int
    {
        $towns = $this->towns(
            Artist::query()->geolocated()->toBase()
                // Aliased through the builder so each driver quotes the
                // camel-cased column itself (raw double quotes are a string
                // literal on MySQL).
                ->select(['hometown as city', 'stateOrProvince as region', 'country'])
                ->selectRaw('avg(lat) as lat, avg(lng) as lng')
                ->groupBy('hometown', 'stateOrProvince', 'country')->get(),
        )
            ->merge($this->towns(
                Venue::query()->where('address_visibility', Venue::VISIBILITY_PRIVATE)->whereNotNull('lat')->toBase()
                    ->select(['city', 'state as region', 'country'])
                    ->selectRaw('avg(lat) as lat, avg(lng) as lng')
                    ->groupBy('city', 'state', 'country')->get(),
            ))
            ->merge($this->towns(
                Show::query()->whereNull('venue_id')->whereNotNull('lat')->toBase()
                    ->select(['city', 'state as region', 'country'])
                    ->selectRaw('avg(lat) as lat, avg(lng) as lng')
                    ->groupBy('city', 'state', 'country')->get(),
            ))
            // The first source wins a slug: artists, the densest signal.
            ->unique('slug');

        $created = 0;

        foreach ($towns as $town) {
            $place = Place::firstOrNew(['slug' => $town['slug']]);
            $created += $place->exists ? 0 : 1;
            $place->fill($town)->save();
        }

        $this->info("{$towns->count()} places in sync ({$created} new).");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<int, array{slug: string, name: string, region: mixed, country: mixed, lat: float, lng: float}>
     */
    private function towns(Collection $rows): Collection
    {
        return $rows
            ->filter(fn ($row) => filled($row->city))
            ->map(fn ($row) => [
                'slug' => Place::slugFor((string) $row->city, $row->region),
                'name' => trim((string) $row->city),
                'region' => $row->region,
                'country' => $row->country,
                'lat' => round((float) $row->lat, 6),
                'lng' => round((float) $row->lng, 6),
            ])
            ->values();
    }
}
