<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Report;
use App\Models\User;
use App\Services\SupportLink;
use Database\Factories\Support\Towns;
use Database\Seeders\PilotSeeder;
use Database\Seeders\Support\PilotScene;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * The pilot dataset's own rules (see `_meta` in
 * database/seeders/data/pilot-scene.json).
 *
 * **The rules changed in November 2026** (owner decision): real, already
 * public information about real acts is now in scope, because a recruiting
 * demo built from the actual Connecticut scene is worth more than one
 * built from invented names. Bios, members, links and venue addresses are
 * all public facts, and none of them is private.
 *
 * What did *not* change, and what this file mostly exists to enforce:
 *
 *  - **No contact details, ever.** No real email, phone or private address
 *    in the file, and every seeded account on an RFC-reserved example.*
 *    domain, so a demo digest or notification cannot reach a real inbox.
 *    This is the rule the owner stated outright, and it is the one that
 *    would actually hurt somebody if it broke.
 *  - **No real DIY space.** A club's address is public; a DIY space's is
 *    not, and that is the whole reason private venues exist in Riff.
 *  - Invented links never point anywhere a stranger could own.
 */
class PilotDatasetTest extends TestCase
{
    use RefreshDatabase;

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

        foreach (PilotScene::acts() as $act) {
            $this->assertArrayHasKey($act['type'], config('riff.artist.types'), $act['name']);
            $this->assertNotEmpty(Towns::named($act['town']), $act['name']);

            foreach ($act['genres'] as $genre) {
                $this->assertContains($genre, config('riff.genres'), "{$act['name']}: {$genre}");
            }

            foreach ($act['support'] ?? [] as $link) {
                $this->assertNotNull(
                    SupportLink::parse($link['url'], $link['type']),
                    "{$act['name']}: {$link['url']} is not a valid {$link['type']} link.",
                );
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

        foreach (PilotScene::listings() as $listing) {
            $this->assertArrayHasKey($listing['type'], config('riff.listings.types'));

            if ($listing['artist'] !== null) {
                $this->assertContains($listing['artist'], $actNames);
            }
        }
    }

    /**
     * The rule the owner stated outright: real data is fine, but nothing
     * that could put a demo in a real person's inbox.
     */
    public function test_the_dataset_carries_no_contact_details(): void
    {
        $raw = json_encode(PilotScene::data());

        $this->assertIsString($raw);

        // No email address anywhere in the file, on any entry, real or not.
        $this->assertDoesNotMatchRegularExpression(
            '/[\w.+-]+@[\w-]+\.[\w.]+/',
            $raw,
            'The dataset must never carry an email address.',
        );

        // No phone numbers either.
        $this->assertDoesNotMatchRegularExpression(
            '/\(?\d{3}\)?[\s.-]\d{3}[\s.-]\d{4}/',
            $raw,
            'The dataset must never carry a phone number.',
        );

        // And no act carries a contact field even if one were added.
        foreach (PilotScene::acts() as $act) {
            $this->assertArrayNotHasKey('email', $act, $act['name']);
            $this->assertArrayNotHasKey('phone', $act, $act['name']);
        }
    }

    /**
     * The protection behind that rule: every seeded account is on an
     * RFC-2606 reserved domain, which cannot be registered and therefore
     * cannot receive mail. A digest run against the demo database is
     * physically incapable of reaching a real person.
     */
    public function test_every_seeded_account_is_unreachable_by_email(): void
    {
        $this->seed(PilotSeeder::class);

        $addresses = User::query()->pluck('email');

        $this->assertNotEmpty($addresses);

        foreach ($addresses as $address) {
            $this->assertMatchesRegularExpression(
                '/@(.+\.)?example\.(com|net|org)$/i',
                (string) $address,
                "Seeded account {$address} is on a domain that could receive real mail.",
            );
        }
    }

    public function test_no_artist_is_seeded_with_a_contact_address(): void
    {
        $this->seed(PilotSeeder::class);

        $this->assertSame(
            0,
            Artist::query()->whereNotNull('email')->count(),
            'A seeded artist carries a contact address.',
        );
    }

    public function test_real_acts_only_carry_links_on_their_own_pages(): void
    {
        // Real data means *their* data: a link attributed to a real act
        // must be one they actually publish, not a guess.
        foreach (PilotScene::acts() as $act) {
            if (! $act['real']) {
                continue;
            }

            foreach (array_filter([$act['music_url'] ?? null]) as $url) {
                $this->assertMatchesRegularExpression(
                    '#^https://[a-z0-9-]+\.bandcamp\.com$#',
                    $url,
                    "{$act['name']}: a real act's music link must be their own Bandcamp page.",
                );
            }

            foreach ($act['support'] ?? [] as $link) {
                $this->assertSame(
                    'bandcamp',
                    $link['type'],
                    "{$act['name']}: a real act's support links are limited to their own Bandcamp.",
                );
            }
        }
    }

    public function test_invented_acts_never_link_anywhere_a_stranger_could_own(): void
    {
        // "The Silver Mill" is exactly the kind of name a real band may
        // already have taken on Bandcamp or Ko-fi.
        foreach (PilotScene::acts() as $act) {
            if ($act['real']) {
                continue;
            }

            foreach ($act['support'] ?? [] as $link) {
                $host = (string) parse_url($link['url'], PHP_URL_HOST);
                $path = trim((string) parse_url($link['url'], PHP_URL_PATH), '/');

                $reserved = preg_match('/(^|\.)example\.(com|net|org)$/', $host) === 1;
                $bareService = $path === '' && ! str_contains($host, '.bandcamp.com');

                $this->assertTrue(
                    $reserved || $bareService,
                    "{$act['name']}: {$link['url']} could belong to a real person.",
                );
            }
        }
    }

    public function test_no_real_diy_space_is_listed(): void
    {
        // A club's street address is public; a DIY space's is not, which
        // is the entire reason private venues exist in Riff.
        foreach (PilotScene::venues() as $venue) {
            if ($venue['kind'] === 'diy' || $venue['street'] === null) {
                $this->assertFalse($venue['real'], "{$venue['name']}: a private/DIY venue must be fictional.");
            }

            if ($venue['real']) {
                $this->assertNotNull($venue['street'], "{$venue['name']}: a real venue is listed at its public address or not at all.");
            }
        }
    }

    public function test_invented_states_stay_on_invented_entries(): void
    {
        // Being reported, hidden, archived, or shown turning down a bill
        // are judgements, not public facts -- they stay on the entries
        // kept for exactly that purpose.
        $real = $this->realActNames();

        foreach (PilotScene::acts() as $act) {
            if ($act['real']) {
                $this->assertArrayNotHasKey('hidden_from_map', $act, $act['name']);
                $this->assertArrayNotHasKey('archived', $act, $act['name']);
                $this->assertArrayNotHasKey('placeholder_music', $act, $act['name']);
            }
        }

        foreach (PilotScene::shows() as $show) {
            foreach ($show['pending'] ?? [] as $act) {
                $this->assertNotContains($act, $real, "{$act}: shown as not having agreed to a bill.");
            }

            if ($show['cancelled'] ?? false) {
                $this->assertNotContains($show['host'], $real, "{$show['host']}: shown cancelling.");
            }
        }
    }

    public function test_the_seeded_moderation_demo_only_touches_fictional_acts(): void
    {
        // Asserted against the seeded database rather than the file: what
        // matters is that no real act ends up *in the report queue*, which
        // is a judgement about them and not a public fact.
        $this->seed(PilotSeeder::class);

        $reported = Report::query()
            ->where('reportable_type', (new Artist)->getMorphClass())
            ->pluck('reportable_id');

        $this->assertNotEmpty($reported, 'The demo report queue is empty.');

        $names = Artist::withTrashed()->whereKey($reported)->pluck('name');

        foreach ($names as $name) {
            $this->assertContains($name, PilotScene::fictionalActNames(), $name);
        }
    }
}
