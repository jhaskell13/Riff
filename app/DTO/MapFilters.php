<?php

namespace App\DTO;

/**
 * A validated, normalised map query. Built only by MapArtistsRequest, so
 * every field here is already bounded and canonical: arrays are sorted and
 * de-duplicated, bounds are snapped, and the origin is rounded. That is what
 * makes cacheKey() meaningful -- two requests that mean the same thing
 * produce the same key.
 */
class MapFilters
{
    public const SORTS = ['distance', 'name', 'newest'];

    /**
     * @param  array{south: float, west: float, north: float, east: float}|null  $bounds
     * @param  list<int>  $genreIds
     * @param  list<string>  $types
     */
    public function __construct(
        public ?array $bounds,
        public MapPoint $origin,
        public ?float $radiusKm,
        public string $unit,
        public array $genreIds = [],
        public array $types = [],
        public ?string $query = null,
        public bool $hasMusic = false,
        public bool $hasPhoto = false,
        public ?int $followedBy = null,
        public string $sort = 'distance',
        public int $limit = 500,
    ) {}

    /**
     * A name search with no area: global, nearest-first, small.
     */
    public function isSearchOnly(): bool
    {
        return $this->query !== null && $this->bounds === null && $this->radiusKm === null;
    }

    /**
     * Results that depend on who is asking must not be shared through the
     * cache.
     */
    public function isPersonal(): bool
    {
        return $this->followedBy !== null;
    }

    public function cacheKey(): ?string
    {
        if ($this->isPersonal()) {
            return null;
        }

        return sha1(json_encode([
            $this->bounds,
            $this->origin->toArray(),
            $this->radiusKm,
            $this->unit,
            $this->genreIds,
            $this->types,
            $this->query,
            $this->hasMusic,
            $this->hasPhoto,
            $this->sort,
            $this->limit,
        ]));
    }
}
