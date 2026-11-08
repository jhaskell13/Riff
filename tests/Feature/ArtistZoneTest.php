<?php

namespace Tests\Feature;

use App\Models\Artist;
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

    public function test_guests_are_redirected_to_login(): void
    {
        $artist = Artist::factory()->create();

        $this->get(route('artists.show', $artist))->assertRedirect(route('login'));
    }

    public function test_a_user_can_create_an_artist_and_becomes_a_member(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('artists.store'), [
            'name' => 'The Testers',
            'genre' => 'Rock',
            'hometown' => 'Hartford',
            'stateOrProvince' => 'CT',
            'country' => 'USA',
            'biography' => 'A band formed in a test suite.',
        ]);

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
            ->patch(route('artists.update', $artist), [
                'name' => 'New Name',
                'genre' => $artist->genre,
                'hometown' => $artist->hometown,
                'stateOrProvince' => $artist->stateOrProvince,
                'country' => $artist->country,
            ])
            ->assertRedirect(route('artists.show', $artist));

        $this->assertSame('New Name', $artist->fresh()->name);
    }

    public function test_a_non_member_cannot_update_an_artist(): void
    {
        $artist = Artist::factory()->create(['name' => 'Untouched']);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->patch(route('artists.update', $artist), [
                'name' => 'Hijacked',
                'genre' => $artist->genre,
                'hometown' => $artist->hometown,
                'stateOrProvince' => $artist->stateOrProvince,
                'country' => $artist->country,
            ])
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
            ->patch(route('artists.update', $artist), [
                'name' => $artist->name,
                'genre' => $artist->genre,
                'hometown' => $artist->hometown,
                'stateOrProvince' => $artist->stateOrProvince,
                'country' => $artist->country,
                'website' => 'not-a-url',
                'email' => 'not-an-email',
            ])
            ->assertSessionHasErrors(['website', 'email']);
    }
}
