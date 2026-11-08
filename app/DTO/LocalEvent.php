<?php

namespace App\DTO;

use Illuminate\Support\Carbon;

/**
 * Provider-agnostic shape for a nearby music event. Every event source
 * (Ticketmaster, local venue APIs, scraped listings) maps into this,
 * so the map and event UI never depend on one provider's schema.
 */
class LocalEvent
{
    public function __construct(
        public string $name,
        public ?Carbon $startsAt,
        public ?string $venue,
        public ?string $city,
        public ?string $state,
        public ?float $lat,
        public ?float $lng,
        public ?string $url = null,
        public string $source = 'unknown',
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'starts_at' => $this->startsAt?->toIso8601String(),
            'venue' => $this->venue,
            'city' => $this->city,
            'state' => $this->state,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'url' => $this->url,
            'source' => $this->source,
        ];
    }
}
