<?php

namespace App\DTO;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Show;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Who is who in a thread, worked out once for the whole render.
 *
 * A reader needs to tell three things apart at a glance: the band speaking
 * as itself, another band on the same bill speaking as itself, and a person
 * who happens to be *in* one of those bands speaking as themselves. Without
 * a label the third is indistinguishable from any other fan, which matters
 * most in exactly the case where it is most confusing -- a show thread with
 * four acts on it.
 *
 * The labels name the *relationship to this thread*, never the artist's own
 * name for the artist-voice cases: the author line already says which band
 * said it, so repeating it in the badge was noise (the wall once showed the
 * band's name twice in a row). "Artist", "Host" and "On the bill" each say
 * something the author line does not.
 *
 * Membership is resolved in **one query** for every artist with standing
 * here, then matched in memory. Asking per comment would be a lookup per
 * row, which is the N+1 the wall already learned about once.
 */
final class ThreadBadges
{
    /**
     * @param  array<int, string>  $memberOf  user id => the artist they belong to
     */
    private function __construct(
        private readonly ?int $ownerArtistId,
        private readonly string $ownerLabel,
        private readonly ?string $otherVoiceLabel,
        private readonly array $memberOf,
        private readonly bool $namesMemberArtist,
    ) {}

    /** Nothing to label: a subject with no artist behind it. */
    public static function none(): self
    {
        return new self(null, 'Artist', null, [], false);
    }

    /**
     * The badges for a whole wall.
     *
     * Every post on one wall belongs to the same artist, so these are
     * identical for all of them -- built once per render, not once per
     * post. Building them per post was an N+1 that the wall's query-count
     * test caught immediately.
     */
    public static function forWall(Artist $artist): self
    {
        return new self(
            ownerArtistId: $artist->id,
            ownerLabel: 'Artist',
            otherVoiceLabel: null,
            memberOf: self::members(new EloquentCollection([$artist])),
            namesMemberArtist: false,
        );
    }

    public static function for(?Commentable $subject): self
    {
        if ($subject === null) {
            return self::none();
        }

        $artists = $subject->commentVoiceArtists();

        if ($artists->isEmpty()) {
            return self::none();
        }

        $isShow = $subject instanceof Show;

        return new self(
            ownerArtistId: $subject->moderatingArtist()?->id,
            // "Host" says what the badge is for on a show; on a wall the
            // page already belongs to them, so "Artist" is the useful word.
            ownerLabel: $isShow ? 'Host' : 'Artist',
            // Only a show has other bands with standing.
            otherVoiceLabel: $isShow ? 'On the bill' : null,
            memberOf: self::members($artists),
            // On a show, *which* band someone is in is the point; on a wall
            // there is only one band it could be.
            namesMemberArtist: $isShow,
        );
    }

    /**
     * The badge for one comment, or null when its author has no standing
     * here (an ordinary listener, which is most of them).
     *
     * @return array{label: string, tone: string}|null
     */
    public function labelFor(bool $isArtistVoice, ?int $artistId, ?int $userId): ?array
    {
        if ($isArtistVoice) {
            if ($artistId === null) {
                return null;
            }

            return $artistId === $this->ownerArtistId
                ? ['label' => $this->ownerLabel, 'tone' => 'owner']
                : ($this->otherVoiceLabel === null
                    ? null
                    : ['label' => $this->otherVoiceLabel, 'tone' => 'lineup']);
        }

        // A person in one of the bands, speaking as themselves.
        $artistName = $userId === null ? null : ($this->memberOf[$userId] ?? null);

        if ($artistName === null) {
            return null;
        }

        return [
            'label' => $this->namesMemberArtist ? "Member of {$artistName}" : 'Member',
            'tone' => 'member',
        ];
    }

    /**
     * user id => artist name, for every artist with standing here. One
     * query, read straight off the pivot: hydrating each artist's members
     * would be a query per band for a line of text.
     *
     * Someone in two acts on one bill gets the first by bill order, which
     * puts the host first -- the most informative answer available in a
     * badge that has room for one.
     *
     * @param  Collection<int, Artist>  $artists
     * @return array<int, string>
     */
    private static function members($artists): array
    {
        $names = $artists->pluck('name', 'id');

        $rows = DB::table('artist_user')
            ->whereIn('artist_id', $names->keys())
            ->get(['user_id', 'artist_id']);

        $byUser = [];
        $order = $names->keys()->flip();

        foreach ($rows as $row) {
            $existing = $byUser[$row->user_id] ?? null;

            if ($existing === null || $order[$row->artist_id] < $order[$existing]) {
                $byUser[$row->user_id] = $row->artist_id;
            }
        }

        return array_map(
            fn (int|string $artistId) => (string) $names[$artistId],
            $byUser,
        );
    }
}
