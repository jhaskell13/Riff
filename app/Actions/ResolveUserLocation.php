<?php

namespace App\Actions;

use App\DTO\MapPoint;
use App\Models\User;
use App\Services\Geocoder;

class ResolveUserLocation
{
    /**
     * Create a new class instance.
     */
    public function __construct(private Geocoder $geocoder)
    {
        //
    }

    public function execute(User $user): MapPoint
    {
        $location = $this->geocoder->fromAddress($user->city, $user->state, $user?->country);

        return new MapPoint(
            lat: $location->lat,
            lng: $location->lng,
            label: 'You',
        );
    }
}
