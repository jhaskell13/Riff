<?php

namespace App\Actions;

use App\DTO\MapPoint;
use App\Models\Artist;
use App\Services\Geocoder;
use Illuminate\Support\Collection;

class BuildArtistMapData
{
    /**
     * Create a new class instance.
     */
    public function __construct(private Geocoder $geocoder)
    {
        //
    }

    public function execute(Collection $artists): Collection
    {
        return $artists->map(function (Artist $artist) {
            $location = $this->geocoder->fromAddress($artist->hometown, $artist->stateOrProvince, $artist?->country);

            return new MapPoint(
                lat: $location->lat,
                lng: $location->lng,
                label: $artist->name,
            );
        });
    }
}
