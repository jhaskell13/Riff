<?php

namespace App\Observers;

use App\Contracts\Geocodable;
use App\Jobs\GeocodeAddress;
use Illuminate\Database\Eloquent\Model;

class GeocodableObserver
{
    /**
     * An address change invalidates the stored coordinates immediately.
     * The record leaves the map until geocoding succeeds; stale
     * coordinates must never place it at the old location.
     */
    public function updating(Model&Geocodable $model): void
    {
        if ($model->isDirty($model->geocodableAttributes())) {
            $model->lat = null;
            $model->lng = null;
        }
    }

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
