<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class PostLike extends Pivot
{
    protected $table = 'post_likes';

    public $incrementing = true;

    protected $fillable = [
        'post_id',
        'user_id',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::created(function ($like) {
            dump($like->post);
            $like->post->incrementLikesCount();
        });

        static::deleted(function ($like) {
            $like->post->decrementLikesCount();
        });
    }
}
