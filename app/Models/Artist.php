<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Artist extends Model
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
    ];

    protected $casts = [
        'formed_date' => 'datetime',
    ];

    protected $appends = ['address'];

    /**
     * Get all of the members for the Artist
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function getAddressAttribute(): string
    {
        return $this->hometown.', '.$this->stateOrProvince.', '.$this->country;
    }
}
