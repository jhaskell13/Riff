<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Contracts\Geocodable;
use App\Observers\GeocodableObserver;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[ObservedBy([GeocodableObserver::class])]
class User extends Authenticatable implements Geocodable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'city',
        'state',
        'country',
        'lat',
        'lng',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'lat' => 'float',
            'lng' => 'float',
        ];
    }

    /**
     * Get the artist that owns the User
     */
    public function artists(): BelongsToMany
    {
        return $this->belongsToMany(Artist::class);
    }

    public function geocodableAddress(): array
    {
        return [$this->city, $this->state, $this->country];
    }

    public function geocodableAttributes(): array
    {
        return ['city', 'state', 'country'];
    }

    public function isMemberOf(Artist $artist): bool
    {
        return $this->artists()->whereKey($artist->id)->exists();
    }

    /**
     * Get all of the posts for the User
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * The postLikes that belong to the User
     */
    public function postLikes(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_likes');
    }

    /**
     * Get all of the comments for the User
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function commentLikes()
    {
        return $this->belongsToMany(Comment::class, 'comment_likes')->withTimestamps();
    }

    public function hasLikedPost(Post $post)
    {
        return $this->postLikes()->where('post_id', $post->id)->exists();
    }

    public function hasLikedComment(Comment $comment)
    {
        return $this->commentLikes()->where('comment_id', $comment->id)->exists();
    }
}
