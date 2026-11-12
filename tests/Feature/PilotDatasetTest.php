<?php

namespace Tests\Feature;

use App\Services\SupportLink;
use Database\Factories\Support\Towns;
use Database\Seeders\Support\PilotScene;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The researched pilot dataset's own rules (see _meta in
 * database/seeders/data/pilot-scene.json). The acts and venues marked real
 * describe real people and places who never agreed to be on Riff, so the
 * dataset must never attribute anything to them: every invented state —
 * reports, artist-voice posts, listings, pending lineup slots, cancelled
 * shows, hidden or archived profiles — belongs to fictional entries only.
 */
class PilotDatasetTest extends TestCase
{
    /** @return list<string> */
    private function realActNames(): array
    {
        return array_values(Arr::pluck(
            array_filter(PilotScene::acts(), fn (array $act) => $act['real'] === true),
            'name',
        ));
    }

    public function test_the_dataset_is_internally_consistent(): void
    {
        $actNames = Arr::pluck(PilotScene::acts(), 'name');
        $venueNames = Arr::pluck(PilotScene::venues(), 'name');

        $this->assertSame($actNames, array_unique($actNames), 'Duplicate act names.');
        $this->assertSame($venueNames, array_unique($venueNames), 'Duplicate venue names.');

        // Vocabulary and towns resolve, so a typo fails here and not
        // halfway through a seed.
        foreach (PilotScene::acts() as $act) {
            $this->assertArrayHasKey($act['type'], config('riff.artist.types'), $act['name']);
            $this->assertNotEmpty(Towns::named($act['town']), $act['name']);

            foreach ($act['genres'] as $genre) {
                $this->assertContains($genre, config('riff.genres'), "{$act['name']}: {$genre}");
            }
        }

        foreach (PilotScene::venues() as $venue) {
            $this->assertArrayHasKey($venue['kind'], config('riff.venues.kinds'), $venue['name']);
            $this->assertNotEmpty(Towns::named($venue['town']), $venue['name']);

            if ($venue['age_policy'] !== null) {
                $this->assertArrayHasKey($venue['age_policy'], config('riff.age_policies'), $venue['name']);
            }
        }

        foreach (PilotScene::shows() as $show) {
            $this->assertContains($show['host'], $actNames, $show['host']);

            foreach ([...($show['confirmed'] ?? []), ...($show['pending'] ?? [])] as $act) {
                $this->assertContains($act, $actNames, $act);
            }

            if (isset($show['venue'])) {
                $this->assertContains($show['venue'], $venueNames, $show['venue']);
            } else {
                $this->assertNotEmpty(Towns::named($show['location']['town']));
            }
        }

        // Seeded support links go through the same parser a real artist's
        // would, so a typo in the dataset fails here rather than storing a
        // dead href the seeder silently skipped.
        foreach (PilotScene::acts() as $act) {
            foreach ($act['support'] ?? [] as $link) {
                $this->assertNotNull(
                    SupportLink::parse($link['url'], $link['type']),
                    "{$act['name']}: {$link['url']} is not a valid {$link['type']} link.",
                );
            }
        }

        foreach (PilotScene::listings() as $listing) {
            $this->assertArrayHasKey($listing['type'], config('riff.listings.types'));

            if ($listing['artist'] !== null) {
                $this->assertContains($listing['artist'], $actNames);
            }
        }
    }

    public function test_nothing_is_ever_attributed_to_a_real_act(): void
    {
        $real = $this->realActNames();

        foreach (PilotScene::acts() as $act) {
            if ($act['real']) {
                $this->assertArrayNotHasKey('hidden_from_map', $act, "{$act['name']}: a real act can't be given a hidden state.");
                $this->assertArrayNotHasKey('archived', $act, "{$act['name']}: a real act can't be archived.");
                $this->assertArrayNotHasKey('placeholder_music', $act, "{$act['name']}: placeholder tracks would misattribute someone else's music.");
            }
        }

        foreach (PilotScene::shows() as $show) {
            // A pending slot is an invented consent answer; a cancellation
            // is an invented decision. Fictional acts only.
            foreach ($show['pending'] ?? [] as $act) {
                $this->assertNotContains($act, $real, "{$act}: a real act can't be shown as not having agreed to a bill.");
            }

            if ($show['cancelled'] ?? false) {
                $this->assertNotContains($show['host'], $real, "{$show['host']}: a real act can't be shown cancelling.");
            }
        }

        foreach (PilotScene::listings() as $listing) {
            if ($listing['artist'] !== null) {
                $this->assertNotContains($listing['artist'], $real, "{$listing['artist']}: a real act can't be said to be looking for anyone.");
            }
        }

        // The DIY space must stay fictional: Riff never holds a real one's
        // address, and must not out one's existence either.
        foreach (PilotScene::venues() as $venue) {
            if ($venue['kind'] === 'diy' || $venue['street'] === null) {
                $this->assertFalse($venue['real'], "{$venue['name']}: a private/DIY venue in the dataset must be fictional.");
            }

            if ($venue['real']) {
                $this->assertNotNull($venue['street'], "{$venue['name']}: a real venue is listed at its public address or not at all.");
            }
        }

        // Real acts may only carry links verified during research, and only
        // link-out Bandcamp pages (a wrong playable embed would put someone
        // else's audio on their profile).
        foreach (PilotScene::acts() as $act) {
            if ($act['real'] && isset($act['music_url'])) {
                $this->assertMatchesRegularExpression('/^https:\/\/[a-z0-9-]+\.bandcamp\.com$/', $act['music_url'], $act['name']);
            }
        }

        // A support section is an invitation to pay. Saying a real act
        // asks for money -- anywhere, let alone at a URL nobody verified --
        // is a stronger attribution than listing a link, so it stays on
        // fictional entries only, like posts and reports.
        foreach (PilotScene::acts() as $act) {
            if ($act['real']) {
                $this->assertArrayNotHasKey(
                    'support',
                    $act,
                    "{$act['name']}: a real act can't be shown asking to be paid.",
                );
            }
        }
    }

    public function test_the_seeded_moderation_demo_only_touches_fictional_acts(): void
    {
        // The seeder draws its posts and report subjects from this list;
        // every name on it must be marked fictional in the dataset.
        foreach (PilotScene::fictionalActNames() as $name) {
            $act = Arr::first(PilotScene::acts(), fn (array $candidate) => $candidate['name'] === $name);

            $this->assertNotNull($act);
            $this->assertFalse($act['real']);
        }

        $this->assertGreaterThanOrEqual(5, count(PilotScene::fictionalActNames()),
            'The moderation/posts demo needs a handful of fictional acts to hang its states on.');
    }
}
