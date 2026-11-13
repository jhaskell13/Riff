<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\ArtistSupportLink;
use Illuminate\Support\Facades\DB;

/**
 * Replaces an artist's support links with the set the form submitted.
 *
 * The whole section saves at once, like genres do, because reordering and
 * removing are the common edits and per-link endpoints would make either
 * one several requests. Two things that matters for:
 *
 *  - **Click counts survive an edit.** A link the artist kept is matched by
 *    id and updated in place, so fixing a typo or moving a row does not
 *    reset the counter the Phase 4 payments question depends on.
 *
 *  - **Ids are scoped to the artist.** An id the form sends that does not
 *    belong to this artist is treated as a new link, never as a row to
 *    update -- otherwise the form would be an editing endpoint for every
 *    other artist's links.
 */
class ArtistSupportLinks
{
    /**
     * Keyed rather than a list on purpose: a form array can arrive with
     * gaps (links.0 and links.2 after a removal), so array_values below is
     * what guarantees positions run 0..n.
     *
     * @param  array<int|string, array{id?: int|null, type: string, url: string, label?: string|null}>  $links
     */
    public function sync(Artist $artist, array $links): void
    {
        $existing = $artist->supportLinks()->get()->keyBy('id');
        $kept = [];

        DB::transaction(function () use ($artist, $links, $existing, &$kept) {
            foreach (array_values($links) as $position => $link) {
                $target = SupportLink::parse($link['url'], $link['type']);

                // Validation rejects these, so reaching here means a link
                // the parser refuses; skipping beats storing a dead href.
                if ($target === null) {
                    continue;
                }

                $label = isset($link['label']) ? trim((string) $link['label']) : '';

                $attributes = [
                    'type' => $target->type,
                    'url' => $target->url,
                    'label' => $label === '' ? null : $label,
                    'position' => $position,
                ];

                $id = isset($link['id']) ? (int) $link['id'] : 0;
                $row = $existing->get($id);

                if ($row !== null) {
                    $row->update($attributes);
                    $kept[] = $row->id;

                    continue;
                }

                $kept[] = $artist->supportLinks()->create($attributes)->id;
            }

            // Whatever the artist removed. Their click history goes with
            // them: the counter is per link, and the link is gone.
            $artist->supportLinks()
                ->when($kept !== [], fn ($query) => $query->whereNotIn('id', $kept))
                ->delete();
        });
    }

    /**
     * Record an outbound click. Aggregate only -- see the migration.
     */
    public function recordClick(ArtistSupportLink $link): void
    {
        $link->increment('clicks');
    }
}
