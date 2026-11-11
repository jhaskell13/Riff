<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapTest extends TestCase
{
    use RefreshDatabase;

    private const BOUNDS = [
        'south' => 41.0,
        'north' => 42.0,
        'west' => -73.0,
        'east' => -72.0,
    ];

    public function test_guests_can_open_the_map(): void
    {
        $this->get(route('map.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Map/Index')
                ->where('canFollow', false)
                ->where('hasOwnLocation', false)
                ->where('center.lat', config('riff.map.default_center.lat'))
            );
    }

    public function test_the_map_centers_on_the_users_coordinates(): void
    {
        $user = User::factory()->create(['lat' => 41.5, 'lng' => -72.5]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertInertia(fn ($page) => $page
                ->component('Map/Index')
                ->where('center.lat', 41.5)
                ->where('center.lng', -72.5)
                ->where('hasOwnLocation', true)
            );
    }

    public function test_the_map_falls_back_to_the_default_center_without_coordinates(): void
    {
        $user = User::factory()->create(['lat' => null, 'lng' => null]);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertInertia(fn ($page) => $page
                ->where('center.lat', config('riff.map.default_center.lat'))
                ->where('hasOwnLocation', false)
            );
    }

    public function test_only_artists_inside_the_bounds_are_returned(): void
    {
        $user = User::factory()->create();
        $inside = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);
        Artist::factory()->create(['lat' => 45.0, 'lng' => -72.5]); // north of bounds
        Artist::factory()->create(['lat' => 41.5, 'lng' => -70.0]); // east of bounds

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inside->id)
            ->assertJsonPath('data.0.name', $inside->name)
            ->assertJsonPath('data.0.url', route('artists.show', $inside));
    }

    public function test_artists_without_coordinates_are_never_plotted(): void
    {
        $user = User::factory()->create();
        Artist::factory()->create(['lat' => null, 'lng' => null]);

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_archived_artists_are_not_plotted(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()->create(['lat' => 41.5, 'lng' => -72.5]);
        $artist->delete();

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertJsonCount(0, 'data');
    }

    public function test_artists_who_opted_off_the_map_are_not_plotted(): void
    {
        $user = User::factory()->create();
        Artist::factory()->hiddenFromMap()->create(['lat' => 41.5, 'lng' => -72.5]);

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertJsonCount(0, 'data');
    }

    public function test_a_marker_carries_what_a_card_needs(): void
    {
        $user = User::factory()->create();
        $artist = Artist::factory()
            ->withGenres(['Punk', 'Folk'])
            ->create([
                'lat' => 41.5,
                'lng' => -72.5,
                'artist_type' => 'solo',
                'music_url' => 'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
            ]);

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertJsonPath('data.0.type', 'solo')
            ->assertJsonPath('data.0.typeLabel', 'Solo artist')
            ->assertJsonPath('data.0.hasMusic', true)
            ->assertJsonPath('data.0.location', $artist->hometown.', '.$artist->stateOrProvince)
            ->assertJsonCount(2, 'data.0.genres');
    }

    public function test_bounds_are_required_and_validated(): void
    {
        $user = User::factory()->create();

        // No area, radius, or search term: nothing says where to look.
        $this->actingAs($user)
            ->getJson(route('map.artists'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['south']);

        // A partial viewport is still rejected.
        $this->actingAs($user)
            ->getJson(route('map.artists', ['south' => 41, 'north' => 42]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['west', 'east']);

        $this->actingAs($user)
            ->getJson(route('map.artists', ['south' => 50, 'north' => 40, 'west' => -73, 'east' => -72]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['north']);
    }

    public function test_the_marker_count_is_capped(): void
    {
        config(['riff.map.max_markers' => 5]);
        $user = User::factory()->create();
        Artist::factory()->count(8)->create(['lat' => 41.5, 'lng' => -72.5]);

        $this->actingAs($user)
            ->getJson(route('map.artists', self::BOUNDS))
            ->assertJsonCount(5, 'data');
    }
}
