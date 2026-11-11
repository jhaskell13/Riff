<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSlug;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The public artist profile: shareable slug URLs that survive renames.
 * Links are permanent (old slugs and old numeric ids both redirect), slugs
 * follow the name, and a guest gets the full page with register prompts in
 * place of account controls.
 */
class ArtistPublicProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_can_view_an_artist_profile(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);

        $this->get(route('artists.show', $artist))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ArtistZone/Show')
                ->where('artist.name', 'Gutter Hymns')
                ->where('canManage', false)
                ->where('canFollow', false)
                ->where('canBlock', false));
    }

    public function test_an_unverified_user_can_view_an_artist_profile(): void
    {
        $artist = Artist::factory()->create();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)->get(route('artists.show', $artist))->assertOk();
    }

    public function test_a_signed_in_viewer_gets_account_controls(): void
    {
        $artist = Artist::factory()->create();
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get(route('artists.show', $artist))
            ->assertInertia(fn ($page) => $page
                ->where('canFollow', true)
                ->where('canManage', false));
    }

    public function test_a_numeric_id_url_redirects_permanently_to_the_slug(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);

        $this->get("/artists/{$artist->id}")
            ->assertMovedPermanently()
            ->assertRedirect(route('artists.show', $artist));

        $this->assertSame('gutter-hymns', $artist->fresh()->slug);
    }

    public function test_a_rename_changes_the_slug_and_keeps_the_old_url_working(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $oldUrl = route('artists.show', $artist);

        $artist->update(['name' => 'Sewer Psalms']);

        $this->assertSame('sewer-psalms', $artist->slug);

        $this->get($oldUrl)
            ->assertMovedPermanently()
            ->assertRedirect(route('artists.show', $artist));

        $this->get(route('artists.show', $artist))->assertOk();
    }

    public function test_renaming_back_reclaims_the_original_slug(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);

        $artist->update(['name' => 'Sewer Psalms']);
        $artist->update(['name' => 'Gutter Hymns']);

        $this->assertSame('gutter-hymns', $artist->fresh()->slug);
        // No redirect row may point the reclaimed slug anywhere else.
        $this->assertDatabaseMissing('artist_slugs', ['slug' => 'gutter-hymns']);
    }

    public function test_slug_collisions_get_a_numeric_suffix(): void
    {
        $first = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $second = Artist::factory()->create(['name' => 'Gutter Hymns']);

        $this->assertSame('gutter-hymns', $first->slug);
        $this->assertSame('gutter-hymns-2', $second->slug);
    }

    public function test_a_parked_slug_is_not_given_to_another_artist(): void
    {
        $renamed = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $renamed->update(['name' => 'Sewer Psalms']);

        $newcomer = Artist::factory()->create(['name' => 'Gutter Hymns']);

        // The old URL still belongs to the renamed artist's redirect.
        $this->assertSame('gutter-hymns-2', $newcomer->slug);
        $this->assertSame($renamed->id, ArtistSlug::where('slug', 'gutter-hymns')->firstOrFail()->artist_id);
    }

    public function test_an_all_digit_name_never_yields_an_all_digit_slug(): void
    {
        // A purely numeric slug would be shadowed by the /artists/{id}
        // redirect route and unreachable.
        $artist = Artist::factory()->create(['name' => '1994', 'artist_type' => 'band']);

        $this->assertSame('1994-band', $artist->slug);
        $this->get(route('artists.show', $artist))->assertOk();
    }

    public function test_an_archived_artist_is_not_publicly_visible(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $url = route('artists.show', $artist);
        $artist->delete();

        $this->get($url)->assertNotFound();
        $this->get("/artists/{$artist->id}")->assertNotFound();
    }

    public function test_the_slug_cannot_be_mass_assigned(): void
    {
        $artist = Artist::factory()->create(['name' => 'Gutter Hymns']);
        $member = User::factory()->create();
        $member->artists()->attach($artist);

        $this->actingAs($member)->patch(route('artists.update', $artist), [
            'name' => $artist->name,
            'artist_type' => $artist->artist_type,
            'hometown' => $artist->hometown,
            'stateOrProvince' => $artist->stateOrProvince,
            'country' => $artist->country,
            'show_on_map' => $artist->show_on_map,
            'slug' => 'hijacked',
        ]);

        $this->assertSame('gutter-hymns', $artist->fresh()->slug);
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->get('/artists/never-played-here')->assertNotFound();
    }
}
