<?php

namespace App\Observers;

use App\Contracts\Geocodable;
use App\Jobs\GeocodeAddress;
use Illuminate\Database\Eloquent\Model;

class GeocodableObserver
{
    /**
     * Geocode new records unless coordinates were supplied directly
     * (factories and imports may set them).
     */
    public function created(Model&Geocodable $model): void
    {
        if ($model->lat === null || $model->lng === null) {
            GeocodeAddress::dispatch($model);
        }
    }

    /**
     * Re-geocode when any address component changed.
     */
    public function updated(Model&Geocodable $model): void
    {
        if ($model->wasChanged($model->geocodableAttributes())) {
            GeocodeAddress::dispatch($model);
        }
    }
}
