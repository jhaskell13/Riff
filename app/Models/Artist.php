<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Artist extends Model
{
    protected $fillable = [
        'name',
        'location',
        'genre',
        'genres',
        'members',
        'biography',
        'website',
        'email',
        'social_media',
        'image_url',
        'formed_date',
    ];

    protected $casts = [
        'formed_date' => 'datetime',
    ];
    
    /**
     * Get all of the members for the Artist
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function members(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, ArtistUser::class);
    }
}
