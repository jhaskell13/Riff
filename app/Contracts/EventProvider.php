<?php

namespace App\Contracts;

use App\DTO\LocalEvent;
use Illuminate\Support\Collection;

/**
 * A source of nearby music events. Implementations must be resilient:
 * misconfiguration or provider downtime returns an empty collection,
 * never an exception that breaks map or event pages.
 *
 * Planned implementations beyond Ticketmaster: local venue calendars,
 * Bandsintown, and scraped listings for small venues (see ROADMAP
 * phase 2 — label imported listings vs artist-confirmed events).
 */
interface EventProvider
{
    /**
     * Human-readable source key stamped on each LocalEvent.
     */
    public function source(): string;

    /**
     * Whether the provider has the configuration it needs to run.
     */
    public function isConfigured(): bool;

    /**
     * Events near a coordinate, mapped into LocalEvent DTOs.
     *
     * @return Collection<int, LocalEvent>
     */
    public function eventsNear(float $lat, float $lng, int $radiusMiles = 25): Collection;
}
