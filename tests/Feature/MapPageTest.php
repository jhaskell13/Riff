<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The map shell: who can open it, what the UI needs to render, and the
 * fact that it is now where signing in lands you.
 */
class MapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_sees_the_map_with_the_default_centre(): void
    {
        $this->get(route('map.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Map/Index')
                ->where('canFollow', false)
                ->where('hasOwnLocation', false)
                ->where('center.lat', config('riff.map.default_center.lat'))
                ->has('filterOptions.genres')
                ->has('filterOptions.types')
                ->has('filterOptions.sorts')
                ->where('maxMarkers', config('riff.map.max_markers'))
            );
    }

    public function test_a_signed_in_listener_can_follow_and_is_centred_on_their_area(): void
    {
        $user = User::factory()->create(['lat' => 41.5, 'lng' => -72.5, 'distance_unit' => 'km']);

        $this->actingAs($user)
            ->get(route('map.index'))
            ->assertInertia(fn ($page) => $page
                ->where('canFollow', true)
                ->where('hasOwnLocation', true)
                ->where('center.lat', 41.5)
                ->where('filterOptions.unit', 'km')
            );
    }

    public function test_an_unverified_user_can_still_browse_the_map(): void
    {
        // Discovery is the reason to verify in the first place; blocking it
        // behind verification would hide the product from a new account.
        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('map.index'))
            ->assertOk();
    }

    public function test_signing_in_lands_on_the_map(): void
    {
        $user = User::factory()->create(['password' => Hash::make('password-123')]);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'password-123'])
            ->assertRedirect(route('map.index', absolute: false));
    }

    public function test_registering_lands_on_the_map(): void
    {
        $this->post(route('register'), [
            'name' => 'New Listener',
            'email' => 'listener@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'city' => 'Hartford',
            'state' => 'CT',
            'country' => 'USA',
        ])->assertRedirect(route('map.index', absolute: false));
    }

    public function test_the_map_page_is_rate_limited_like_its_data(): void
    {
        config(['riff.map.rate_limit_per_minute' => 2]);

        $this->get(route('map.index'))->assertOk();
        $this->get(route('map.index'))->assertOk();
        $this->get(route('map.index'))->assertTooManyRequests();
    }
}
