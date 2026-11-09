<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\LiveStream;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every authenticated GET route in the app.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function protectedGetRoutes(): array
    {
        return [
            'feed' => ['feed.index', []],
            'map' => ['map.index', []],
            'map artists' => ['map.artists', ['south' => 41, 'north' => 42, 'west' => -73, 'east' => -72]],
            'artist index' => ['artists.index', []],
            'artist create' => ['artists.create', []],
            'artist show' => ['artists.show', ['artist' => 1]],
            'artist edit' => ['artists.edit', ['artist' => 1]],
            'streams' => ['streams', []],
            'stream create' => ['stream.create', []],
            'stream show' => ['stream.show', ['stream' => 1]],
            'stream manage' => ['stream.edit', ['stream' => 1]],
            'profile' => ['profile.edit', []],
        ];
    }

    #[DataProvider('protectedGetRoutes')]
    public function test_guests_are_redirected_to_login(string $routeName, array $params): void
    {
        $this->get(route($routeName, $params))->assertRedirect(route('login'));
    }

    /**
     * Routes behind the verified middleware (profile is plain auth,
     * matching Breeze defaults).
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function verifiedOnlyRoutes(): array
    {
        return collect(self::protectedGetRoutes())
            ->reject(fn ($case) => $case[0] === 'profile.edit')
            ->all();
    }

    #[DataProvider('verifiedOnlyRoutes')]
    public function test_unverified_users_are_sent_to_the_verification_notice(string $routeName, array $params): void
    {
        // Real records so route model binding resolves before middleware is the question.
        Artist::factory()->create();
        LiveStream::factory()->create();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->get(route($routeName, $params))
            ->assertRedirect(route('verification.notice'));
    }

    public function test_unverified_users_cannot_perform_writes(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->post(route('feed.store'), ['content' => 'blocked'])
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('posts', 0);

        $this->actingAs($user)
            ->post(route('artists.store'), [
                'name' => 'Blocked Band',
                'artist_type' => 'band',
                'genres' => [1],
                'hometown' => 'Hartford',
                'stateOrProvince' => 'CT',
                'country' => 'USA',
                'show_on_map' => true,
            ])
            ->assertRedirect(route('verification.notice'));

        $this->assertDatabaseCount('artists', 0);
    }

    public function test_the_health_endpoint_is_public(): void
    {
        $this->get('/health')->assertOk();
    }
}
