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
use Database\Seeders\Support\PilotScene;
use Database\Seeders\Support\PlaceholderPhoto;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * The pilot scene: Greater Hartford and the towns around it, seeded from
 * the researched dataset in database/seeders/data/pilot-scene.json — real
 * Connecticut acts (factual bios, verified links) plus a handful of
 * invented ones. Idempotent: an act that already exists by name is left
 * alone. Demo and local development only; see the JSON's _meta.
 *
 * Consent boundary: anything that *attributes* something to an act — feed
 * posts in its voice, entries in the report queue, hidden/archived states —
 * is seeded onto the fictional acts only. Real acts get a profile, a
 * factual bio, generated placeholder art (never their real photos), and
 * only music links verified during research.
 */
class PilotSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(GenreSeeder::class);

        $genreIds = Genre::pluck('id', 'name');
        $artists = collect();

        foreach (PilotScene::acts() as $act) {
            $existing = Artist::withTrashed()->firstWhere('name', $act['name']);

            if ($existing !== null) {
                $artists->push($existing);

                continue;
            }

            $town = Towns::named($act['town']);

            $artist = Artist::factory()
                // Placeholder tracks only on fictional acts; real acts get
                // their own verified link or none at all.
                ->when($act['placeholder_music'] ?? false, fn ($factory) => $factory->withMusic())
                ->create([
                    'name' => $act['name'],
                    'artist_type' => $act['type'],
                    'hometown' => $town['city'],
                    'stateOrProvince' => $town['state'],
                    'country' => 'USA',
                    'lat' => $town['lat'],
                    'lng' => $town['lng'],
                    'biography' => $act['bio'],
                    'show_on_map' => ! ($act['hidden_from_map'] ?? false),
                    ...(isset($act['music_url']) ? ['music_url' => $act['music_url']] : []),
                ]);

            $artist->genres()->sync($genreIds->only($act['genres'])->values()->all());

            PlaceholderPhoto::attach($artist);

            // A support section is an invitation to pay, which is a
            // stronger attribution than listing a link -- so, like posts
            // and reports, it is seeded onto fictional acts only. The
            // dataset enforces that; this asserts it at the point of use.
            if ($act['support'] ?? false) {
                $this->supportLinks($artist, $act);
            }

            $artist->members()->attach($this->memberFor($artist, $town));

            if ($act['archived'] ?? false) {
                $artist->delete();
            }

            $artists->push($artist);
        }

        $onMap = $artists->filter(fn (Artist $artist) => $artist->show_on_map && ! $artist->trashed());
        $fictional = $onMap->whereIn('name', PilotScene::fictionalActNames());

        $this->listeners($onMap);
        // Posts speak as the act and reports accuse one: fictional only.
        $this->posts($fictional);
        $this->reports($fictional);
    }

    /**
     * Where a (fictional) act asks to be supported. Click counts are
     * invented, and exist so the Artist Zone has something to show.
     *
     * @param  array<string, mixed>  $act
     */
    private function supportLinks(Artist $artist, array $act): void
    {
        if ($act['real'] !== false) {
            throw new \LogicException("{$act['name']}: support links may only be seeded onto fictional acts.");
        }

        foreach (array_values($act['support']) as $position => $link) {
            $artist->supportLinks()->create([
                'type' => $link['type'],
                'url' => $link['url'],
                'label' => $link['label'] ?? null,
                'position' => $position,
                // Not fillable, by design: a request may never set this.
            ])->forceFill(['clicks' => (int) ($link['clicks'] ?? 0)])->save();
        }
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
