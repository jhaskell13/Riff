<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Map distances are shown in the listener's own unit, guessed once at
 * registration and changeable afterwards.
 */
class DistanceUnitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function countries(): array
    {
        return [
            'united states' => ['USA', 'mi'],
            'lowercase united states' => ['united states', 'mi'],
            'united kingdom' => ['United Kingdom', 'mi'],
            'padded liberia' => ['  Liberia ', 'mi'],
            'canada' => ['Canada', 'km'],
            'germany' => ['Germany', 'km'],
            'unknown' => ['Atlantis', 'km'],
        ];
    }

    #[DataProvider('countries')]
    public function test_registration_guesses_the_unit_from_the_country(string $country, string $expected): void
    {
        $this->post(route('register'), [
            'name' => 'New Listener',
            'email' => 'listener@example.com',
            'password' => 'password-123',
            'password_confirmation' => 'password-123',
            'city' => 'Somewhere',
            'state' => 'Someplace',
            'country' => $country,
        ]);

        $this->assertSame($expected, User::where('email', 'listener@example.com')->firstOrFail()->distance_unit);
    }

    public function test_a_listener_can_change_their_unit(): void
    {
        $user = User::factory()->create(['distance_unit' => 'mi']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'city' => $user->city,
            'state' => $user->state,
            'country' => $user->country,
            'distance_unit' => 'km',
        ])->assertSessionHasNoErrors();

        $this->assertSame('km', $user->fresh()->distance_unit);
    }

    public function test_an_unknown_unit_is_rejected(): void
    {
        $user = User::factory()->create(['distance_unit' => 'mi']);

        $this->actingAs($user)->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'city' => $user->city,
            'state' => $user->state,
            'country' => $user->country,
            'distance_unit' => 'furlongs',
        ])->assertSessionHasErrors('distance_unit');

        $this->assertSame('mi', $user->fresh()->distance_unit);
    }
}
