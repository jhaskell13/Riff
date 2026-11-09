<?php

namespace App\Models;

use App\Support\GenreCatalog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A genre in the discovery vocabulary. The curated list in config/riff.php
 * is reconciled by GenreSeeder; is_curated marks entries that came from
 * somewhere else (a legacy free-text value today, artist-created tags
 * later) so the two can be told apart in filters and moderation.
 */
class Genre extends Model
{
    protected $fillable = ['name', 'slug', 'is_curated', 'created_by'];

    protected $casts = ['is_curated' => 'boolean'];

    protected static function booted(): void
    {
        static::saved(fn () => GenreCatalog::flush());
        static::deleted(fn () => GenreCatalog::flush());
    }

    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class);
    }

    public function scopeCurated(Builder $query): Builder
    {
        return $query->where('is_curated', true);
    }
}
