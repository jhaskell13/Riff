<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class EventFinder
{
    const CLASSIFICATIONS_ENDPOINT = 'https://app.ticketmaster.com/discovery/v2/classifications.json';

    const EVENTS_ENDPOINT = 'https://app.ticketmaster.com/discovery/v2/events.json';

    private function get(string $endpoint, array $params = []): Collection
    {
        $params['apikey'] = config('services.ticketmaster.key');
        $response = json_decode(Http::get($endpoint, $params)->body());

        if (isset($response->errors)) {
            throw new \RuntimeException('Ticketmaster API error: '.json_encode($response->errors));
        }

        return collect($response->_embedded);
    }

    private function getClassificationId(string $keyword): string
    {
        // 24 hour cache
        $classificationId = Cache::remember("tm.classificationId.$keyword", 60 * 60 * 24, function () use ($keyword) {
            return collect($this->get(self::CLASSIFICATIONS_ENDPOINT, [
                'keyword' => $keyword,
            ])->first())->where('segment.name', $keyword)
                ->first()
                ->segment
                ->id;
        });

        return $classificationId;
    }

    public function getNearbyEvents(string $geoHash, int $radius = 25): Collection
    {
        $musicClassificationId = $this->getClassificationId('Music');

        // 1 hour cache
        $events = Cache::remember("tm.events.Music.$geoHash", 3600, function () use ($geoHash, $radius, $musicClassificationId) {
            return collect($this->get(self::EVENTS_ENDPOINT, [
                'classificationId' => $musicClassificationId,
                'geoPoint' => $geoHash,
                'radius' => strval($radius),
                'unit' => 'miles',
            ])->get('events'));
        });

        return $this->mapEventLocations($events);
    }

    /**
     * Maps lat/lng for each venue at an event, given the collection of events
     *
     * @param  Collection  $events  Collected events from this service
     */
    private function mapEventLocations(Collection $events): Collection
    {
        return $events->map(function ($event) {
            return (object) [
                'name' => $event->name,
                'dates' => $event->dates,
                'locations' => collect($event->_embedded->venues)->map(function ($venue) {
                    return (object) [
                        'venue' => $venue->name,
                        'city' => $venue->city,
                        'state' => $venue->state,
                        'country' => $venue->country,
                        'address' => $venue->address->line1,
                        'lat' => $venue->location->latitude,
                        'lng' => $venue->location->longitude,
                    ];
                }),
            ];
        });
    }
}
