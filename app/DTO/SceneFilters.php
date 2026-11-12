<?php

namespace App\DTO;

use App\Support\ShowWindow;

/**
 * A validated, normalised query for the map's show and venue pins. Built
 * only by MapSceneRequest; like MapFilters, two requests that mean the same
 * thing produce the same cache key.
 */
class SceneFilters
{
    /**
     * @param  array{south: float, west: float, north: float, east: float}|null  $bounds
     * @param  list<int>  $genreIds
     */
    public function __construct(
        public ?array $bounds,
        public MapPoint $origin,
        public ?float $radiusKm,
        public string $unit,
        public ShowWindow $window,
        public array $genreIds = [],
        public int $limit = 300,
        /** Set only when the viewer blocks someone; makes results personal. */
        public ?int $hiddenFor = null,
    ) {}

    public function cacheKey(string $kind): ?string
    {
        // Venues don't depend on who's looking (blocking hides artists'
        // things, not rooms), so a blocking viewer still shares their cache.
        if ($this->hiddenFor !== null && $kind !== 'venues') {
            return null;
        }

        // The window by name and local day, not its exact instants: those
        // move every second, and the cache's short TTL bounds the drift.
        return $kind.':'.sha1(json_encode([
            $this->bounds,
            $this->origin->toArray(),
            $this->radiusKm,
            $this->unit,
            $kind === 'shows' ? [$this->window->name, $this->window->localDate, $this->window->timezone] : null,
            $this->genreIds,
            $this->limit,
        ]));
    }
}
