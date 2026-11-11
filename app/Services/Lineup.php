<?php

namespace App\Services;

use App\Models\LineupEntry;
use App\Models\Show;
use App\Models\User;

/**
 * Writes a show's bill and enforces lineup consent, for create and edit
 * alike.
 *
 * - An artist the acting member belongs to is confirmed at once.
 * - Any other Riff artist starts pending, and appears nowhere public until
 *   one of its own members confirms.
 * - A declined artist stays declined across edits: re-saving the form must
 *   not re-ask a band that already said no.
 * - A material change (date, time, or place) puts every other artist's
 *   answer back to pending: confirming "Friday at the Lantern Room" is not
 *   consent to "Saturday in a backyard".
 */
class Lineup
{
    /**
     * @param  list<array{artist_id?: int|null, name?: string|null}>  $entries  in bill order
     */
    public function sync(Show $show, array $entries, User $actor, bool $materialChange = false): void
    {
        // Cast: some drivers return ids as strings, and the check is strict.
        $ownArtistIds = $actor->artists()->pluck('artists.id')->map(fn ($id) => (int) $id)->all();

        $existing = LineupEntry::query()
            ->where('show_id', $show->id)
            ->whereNotNull('artist_id')
            ->get()
            ->keyBy('artist_id');

        // Free-text names carry no consent state, so they are simply
        // rewritten. (Deletes go through a plain query, not the ordered
        // relation: DELETE ... ORDER BY is not portable across drivers.)
        LineupEntry::query()->where('show_id', $show->id)->whereNull('artist_id')->delete();

        $kept = [];

        foreach ($entries as $position => $entry) {
            $artistId = $entry['artist_id'] ?? null;

            if ($artistId === null) {
                // Confirmed by the column default: a name with no Riff
                // profile has no one to ask.
                $show->lineup()->create(['name' => trim((string) ($entry['name'] ?? '')), 'position' => $position]);

                continue;
            }

            $artistId = (int) $artistId;
            $kept[] = $artistId;
            $isOwn = in_array($artistId, $ownArtistIds, true);
            $current = $existing->get($artistId);

            if ($current === null) {
                $current = $show->lineup()->make(['artist_id' => $artistId, 'position' => $position]);
                $current->forceFill([
                    'status' => $isOwn ? LineupEntry::CONFIRMED : LineupEntry::PENDING,
                    'responded_at' => $isOwn ? now() : null,
                ]);
            } else {
                $current->position = $position;

                if ($isOwn) {
                    $current->forceFill(['status' => LineupEntry::CONFIRMED, 'responded_at' => now()]);
                } elseif ($materialChange) {
                    $current->forceFill(['status' => LineupEntry::PENDING, 'responded_at' => null]);
                }
            }

            $current->save();
        }

        LineupEntry::query()
            ->where('show_id', $show->id)
            ->whereNotNull('artist_id')
            ->whereNotIn('artist_id', $kept === [] ? [0] : $kept)
            ->delete();
    }
}
