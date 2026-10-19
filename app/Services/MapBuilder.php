<?php

namespace App\Services;

use App\Actions\BuildArtistMapData;
use App\Actions\ResolveUserLocation;
use App\Models\Artist;
use App\Models\User;

class MapBuilder
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private ResolveUserLocation $resolveUserLocation,
        private BuildArtistMapData $buildArtistMapData,
        private Geocoder $geocoder,
    ) {}

    public function buildMapDataForUser(User $user): array
    {
        try {
            return [
                'userLocation' => $this->resolveUserLocation->execute($user),
                'artistLocations' => $this->buildArtistMapData->execute(Artist::query()->get()),
            ];
        } catch (\Throwable $e) {
            \Log::error("Error building map data... [$e->getMessage()]");
            return [
                'userLocation' => $this->geocoder->defaultCoords(),
                'artistLocations' => collect(),
            ];
        }
    }

    // getNearbyArtists(int radius= 50miles)
}
