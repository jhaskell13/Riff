<?php

namespace Database\Seeders;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\Post;
use App\Models\Report;
use App\Models\User;
use App\Support\ModerationAction;
use App\Support\OnboardingIntent;
use App\Support\ReportStatus;
use Database\Factories\Support\Towns;
use Database\Seeders\Support\PlaceholderPhoto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * One local scene, dense enough to demonstrate Riff with: Greater Hartford,
 * several acts per town, plus listeners, posts, and a report queue in both
 * states. Idempotent -- an act that already exists by name is left alone.
 *
 * Every act is invented on purpose. Real local bands never agreed to be
 * listed, and the music links are public placeholders, not these acts' own
 * work; a real pilot import must carry neither.
 */
class PilotSeeder extends Seeder
{
    /**
     * name, type, town, genres, bio, has playable link. Several acts share
     * a town on purpose -- same-town clustering needs something to cluster.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string, 5: bool}>
     */
    private const ACTS = [
        ['The Paper Harbor', 'band', 'Hartford', ['Indie', 'Rock'],
            'Four-piece out of Hartford. We played our first show at a pizza place on Park Street and have been trying to get back to that energy ever since.', true],
        ['Quiet Transit', 'band', 'Hartford', ['Post-Rock', 'Ambient'],
            'Instrumental, loud at the end. We rehearse in a converted office above a print shop downtown.', true],
        ['Marisol Vega', 'solo', 'Hartford', ['Latin', 'Soul'],
            'Singer and guitarist. I grew up between Hartford and Ponce and the songs come out somewhere in the middle.', true],
        ['DJ Lowtide', 'dj', 'Hartford', ['House', 'Electronic'],
            'Records every other Friday at a bar on Pratt Street. Mostly house, some disco, nothing before midnight.', true],
        ['Asylum Hill Choir', 'band', 'Hartford', ['Gospel', 'Soul'],
            'Twelve voices, one organ, no amplification worth mentioning. We sing at churches and community halls across the city.', false],
        ['Mirrorwork', 'producer', 'Hartford', ['Techno', 'Electronic'],
            'Hardware techno made in a storage unit. Profile is here; the pin is not, by choice.', true],

        ['Northern Avenue', 'band', 'West Hartford', ['Americana', 'Folk'],
            'Three of us, two guitars, and a pedal steel borrowed indefinitely. Songs about the Connecticut River and the people who leave it.', true],
        ['Cassette Beats', 'producer', 'West Hartford', ['Hip-Hop', 'Electronic'],
            'Sample-led beats, mostly made between night shifts. Open to vocalists -- send me something.', true],

        ['The Silver Mill', 'band', 'New Britain', ['Punk', 'Hardcore'],
            'Fast, short, loud. We book our own shows in basements and VFW halls and split the door evenly.', true],
        ['Benny R.', 'rapper', 'New Britain', ['Hip-Hop'],
            'New Britain raised. Two tapes down, third one coming whenever it is finished. Everything recorded in my brother\'s bedroom.', true],
        ['Hollow Season', 'band', 'New Britain', ['Emo', 'Shoegaze'],
            'Loud guitars and quiet singing, in that order. Started as a two-piece in a dorm and grew by one every year.', false],

        ['Wire & Orchard', 'band', 'Middletown', ['Folk', 'Bluegrass'],
            'Banjo, fiddle, upright bass, and whoever else turns up. We play porches, farmers markets, and the occasional actual venue.', true],
        ['Glass Parade', 'band', 'Middletown', ['Alternative', 'Indie'],
            'Formed out of two bands that broke up the same summer. New record tracked live in a barn in Durham.', true],

        ['Tapehead Audio', 'producer', 'Manchester', ['Experimental', 'Ambient'],
            'Tape loops and broken pedals. I also mix for other local acts, which pays better than this does.', false],
        ['The Crooked Pines', 'band', 'Manchester', ['Country', 'Americana'],
            'Honky-tonk in a state that has no business with it. Weekly residency at a place that lets us play too long.', true],

        ['Delia Hart', 'solo', 'Bristol', ['Singer-Songwriter', 'Folk'],
            'Songs written at a kitchen table in Bristol, played anywhere that will have them. Happy to drive an hour for a show.', true],
        ['Static Union', 'band', 'Bristol', ['Metal', 'Rock'],
            'Four people who met at a guitar shop. We are louder than the room every time, and we are working on that.', false],

        ['Sunken Radio', 'band', 'Glastonbury', ['Shoegaze', 'Indie'],
            'Two guitars through more reverb than is reasonable. Recording at home, mixing badly, enjoying it.', true],
        ['Jo Prentice', 'solo', 'Glastonbury', ['Jazz', 'Blues'],
            'Piano and voice, standards and originals. Thirty years of weddings has not worn it off yet.', true],

        ['The Second Hour', 'band', 'Meriden', ['Jazz', 'Funk'],
            'A horn section that plays whatever the rhythm section starts. We are a different band at 1am than at 10pm.', true],
        ['Aperture', 'producer', 'Meriden', ['Ambient', 'Electronic'],
            'Long-form ambient records made overnight. Always looking for people to play with.', false],

        ['Campus Radio Ghosts', 'band', 'Storrs', ['Indie', 'Experimental'],
            'We met at the student station and never gave the keys back. Shows most months -- check the socials.', true],

        ['Thread City Ska', 'band', 'Willimantic', ['Ska', 'Reggae'],
            'Eight of us, named for the mills. If there is a street festival within an hour of Willimantic, we have played it.', true],

        ['Pale Lantern', 'band', 'Enfield', ['Alternative', 'Post-Rock'],
            'Quiet band, slow songs. We keep a profile for bookings but we would rather not be a pin on a map.', false],

        ['The Winter Ledger', 'band', 'Wallingford', ['Indie', 'Folk'],
            'On hold while two of us are out of state. Archived rather than deleted, which is the point of archiving.', false],
    ];

    /** Acts that keep a profile but no pin, to show that the choice exists. */
    private const HIDDEN_FROM_MAP = ['Mirrorwork', 'Pale Lantern'];

    /** Archived (soft-deleted), recoverable from the Artist Zone. */
    private const ARCHIVED = ['The Winter Ledger'];

    public function run(): void
    {
        $this->call(GenreSeeder::class);

        $genreIds = Genre::pluck('id', 'name');
        $artists = collect();

        foreach (self::ACTS as [$name, $type, $townName, $genres, $bio, $hasMusic]) {
            $existing = Artist::withTrashed()->firstWhere('name', $name);

            if ($existing !== null) {
                $artists->push($existing);

                continue;
            }

            $town = Towns::named($townName);

            $artist = Artist::factory()
                ->when($hasMusic, fn ($factory) => $factory->withMusic())
                ->create([
                    'name' => $name,
                    'artist_type' => $type,
                    'hometown' => $town['city'],
                    'stateOrProvince' => $town['state'],
                    'country' => 'USA',
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'biography' => $bio,
                    'show_on_map' => ! in_array($name, self::HIDDEN_FROM_MAP, true),
                ]);

            $artist->genres()->sync($genreIds->only($genres)->values()->all());

            PlaceholderPhoto::attach($artist);

            $artist->members()->attach($this->memberFor($artist, $town));

            if (in_array($name, self::ARCHIVED, true)) {
                $artist->delete();
            }

            $artists->push($artist);
        }

        $onMap = $artists->filter(fn (Artist $artist) => $artist->show_on_map && ! $artist->trashed());

        $this->listeners($onMap);
        $this->posts($onMap);
        $this->reports($onMap);
    }

    /** The member account behind an act, local to the same town. */
    private function memberFor(Artist $artist, array $town): User
    {
        return User::factory()->create([
            'city' => $town['city'],
            'state' => $town['state'],
            'lat' => $town['lat'],
            'lng' => $town['lng'],
            'onboarding_intent' => OnboardingIntent::Artist,
        ]);
    }

    /**
     * Local listeners whose follows make the cards feel inhabited.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function listeners(Collection $artists): void
    {
        if ($artists->isEmpty() || User::where('onboarding_intent', OnboardingIntent::Fan)->exists()) {
            return;
        }

        $towns = ['Hartford', 'West Hartford', 'New Britain', 'Middletown', 'Manchester', 'Bristol', 'Glastonbury'];

        foreach (range(1, 18) as $ignored) {
            $town = Towns::named(Arr::random($towns));

            User::factory()
                ->create([
                    'city' => $town['city'],
                    'state' => $town['state'],
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'onboarding_intent' => OnboardingIntent::Fan,
                ])
                ->followedArtists()
                ->sync($artists->random(rand(2, min(7, $artists->count())))->pluck('id'));
        }
    }

    /**
     * Posts by the acts themselves: a member posting as the artist.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function posts(Collection $artists): void
    {
        $lines = [
            'New single is up. Thanks to everyone who came out to the release show.',
            'Practice space flooded. If anyone has a room in the area for a month, we are asking.',
            'Playing Friday, doors at 8. Three bands, five dollars, bring a friend.',
            'Demos are finally mixed. Streaming everywhere this week.',
            'Looking for a drummer who can make Thursdays work.',
            'Recorded live to tape yesterday and kept the mistakes.',
            'Our van passed inspection. Tour is back on.',
            'Open mic tonight if you want to try something new on a kind room.',
            'Added a second date because the first sold out. Still cannot quite believe that.',
            'Thanks to the crowd last night for singing the second verse when I forgot it.',
        ];

        foreach ($artists->random(min(10, $artists->count())) as $index => $artist) {
            $member = $artist->members()->first();

            if ($member === null) {
                continue;
            }

            Post::factory()->create([
                'user_id' => $member->getKey(),
                'artist_id' => $artist->id,
                'content' => $lines[$index % count($lines)],
                'published_at' => now()->subDays(rand(0, 21))->subHours(rand(0, 23)),
            ]);
        }
    }

    /**
     * Open complaints and one settled, anchored on pilot acts.
     *
     * @param  Collection<int, Artist>  $artists
     */
    private function reports(Collection $artists): void
    {
        if ($artists->count() < 3 || Report::query()->exists()) {
            return;
        }

        $reporters = User::where('onboarding_intent', OnboardingIntent::Fan)->take(3)->get();

        if ($reporters->isEmpty()) {
            return;
        }

        $subjects = $artists->random(3)->values();

        $complaints = [
            ['impersonation', 'This is not them. The real band has a different line-up and they have asked people to stop.'],
            ['wrong_location', 'They are not in this town any more, they moved last year.'],
            ['spam', 'Same copy-and-paste message under every local act.'],
        ];

        foreach ($complaints as $index => [$reason, $details]) {
            Report::factory()->about($subjects[$index])->create([
                'reporter_id' => $reporters[$index % $reporters->count()]->id,
                'reason' => $reason,
                'details' => $details,
                'status' => ReportStatus::Open,
                'created_at' => now()->subDays(3 - $index),
            ]);
        }

        $post = Post::query()->whereNotNull('artist_id')->first();

        if ($post !== null) {
            Report::factory()->about($post)->create([
                'reporter_id' => $reporters->first()->id,
                'reason' => 'not_music',
                'details' => 'This is an advert for a phone repair shop.',
                'status' => ReportStatus::Open,
            ]);
        }

        $moderator = User::where('is_moderator', true)->first();

        if ($moderator !== null) {
            Report::factory()->about($artists->random())->create([
                'reporter_id' => $reporters->first()->id,
                'reason' => 'other',
                'details' => 'Reported by mistake, please ignore.',
                'status' => ReportStatus::Dismissed,
                'resolution' => ModerationAction::Dismiss,
                'resolved_by_id' => $moderator->id,
                'resolved_at' => now()->subDay(),
                'moderator_note' => 'Nothing wrong with the profile; reporter withdrew it.',
            ]);
        }
    }
}
