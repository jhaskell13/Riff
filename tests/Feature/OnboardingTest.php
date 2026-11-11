<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use App\Support\OnboardingIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The two ways into Riff: someone looking for local music, and someone who
 * makes it. The map is public, so the choice is made there and has to carry
 * through registration and the email verification that follows it.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'city' => 'Hartford',
            'state' => 'CT',
            'country' => 'USA',
        ], $overrides);
    }

    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );
    }

    public function test_register_page_carries_the_chosen_path(): void
    {
        $this->get('/register?intent=artist')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('intent', 'artist'));
    }

    public function test_register_page_ignores_an_unknown_path(): void
    {
        $this->get('/register?intent=wandering-minstrel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('intent', null));
    }

    public function test_the_artist_path_is_remembered_and_continues_to_artist_creation(): void
    {
        $response = $this->post('/register', $this->registration(['intent' => 'artist']));

        $response->assertRedirect(route('artists.create', absolute: false));
        $this->assertSame(OnboardingIntent::Artist, User::firstWhere('email', 'test@example.com')->onboarding_intent);
    }

    public function test_the_artist_path_meets_verification_before_artist_creation(): void
    {
        $this->post('/register', $this->registration(['intent' => 'artist']));

        // Artist creation is behind the verification check, so the form is
        // not reachable yet; the notice says what is waiting on the far side.
        $this->get(route('artists.create'))
            ->assertRedirect(route('verification.notice'));

        $this->get(route('verification.notice'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/VerifyEmail')
                ->where('intent', 'artist'));
    }

    public function test_the_listener_path_lands_on_the_map(): void
    {
        $response = $this->post('/register', $this->registration(['intent' => 'fan']));

        $response->assertRedirect(route('map.index', absolute: false));
        $this->assertSame(OnboardingIntent::Fan, User::firstWhere('email', 'test@example.com')->onboarding_intent);
    }

    public function test_registering_without_a_path_records_none(): void
    {
        $response = $this->post('/register', $this->registration());

        $response->assertRedirect(route('map.index', absolute: false));
        $this->assertNull(User::firstWhere('email', 'test@example.com')->onboarding_intent);
    }

    public function test_an_unknown_path_is_rejected_rather_than_stored(): void
    {
        $this->post('/register', $this->registration(['intent' => 'promoter']))
            ->assertSessionHasErrors('intent');

        $this->assertDatabaseMissing('users', ['email' => 'test@example.com']);
    }

    public function test_verifying_email_resumes_the_artist_path(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_intent' => OnboardingIntent::Artist,
        ]);

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect(route('artists.create', absolute: false));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_verifying_email_returns_an_artist_who_already_has_an_identity_to_the_map(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_intent' => OnboardingIntent::Artist,
        ]);
        $user->artists()->attach(Artist::factory()->create());

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect(route('map.index', absolute: false).'?verified=1');
    }

    public function test_verifying_email_returns_a_listener_to_the_map(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_intent' => OnboardingIntent::Fan,
        ]);

        $this->actingAs($user)
            ->get($this->verificationUrl($user))
            ->assertRedirect(route('map.index', absolute: false).'?verified=1');
    }

    public function test_the_verification_notice_tells_an_artist_what_comes_next(): void
    {
        $user = User::factory()->unverified()->create([
            'onboarding_intent' => OnboardingIntent::Artist,
        ]);

        $this->actingAs($user)
            ->get('/verify-email')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/VerifyEmail')
                ->where('intent', 'artist'));
    }
}
