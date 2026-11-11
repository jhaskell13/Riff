<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArtistZoneTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    /**
     * A complete, valid profile payload. Tests override only the field
     * under test, so adding a required field does not scatter edits.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'The Testers',
            'artist_type' => 'band',
            'genres' => [Genre::firstOrFail()->id],
            'hometown' => 'Hartford',
            'stateOrProvince' => 'CT',
            'country' => 'USA',
            'show_on_map' => true,
        ], $overrides);
    }

    /**
     * The same payload but describing an existing artist, for updates.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payloadFor(Artist $artist, array $overrides = []): array
    {
        return $this->profilePayload(array_merge([
            'name' => $artist->name,
            'artist_type' => $artist->artist_type,
            'genres' => $artist->genres->pluck('id')->all(),
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
            'show_on_map' => $artist->show_on_map,
        ], $overrides));
    }

    public function test_guests_are_redirected_to_login_for_zone_pages(): void
    {
        $artist = Artist::factory()->create();

        // The public profile is covered in ArtistPublicProfileTest; the
        // zone (management) stays members-only.
        $this->get(route('artists.index'))->assertRedirect(route('login'));
        $this->get(route('artists.edit', $artist))->assertRedirect(route('login'));
    }

    public function test_a_user_can_create_an_artist_and_becomes_a_member(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('artists.store'), $this->profilePayload([
            'biography' => 'A band formed in a test suite.',
        ]));

        $artist = Artist::where('name', 'The Testers')->firstOrFail();

        $response->assertRedirect(route('artists.show', $artist));
        $this->assertTrue($user->isMemberOf($artist));
    }

    public function test_any_verified_user_can_view_an_artist_profile(): void
    {
        $artist = Artist::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('artists.show', $artist))
            ->assertOk();
    }

    public function test_a_member_can_update_their_artist(): void
    {
        $artist = Artist::factory()->create(['name' => 'Old Name']);
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->patch(route('artists.update', $artist), $this->payloadFor($artist, ['name' => 'New Name']))
            // The redirect carries the fresh slug — a rename changes it.
            ->assertRedirect(route('artists.show', $artist->fresh()));

        $this->assertSame('New Name', $artist->fresh()->name);
    }

    public function test_a_non_member_cannot_update_an_artist(): void
    {
        $artist = Artist::factory()->create(['name' => 'Untouched']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->patch(route('artists.update', $artist), $this->payloadFor($artist, ['name' => 'Hijacked']))
            ->assertForbidden();

        $this->assertSame('Untouched', $artist->fresh()->name);
    }

    public function test_a_non_member_cannot_view_the_edit_page(): void
    {
        $artist = Artist::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->get(route('artists.edit', $artist))
            ->assertForbidden();
    }

    public function test_a_member_can_archive_their_artist(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->delete(route('artists.destroy', $artist))
            ->assertRedirect(route('artists.index'));

        $this->assertSoftDeleted($artist);
    }

    public function test_a_non_member_cannot_archive_an_artist(): void
    {
        $artist = Artist::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->delete(route('artists.destroy', $artist))
            ->assertForbidden();

        $this->assertNotSoftDeleted($artist);
    }

    public function test_a_member_can_restore_an_archived_artist(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);
        $artist->delete();

        $this->actingAs($member)
            ->post(route('artists.restore', $artist))
            ->assertRedirect(route('artists.show', $artist));

        $this->assertNotSoftDeleted($artist);
    }

    public function test_a_non_member_cannot_restore_an_archived_artist(): void
    {
        $artist = Artist::factory()->create();
        $this->memberOf($artist);
        $artist->delete();

        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('artists.restore', $artist))
            ->assertForbidden();

        $this->assertSoftDeleted($artist);
    }

    public function test_the_index_lists_a_members_archived_artists(): void
    {
        $artist = Artist::factory()->create(['name' => 'Shelved Band']);
        $member = $this->memberOf($artist);
        $artist->delete();

        $this->actingAs($member)
            ->get(route('artists.index'))
            ->assertInertia(fn ($page) => $page
                ->has('artists', 0)
                ->has('archivedArtists', 1)
                ->where('archivedArtists.0.name', 'Shelved Band')
            );
    }

    public function test_an_archived_artist_profile_is_not_accessible(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);
        $artist->delete();

        $this->actingAs($member)
            ->get(route('artists.show', $artist))
            ->assertNotFound();
    }

    public function test_update_rejects_invalid_website_and_email(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->patch(route('artists.update', $artist), $this->payloadFor($artist, [
                'website' => 'not-a-url',
                'email' => 'not-an-email',
            ]))
            ->assertSessionHasErrors(['website', 'email']);
    }

    public function test_an_artist_keeps_its_genres_and_type(): void
    {
        $user = User::factory()->create();
        $genres = Genre::query()->limit(2)->pluck('id');

        $this->actingAs($user)->post(route('artists.store'), $this->profilePayload([
            'artist_type' => 'producer',
            'genres' => $genres->all(),
        ]));

        $artist = Artist::where('name', 'The Testers')->firstOrFail();

        $this->assertSame('producer', $artist->artist_type);
        $this->assertEqualsCanonicalizing($genres->all(), $artist->genres->pluck('id')->all());
    }

    public function test_updating_genres_replaces_rather_than_appends(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);
        $replacement = Genre::query()->whereNotIn('id', $artist->genres->pluck('id'))->firstOrFail();

        $this->actingAs($member)->patch(
            route('artists.update', $artist),
            $this->payloadFor($artist, ['genres' => [$replacement->id]]),
        );

        $this->assertSame([$replacement->id], $artist->fresh()->genres->pluck('id')->all());
    }

    public function test_more_genres_than_the_cap_are_rejected(): void
    {
        $user = User::factory()->create();
        $tooMany = Genre::query()->limit(config('riff.artist.max_genres') + 1)->pluck('id');

        $this->actingAs($user)
            ->post(route('artists.store'), $this->profilePayload(['genres' => $tooMany->all()]))
            ->assertSessionHasErrors('genres');
    }

    public function test_an_unknown_artist_type_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('artists.store'), $this->profilePayload(['artist_type' => 'orchestra']))
            ->assertSessionHasErrors('artist_type');
    }

    public function test_a_recognised_music_link_is_stored_with_its_provider(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.store'), $this->profilePayload([
            'music_url' => 'https://open.spotify.com/intl-de/track/4cOdK2wGLETKBW3PvgPWqT?si=abc',
        ]));

        $artist = Artist::where('name', 'The Testers')->firstOrFail();

        // The link is normalised to its canonical form, and the provider is
        // derived rather than trusted from the request.
        $this->assertSame('https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT', $artist->music_url);
        $this->assertSame('spotify', $artist->music_provider);
        $this->assertSame(
            'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
            $artist->music_embed['embed_url'],
        );
    }

    public function test_an_unrecognised_music_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('artists.store'), $this->profilePayload([
                'music_url' => 'https://evil.example/player?src=javascript:alert(1)',
            ]))
            ->assertSessionHasErrors('music_url');

        $this->assertDatabaseCount('artists', 0);
    }

    public function test_a_client_cannot_set_the_music_provider_directly(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.store'), $this->profilePayload([
            'music_provider' => 'spotify',
        ]));

        $this->assertNull(Artist::firstOrFail()->music_provider);
    }

    public function test_links_must_live_on_their_own_platform(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('artists.store'), $this->profilePayload([
                'bandcamp_url' => 'https://evil.example/pretend',
                'spotify_url' => 'https://open.spotify.com/artist/4cOdK2wGLETKBW3PvgPWqT',
            ]))
            ->assertSessionHasErrors('bandcamp_url')
            ->assertSessionDoesntHaveErrors('spotify_url');
    }

    public function test_blank_optional_links_are_accepted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('artists.store'), $this->profilePayload([
                'website' => '',
                'music_url' => '',
                'biography' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(Artist::firstOrFail()->website);
    }

    public function test_an_artist_can_be_hidden_from_the_map_without_losing_its_profile(): void
    {
        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);
        $member = $this->memberOf($artist);

        $this->actingAs($member)->patch(
            route('artists.update', $artist),
            $this->payloadFor($artist, ['show_on_map' => false]),
        );

        $artist->refresh();

        $this->assertFalse($artist->show_on_map);
        // Still geocoded and still viewable -- only the pin is withheld.
        $this->assertSame(41.5, $artist->lat);
        $this->actingAs($member)->get(route('artists.show', $artist))->assertOk();
    }
}
