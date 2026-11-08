<?php

namespace App\Models;

use App\Contracts\Geocodable;
use App\Observers\GeocodableObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[ObservedBy([GeocodableObserver::class])]
class Artist extends Model implements Geocodable
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'hometown',
        'stateOrProvince',
        'country',
        'genre',
        'biography',
        'website',
        'email',
        'image_url',
        'formed_date',
        'lat',
        'lng',
    ];

    protected $casts = [
        'formed_date' => 'date',
        'lat' => 'float',
        'lng' => 'float',
    ];

    /**
     * Get all of the members for the Artist
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function geocodableAddress(): array
    {
        return [$this->hometown, $this->stateOrProvince, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['hometown', 'stateOrProvince', 'country'];
    }

    public function scopeGeolocated(Builder $query): Builder
    {
        return $query->whereNotNull('lat')->whereNotNull('lng');
    }

    /**
     * Artists inside a map viewport. Viewports crossing the antimeridian
     * are not supported; acceptable until Riff hosts artists near it.
     */
    public function scopeWithinBounds(Builder $query, float $south, float $west, float $north, float $east): Builder
    {
        return $query
            ->whereBetween('lat', [$south, $north])
            ->whereBetween('lng', [$west, $east]);
    }

    public function getAddressAttribute(): string
    {
        return $this->hometown.', '.$this->stateOrProvince.', '.$this->country;
    }
}
