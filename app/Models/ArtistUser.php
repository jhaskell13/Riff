<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistUser extends Model
{
    protected $table = 'artist_user';

    protected $fillable = [
        'artist_id',
        'user_id',
    ];
}
