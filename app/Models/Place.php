<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A town with a scene page. Not user-editable: rows are synced from stored
 * town-level coordinates (riff:sync-places, GeocodeAddress), and never
 * deleted, so a scene link stays valid.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $region
 * @property string|null $country
 * @property float $lat
 * @property float $lng
 * @property float|null $radius_km
 * @property-read string $label
 */
class Place extends Model
{
    protected $fillable = ['slug', 'name', 'region', 'country', 'lat', 'lng'];

    protected $casts = [
        'lat' => 'float',
        'lng' => 'float',
        'radius_km' => 'float',
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** "hartford-ct". Deterministic, so a town can be linked without a lookup. */
    public static function slugFor(string $city, ?string $region): string
    {
        return Str::slug(trim($city.' '.($region ?? '')));
    }

    /**
     * Make sure a town has a row. Coordinates are only written on first
     * sight; riff:sync-places refreshes them from the town's records.
     */
    public static function record(string $city, ?string $region, ?string $country, float $lat, float $lng): self
    {
        return static::firstOrCreate(
            ['slug' => self::slugFor($city, $region)],
            ['name' => trim($city), 'region' => $region, 'country' => $country, 'lat' => $lat, 'lng' => $lng],
        );
    }

    public function radiusKm(): float
    {
        return $this->radius_km ?? (float) config('riff.scenes.radius_km');
    }

    public function getLabelAttribute(): string
    {
        return collect([$this->name, $this->region])->filter()->implode(', ');
    }
}
