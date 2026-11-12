<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reply relayed to a listing's poster by email. Kept so one person can
 * reply once, so abuse can be reviewed, and so threads can move into
 * in-app messaging when it exists. user_id is set from the request, never
 * mass-assigned.
 *
 * @property int $id
 * @property int $listing_id
 * @property int $user_id
 * @property string $message
 * @property string $contact
 * @property-read Listing|null $listing
 * @property-read User|null $user
 */
class ListingReply extends Model
{
    protected $fillable = ['message', 'contact'];

    /** @return BelongsTo<Listing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
