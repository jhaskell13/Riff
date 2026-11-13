<?php

namespace App\DTO;

use App\Contracts\Commentable;
use App\Models\Artist;
use App\Models\Show;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

/**
 * Who is who in a thread, worked out once per render.
 *
 * A reader has to tell three things apart: the band speaking, another band
 * on the same bill speaking, and someone who is *in* one of those bands
 * speaking as themselves — indistinguishable from any other fan without a
 * label, which matters most on a four-act bill.
 *
 * Labels name the relationship, never the artist's name: the author line
 * already says who spoke, so the wall once showed the band's name twice in
 * a row. Membership resolves in one query, matched in memory — per comment
 * it would be the N+1 the wall already learned about.
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

    /** A subject with no artist behind it. */
    public static function none(): self
    {
        return new self(null, 'Artist', null, [], false);
    }

    /**
     * Every post on one wall has the same artist behind it, so these are
     * built once — per post was an N+1 the query-count test caught.
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
            // On a wall the page already belongs to them.
            ownerLabel: $isShow ? 'Host' : 'Artist',

            otherVoiceLabel: $isShow ? 'On the bill' : null,
            memberOf: self::members($artists),
            // On a show, *which* band someone is in is the point.
            namesMemberArtist: $isShow,
        );
    }

    /**
     * Null when the author has no standing here, which is most people.
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
     * user id => artist name, in one query off the pivot: hydrating each
     * band's members would be a query per band for a line of text. Someone
     * in two acts gets the first by bill order, which puts the host first.
     *
     * @param  EloquentCollection<int, Artist>  $artists
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
