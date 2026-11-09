<?php

namespace Tests\Feature;

use App\Models\Artist;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArtistPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const GPS_CANARY = 'GPSLatitude=41.7658,GPSLongitude=-72.6734';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('riff.media.disk'));
    }

    private function disk()
    {
        return Storage::disk(config('riff.media.disk'));
    }

    private function memberOf(Artist $artist): User
    {
        $user = User::factory()->create();
        $artist->members()->attach($user);

        return $user;
    }

    /**
     * A real JPEG with an EXIF (APP1) segment spliced in after the SOI
     * marker, carrying a GPS-looking canary string -- the shape of a photo
     * straight off a phone.
     */
    private function photoWithLocationMetadata(): UploadedFile
    {
        $canvas = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($canvas);
        $jpeg = ob_get_clean();

        // Exif header + big-endian TIFF header + an empty IFD, then the canary.
        $payload = "Exif\0\0MM\0*\0\0\0\x08\0\0\0\0\0\0".self::GPS_CANARY;
        $app1 = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        $path = tempnam(sys_get_temp_dir(), 'riff').'.jpg';
        file_put_contents($path, substr($jpeg, 0, 2).$app1.substr($jpeg, 2));

        return new UploadedFile($path, 'IMG_0001.jpg', 'image/jpeg', null, true);
    }

    public function test_a_member_can_upload_a_photo(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image('band.png', 400, 300),
            ])
            ->assertSessionHasNoErrors();

        $artist->refresh();

        $this->assertNotNull($artist->image_path);
        $this->assertStringStartsWith("artists/{$artist->id}/", $artist->image_path);
        $this->assertStringEndsWith('.jpg', $artist->image_path);
        $this->disk()->assertExists($artist->image_path);
        $this->assertSame($this->disk()->url($artist->image_path), $artist->image_url);
    }

    public function test_location_metadata_is_stripped_from_uploads(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);
        $upload = $this->photoWithLocationMetadata();

        // Sanity: the fixture really does carry the metadata.
        $this->assertStringContainsString(self::GPS_CANARY, file_get_contents($upload->getRealPath()));

        $this->actingAs($member)->post(route('artists.photo.store', $artist), ['photo' => $upload]);

        $stored = $this->disk()->get($artist->fresh()->image_path);

        $this->assertStringNotContainsString(self::GPS_CANARY, $stored);
        $this->assertStringNotContainsString('Exif', $stored);
    }

    public function test_large_photos_are_scaled_down(): void
    {
        config(['riff.media.artist_photo.max_edge_pixels' => 500]);
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)->post(route('artists.photo.store', $artist), [
            'photo' => UploadedFile::fake()->image('wide.jpg', 2000, 1000),
        ]);

        [$width, $height] = getimagesizefromstring($this->disk()->get($artist->fresh()->image_path));

        $this->assertSame(500, $width);
        $this->assertSame(250, $height);
    }

    public function test_small_photos_are_not_upscaled(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)->post(route('artists.photo.store', $artist), [
            'photo' => UploadedFile::fake()->image('small.jpg', 300, 200),
        ]);

        [$width] = getimagesizefromstring($this->disk()->get($artist->fresh()->image_path));

        $this->assertSame(300, $width);
    }

    public function test_replacing_a_photo_deletes_the_old_file(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)->post(route('artists.photo.store', $artist), [
            'photo' => UploadedFile::fake()->image('first.jpg', 300, 300),
        ]);
        $first = $artist->fresh()->image_path;

        $this->actingAs($member)->post(route('artists.photo.store', $artist), [
            'photo' => UploadedFile::fake()->image('second.jpg', 300, 300),
        ]);
        $second = $artist->fresh()->image_path;

        $this->assertNotSame($first, $second);
        $this->disk()->assertMissing($first);
        $this->disk()->assertExists($second);
    }

    public function test_a_member_can_remove_the_photo(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)->post(route('artists.photo.store', $artist), [
            'photo' => UploadedFile::fake()->image('band.jpg', 300, 300),
        ]);
        $path = $artist->fresh()->image_path;

        $this->actingAs($member)->delete(route('artists.photo.destroy', $artist));

        $this->assertNull($artist->fresh()->image_path);
        $this->assertNull($artist->fresh()->image_url);
        $this->disk()->assertMissing($path);
    }

    public function test_a_non_member_cannot_change_the_photo(): void
    {
        $artist = Artist::factory()->create();
        $outsider = User::factory()->create();

        $this->actingAs($outsider)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image('hijack.jpg', 300, 300),
            ])
            ->assertForbidden();

        $this->actingAs($outsider)
            ->delete(route('artists.photo.destroy', $artist))
            ->assertForbidden();

        $this->assertNull($artist->fresh()->image_path);
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_non_images_are_rejected(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
            ])
            ->assertSessionHasErrors('photo');

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->create('vector.svg', 10, 'image/svg+xml'),
            ])
            ->assertSessionHasErrors('photo');

        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_oversized_source_images_are_rejected_before_decoding(): void
    {
        config(['riff.media.artist_photo.max_source_edge_pixels' => 1000]);
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image('huge.jpg', 1500, 800),
            ])
            ->assertSessionHasErrors('photo');
    }

    public function test_files_over_the_size_limit_are_rejected(): void
    {
        config(['riff.media.artist_photo.max_kilobytes' => 100]);
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image('heavy.jpg', 300, 300)->size(500),
            ])
            ->assertSessionHasErrors('photo');
    }

    public function test_a_photo_can_be_attached_at_creation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('artists.store'), [
            'name' => 'Pictured Band',
            'artist_type' => 'band',
            'genres' => [Genre::firstOrFail()->id],
            'hometown' => 'Hartford',
            'stateOrProvince' => 'CT',
            'country' => 'USA',
            'show_on_map' => true,
            'photo' => UploadedFile::fake()->image('band.jpg', 300, 300),
        ])->assertSessionHasNoErrors();

        $artist = Artist::where('name', 'Pictured Band')->firstOrFail();

        $this->disk()->assertExists($artist->image_path);
    }

    public function test_photo_uploads_are_rate_limited(): void
    {
        $artist = Artist::factory()->create();
        $member = $this->memberOf($artist);

        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($member)->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image("p{$i}.jpg", 50, 50),
            ]);
        }

        $this->actingAs($member)
            ->post(route('artists.photo.store', $artist), [
                'photo' => UploadedFile::fake()->image('one-too-many.jpg', 50, 50),
            ])
            ->assertTooManyRequests();
    }
}
