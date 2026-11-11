<?php

namespace App\Services\Events;

use App\Actions\GeoHashEncodeAction;
use App\Contracts\EventProvider;
use App\DTO\LocalEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TicketmasterEvents implements EventProvider
{
    protected const EVENTS_ENDPOINT = 'https://app.ticketmaster.com/discovery/v2/events.json';

    /**
     * Ticketmaster's stable classification segment id for Music, used
     * instead of a per-boot classification lookup request.
     */
    protected const MUSIC_SEGMENT_ID = 'KZFzniwnSyZfZ7v7nJ';

    public function __construct(private GeoHashEncodeAction $geoHash) {}

    public function source(): string
    {
        return 'ticketmaster';
    }

    public function isConfigured(): bool
    {
        return filled(config('services.ticketmaster.key'));
    }

    public function eventsNear(float $lat, float $lng, int $radiusMiles = 25): Collection
    {
        if (! $this->isConfigured()) {
            Log::notice('TicketmasterEvents skipped: TICKETMASTER_API_KEY is not configured.');

            return collect();
        }

        $geoHash = $this->geoHash->handle($lat, $lng, 9);

        return Cache::remember(
            "events.ticketmaster.{$geoHash}.{$radiusMiles}",
            now()->addHour(),
            fn () => $this->fetch($geoHash, $radiusMiles),
        );
    }

    protected function fetch(string $geoHash, int $radiusMiles): Collection
    {
        try {
            $response = Http::timeout(5)
                ->retry(2, 200, throw: false)
                ->get(self::EVENTS_ENDPOINT, [
                    'apikey' => config('services.ticketmaster.key'),
                    'classificationId' => self::MUSIC_SEGMENT_ID,
                    'geoPoint' => $geoHash,
                    'radius' => (string) $radiusMiles,
                    'unit' => 'miles',
                ]);
        } catch (ConnectionException $e) {
            Log::warning('Ticketmaster request failed.', ['error' => $e->getMessage()]);

            return collect();
        }

        if ($response->failed()) {
            Log::warning('Ticketmaster returned an HTTP error.', ['status' => $response->status()]);

            return collect();
        }

        return collect((array) $response->json('_embedded.events', []))
            ->map(fn (array $event) => $this->toLocalEvent($event))
            ->filter()
            ->values();
    }

    protected function toLocalEvent(array $event): ?LocalEvent
    {
        $venue = data_get($event, '_embedded.venues.0');

        if (! isset($event['name'])) {
            return null;
        }

        $startsAt = data_get($event, 'dates.start.dateTime');
        $lat = data_get($venue, 'location.latitude');
        $lng = data_get($venue, 'location.longitude');

        return new LocalEvent(
            name: $event['name'],
            startsAt: $startsAt !== null ? Carbon::parse($startsAt) : null,
            venue: data_get($venue, 'name'),
            city: data_get($venue, 'city.name'),
            state: data_get($venue, 'state.stateCode'),
            lat: $lat !== null ? (float) $lat : null,
            lng: $lng !== null ? (float) $lng : null,
            url: data_get($event, 'url'),
            source: $this->source(),
        );
    }
}
