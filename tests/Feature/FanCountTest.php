<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\User;
use App\Services\FanCounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Fan counts are counts (ROADMAP principle 5): nothing here may become a
 * way to find out who, or where, a listener is.
 */
class FanCountTest extends TestCase
{
    use RefreshDatabase;

    /** Hartford, the pilot scene's centre. */
    private const LAT = 41.7637;

    private const LNG = -72.6851;

    private function fanAt(Artist $artist, ?float $lat, ?float $lng): User
    {
        $user = User::factory()->create(['lat' => $lat, 'lng' => $lng]);
        $artist->followers()->attach($user);

        return $user;
    }

    private function hartfordArtist(): Artist
    {
        return Artist::factory()->create(['lat' => self::LAT, 'lng' => self::LNG]);
    }

    private function counts(): FanCounts
    {
        return app(FanCounts::class);
    }

    #[Test]
    public function it_counts_only_fans_inside_the_local_radius(): void
    {
        $artist = $this->hartfordArtist();

        // In town.
        $this->fanAt($artist, self::LAT, self::LNG);
        // ~20 km north: inside the 40 km radius.
        $this->fanAt($artist, self::LAT + 0.18, self::LNG);
        // Los Angeles: a fan, but not a local one.
        $this->fanAt($artist, 34.0522, -118.2437);
        // Never geocoded: cannot be counted as local either way.
        $this->fanAt($artist, null, null);

        $this->assertSame(2, $this->counts()->local($artist));
        $this->assertSame(4, $artist->followers()->count());
    }

    #[Test]
    public function the_radius_is_a_circle_not_its_bounding_box(): void
    {
        // The regression this guards: PDO binds PHP floats as strings, and
        // SQLite will not convert a string compared against an expression,
        // so the exact circle silently passed everything the bounding box
        // let through. A fan in the box's corner is ~1.41x the radius away
        // and must not count. See CLAUDE.md.
        $artist = $this->hartfordArtist();

        $radiusKm = (float) config('riff.fans.local_radius_km');
        $degrees = $radiusKm / 111.32;

        // The north-east corner of the bounding box: inside the box,
        // outside the circle.
        $this->fanAt(
            $artist,
            self::LAT + $degrees * 0.95,
            self::LNG + ($degrees * 0.95) / cos(deg2rad(self::LAT)),
        );

        $this->assertSame(0, $this->counts()->local($artist));
    }

    #[Test]
    public function an_artist_with_no_coordinates_has_no_local_count(): void
    {
        // Null, not zero: with nothing to measure from, "0 nearby" would
        // be a claim the data cannot support.
        $artist = Artist::factory()->create(['lat' => null, 'lng' => null]);
        $this->fanAt($artist, self::LAT, self::LNG);

        $this->assertNull($this->counts()->local($artist));
        $this->assertNull($this->counts()->displayLocal($artist));
    }

    #[Test]
    public function a_small_local_count_is_shown_as_a_threshold(): void
    {
        $artist = $this->hartfordArtist();
        $floor = (int) config('riff.fans.local_floor');

        $this->fanAt($artist, self::LAT, self::LNG);

        // An exact "1" beside a small town narrows a listener down too far.
        $this->assertSame('< '.$floor, $this->counts()->displayLocal($artist));
    }

    #[Test]
    public function a_local_count_at_the_floor_is_exact(): void
    {
        $artist = $this->hartfordArtist();
        $floor = (int) config('riff.fans.local_floor');

        for ($i = 0; $i < $floor; $i++) {
            $this->fanAt($artist, self::LAT, self::LNG);
        }

        $this->assertSame((string) $floor, $this->counts()->displayLocal($artist));
    }

    #[Test]
    public function no_local_fans_shows_nothing_at_all(): void
    {
        $artist = $this->hartfordArtist();
        $this->fanAt($artist, 34.0522, -118.2437);

        $this->assertSame(0, $this->counts()->local($artist));
        $this->assertNull($this->counts()->displayLocal($artist));
    }

    #[Test]
    public function following_refreshes_the_cached_count(): void
    {
        $artist = $this->hartfordArtist();

        // Prime the cache at zero.
        $this->assertSame(0, $this->counts()->local($artist));

        $fan = User::factory()->create(['lat' => self::LAT, 'lng' => self::LNG]);

        $this->actingAs($fan)->post(route('artists.follow', $artist));

        $this->assertSame(1, $this->counts()->local($artist));

        $this->actingAs($fan)->delete(route('artists.unfollow', $artist));

        $this->assertSame(0, $this->counts()->local($artist));
    }

    #[Test]
    public function moving_the_artist_recomputes_who_is_local(): void
    {
        // The geocoder writes coordinates with saveQuietly(), so no model
        // event would invalidate a cache keyed on the id alone.
        $artist = $this->hartfordArtist();
        $this->fanAt($artist, self::LAT, self::LNG);

        $this->assertSame(1, $this->counts()->local($artist));

        $artist->forceFill(['lat' => 34.0522, 'lng' => -118.2437])->saveQuietly();

        $this->assertSame(0, $this->counts()->local($artist->fresh()));
    }

    #[Test]
    public function the_public_page_exposes_a_count_and_never_a_fan(): void
    {
        $artist = $this->hartfordArtist();
        $floor = (int) config('riff.fans.local_floor');

        for ($i = 0; $i < $floor; $i++) {
            $this->fanAt($artist, self::LAT, self::LNG);
        }

        // A distinct point, a few km from the artist's own: the artist's
        // coordinates are public by design (an approximate, chosen town),
        // so asserting on a shared number would prove nothing.
        $fanLat = self::LAT + 0.0531;
        $named = User::factory()->create([
            'name' => 'Verya Unmistakable Fanname',
            'lat' => $fanLat,
            'lng' => self::LNG,
        ]);
        $artist->followers()->attach($named);

        $response = $this->get(route('artists.show', $artist));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('localFans', (string) ($floor + 1)));
        // No fan is ever named or placed on a public page.
        $response->assertDontSee('Verya Unmistakable Fanname');
        $response->assertDontSee((string) $fanLat);
    }
}
