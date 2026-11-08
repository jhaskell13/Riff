<?php

namespace App\Jobs;

use App\Contracts\Geocodable;
use App\Services\Geocoder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeAddress implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public Model&Geocodable $model,
    ) {}

    public function handle(Geocoder $geocoder): void
    {
        [$city, $state, $country] = $this->model->geocodableAddress();

        $point = $geocoder->fromAddress($city, $state, $country);

        if ($point === null) {
            return;
        }

        // saveQuietly: setting coordinates must not re-trigger observers.
        $this->model->forceFill([
            'lat' => $point->lat,
            'lng' => $point->lng,
        ])->saveQuietly();
    }
}
