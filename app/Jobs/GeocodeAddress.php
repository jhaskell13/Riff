<?php

namespace App\Jobs;

use App\Contracts\Geocodable;
use App\Models\Artist;
use App\Models\Place;
use App\Models\User;
use App\Services\Geocoder;
use App\Support\MapCache;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;

class GeocodeAddress implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * A record deleted before the job runs is not an error.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Model&Geocodable $model,
    ) {}

    public function handle(Geocoder $geocoder): void
    {
        $address = $this->model->geocodableAddress();

        $point = $geocoder->fromAddress(...$address);

        if ($point === null) {
            return;
        }

        // Guard against a stale write: if the address changed while this
        // job was geocoding (another job is queued for the new address),
        // these coordinates no longer describe the record.
        $this->model->refresh();

        if ($this->model->geocodableAddress() !== $address) {
            return;
        }

        // saveQuietly: setting coordinates must not re-trigger observers.
        $this->model->forceFill([
            'lat' => $point->lat,
            'lng' => $point->lng,
        ])->saveQuietly();

        // saveQuietly skips the model events that normally invalidate the
        // map cache, and a newly geocoded artist is exactly the change a
        // cached viewport must not miss.
        // Users aren't on the map; everything else geocoded is.
        if (! $this->model instanceof User) {
            MapCache::bump();
        }

        // A newly geocoded artist's town gets a scene page straight away.
        // Artists are always town-level, so the point is the town's centre.
        if ($this->model instanceof Artist) {
            Place::record($address[0], $address[1], $address[2], $point->lat, $point->lng);
        }
    }
}
