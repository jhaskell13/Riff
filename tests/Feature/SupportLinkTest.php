<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\ArtistSupportLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupportLinkTest extends TestCase
{
    use RefreshDatabase;

    private function member(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    #[Test]
    public function a_member_saves_the_support_section(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [
                    ['id' => null, 'type' => 'bandcamp', 'url' => 'https://band.bandcamp.com', 'label' => ''],
                    ['id' => null, 'type' => 'tip_jar', 'url' => 'https://ko-fi.com/band', 'label' => 'Buy us a van'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $links = $artist->supportLinks()->get();

        $this->assertCount(2, $links);
        $this->assertSame('bandcamp', $links[0]->type);
        $this->assertSame(0, $links[0]->position);
        // An empty label stores as null, so the type's own label is used.
        $this->assertNull($links[0]->label);
        $this->assertSame('Bandcamp', $links[0]->display_label);
        $this->assertSame('Buy us a van', $links[1]->display_label);
        $this->assertSame(1, $links[1]->position);
    }

    #[Test]
    public function a_non_member_cannot_touch_the_support_section(): void
    {
        $artist = Artist::factory()->create();
        $link = ArtistSupportLink::factory()->for($artist)->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('artists.support.update', $artist), [
                'links' => [['id' => $link->id, 'type' => 'tip_jar', 'url' => 'https://ko-fi.com/thief']],
            ])
            ->assertForbidden();

        $this->assertSame($link->url, $link->fresh()->url);
    }

    #[Test]
    public function a_guest_cannot_touch_the_support_section(): void
    {
        $artist = Artist::factory()->create();

        $this->patch(route('artists.support.update', $artist), ['links' => []])
            ->assertRedirect(route('login'));
    }

    #[Test]
    public function editing_keeps_the_click_count_of_a_link_that_stayed(): void
    {
        // The counter is the whole point of the feature; a typo fix or a
        // reorder must not reset it.
        $artist = Artist::factory()->create();
        $link = ArtistSupportLink::factory()->for($artist)->create([
            'url' => 'https://band.bandcamp.com',
            'clicks' => 42,
        ]);

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [
                    ['id' => $link->id, 'type' => 'bandcamp', 'url' => 'https://band.bandcamp.com/album/one', 'label' => 'Our record'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $fresh = $link->fresh();

        $this->assertNotNull($fresh);
        $this->assertSame(42, $fresh->clicks);
        $this->assertSame('https://band.bandcamp.com/album/one', $fresh->url);
    }

    #[Test]
    public function an_id_belonging_to_another_artist_is_never_updated(): void
    {
        // The form's ids are a hint for matching, not an authorization
        // boundary: a foreign id must become a new link, never an edit.
        $artist = Artist::factory()->create();
        $other = Artist::factory()->create();
        $foreign = ArtistSupportLink::factory()->for($other)->create([
            'url' => 'https://other.bandcamp.com',
        ]);

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [
                    ['id' => $foreign->id, 'type' => 'tip_jar', 'url' => 'https://ko-fi.com/hijack'],
                ],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('https://other.bandcamp.com', $foreign->fresh()->url);
        $this->assertSame($other->id, $foreign->fresh()->artist_id);
        $this->assertSame('https://ko-fi.com/hijack', $artist->supportLinks()->sole()->url);
    }

    #[Test]
    public function removing_a_link_deletes_it(): void
    {
        $artist = Artist::factory()->create();
        $kept = ArtistSupportLink::factory()->for($artist)->create(['position' => 0]);
        $dropped = ArtistSupportLink::factory()->for($artist)->tipJar()->create(['position' => 1]);

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [['id' => $kept->id, 'type' => $kept->type, 'url' => $kept->url]],
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('artist_support_links', ['id' => $kept->id]);
        $this->assertDatabaseMissing('artist_support_links', ['id' => $dropped->id]);
    }

    #[Test]
    public function clearing_the_section_removes_every_link(): void
    {
        $artist = Artist::factory()->create();
        ArtistSupportLink::factory()->for($artist)->count(3)->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), ['links' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, $artist->supportLinks()->count());
    }

    #[Test]
    public function a_link_that_does_not_match_its_type_is_rejected(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [['id' => null, 'type' => 'bandcamp', 'url' => 'https://evil.example.com/band']],
            ])
            ->assertSessionHasErrors('links.0.url');

        $this->assertSame(0, $artist->supportLinks()->count());
    }

    #[Test]
    public function a_dangerous_scheme_is_rejected(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [['id' => null, 'type' => 'store', 'url' => 'javascript:alert(1)']],
            ])
            ->assertSessionHasErrors('links.0.url');
    }

    #[Test]
    public function an_unknown_type_is_rejected(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [['id' => null, 'type' => 'crypto', 'url' => 'https://example.com']],
            ])
            ->assertSessionHasErrors('links.0.type');
    }

    #[Test]
    public function more_links_than_the_cap_are_rejected(): void
    {
        $artist = Artist::factory()->create();
        $max = (int) config('riff.support.max_links');

        $links = [];

        for ($i = 0; $i <= $max; $i++) {
            $links[] = ['id' => null, 'type' => 'store', 'url' => "https://example.com/shop/{$i}"];
        }

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), ['links' => $links])
            ->assertSessionHasErrors('links');

        $this->assertSame(0, $artist->supportLinks()->count());
    }

    #[Test]
    public function the_clicks_column_cannot_be_mass_assigned(): void
    {
        $artist = Artist::factory()->create();

        $this->actingAs($this->member($artist))
            ->patch(route('artists.support.update', $artist), [
                'links' => [[
                    'id' => null,
                    'type' => 'store',
                    'url' => 'https://example.com/shop',
                    'clicks' => 9999,
                    'artist_id' => 123,
                ]],
            ])
            ->assertSessionHasNoErrors();

        $link = $artist->supportLinks()->sole();

        $this->assertSame(0, $link->clicks);
        $this->assertSame($artist->id, $link->artist_id);
    }

    #[Test]
    public function a_guest_click_is_counted(): void
    {
        // Public on purpose: a shared artist page has to work logged out,
        // so support has to as well.
        $artist = Artist::factory()->create();
        $link = ArtistSupportLink::factory()->for($artist)->create();

        $this->post(route('artists.support.click', ['artist' => $artist->id, 'link' => $link->id]))
            ->assertNoContent();

        $this->assertSame(1, $link->fresh()->clicks);
    }

    #[Test]
    public function a_click_against_the_wrong_artist_is_not_counted(): void
    {
        $artist = Artist::factory()->create();
        $other = Artist::factory()->create();
        $link = ArtistSupportLink::factory()->for($other)->create();

        $this->post(route('artists.support.click', ['artist' => $artist->id, 'link' => $link->id]))
            ->assertNotFound();

        $this->assertSame(0, $link->fresh()->clicks);
    }

    #[Test]
    public function the_public_page_shows_support_links_without_click_counts(): void
    {
        // A public click count would be a popularity number on a page
        // whose whole premise is that popularity does not rank anything.
        $artist = Artist::factory()->create();
        ArtistSupportLink::factory()->for($artist)->create(['clicks' => 77]);

        $response = $this->get(route('artists.show', $artist));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->has('supportLinks', 1)
            ->where('supportLinks.0.typeLabel', 'Bandcamp')
            ->missing('supportLinks.0.clicks'));

        $response->assertDontSee('77');
    }

    #[Test]
    public function members_see_click_counts_on_the_edit_page(): void
    {
        $artist = Artist::factory()->create();
        ArtistSupportLink::factory()->for($artist)->create(['clicks' => 77]);

        $this->actingAs($this->member($artist))
            ->get(route('artists.edit', $artist))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('supportLinks.0.clicks', 77));
    }
}
