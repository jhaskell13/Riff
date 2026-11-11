<?php

namespace Tests\Unit\Services;

use App\Services\MusicLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MusicLink output reaches an iframe src, so the parse must accept only
 * shapes it fully recognises and compose embed URLs from its own templates.
 */
class MusicLinkTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public static function recognisedLinks(): array
    {
        return [
            'spotify track' => [
                'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
                'spotify',
                'https://open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT',
                'https://open.spotify.com/embed/track/4cOdK2wGLETKBW3PvgPWqT',
            ],
            'spotify album with locale and tracking query' => [
                'https://open.spotify.com/intl-de/album/1ATL5GLyefJaxhQzSPVrLX?si=9f',
                'spotify',
                'https://open.spotify.com/album/1ATL5GLyefJaxhQzSPVrLX',
                'https://open.spotify.com/embed/album/1ATL5GLyefJaxhQzSPVrLX',
            ],
            'youtube watch' => [
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s',
                'youtube',
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            ],
            'youtube short link' => [
                'https://youtu.be/dQw4w9WgXcQ',
                'youtube',
                'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
            ],
            'youtube playlist' => [
                'https://www.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI',
                'youtube',
                'https://www.youtube.com/playlist?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI',
                'https://www.youtube-nocookie.com/embed/videoseries?list=PLFgquLnL59alCl_2TQvOiD5Vgm1hCaGSI',
            ],
            'soundcloud track' => [
                'https://soundcloud.com/forss/flickermood?in=someone/sets/x',
                'soundcloud',
                'https://soundcloud.com/forss/flickermood',
                'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fforss%2Fflickermood&visual=false&hide_related=true',
            ],
            'apple music album track' => [
                'https://music.apple.com/us/album/the-song/1440857781?i=1440857793',
                'apple_music',
                'https://music.apple.com/us/album/the-song/1440857781?i=1440857793',
                'https://embed.music.apple.com/us/album/the-song/1440857781?i=1440857793',
            ],
        ];
    }

    #[DataProvider('recognisedLinks')]
    public function test_it_canonicalises_and_builds_an_embed(string $input, string $provider, string $url, ?string $embedUrl): void
    {
        $embed = MusicLink::parse($input);

        $this->assertNotNull($embed, "Expected {$input} to be recognised.");
        $this->assertSame($provider, $embed->provider);
        $this->assertSame($url, $embed->url);
        $this->assertSame($embedUrl, $embed->embedUrl);
        $this->assertTrue($embed->isPlayable());
    }

    public function test_bandcamp_is_recognised_but_not_playable(): void
    {
        $embed = MusicLink::parse('https://sometown.bandcamp.com/album/first-ep');

        $this->assertNotNull($embed);
        $this->assertSame('bandcamp', $embed->provider);
        $this->assertSame('https://sometown.bandcamp.com/album/first-ep', $embed->url);
        $this->assertNull($embed->embedUrl);
        $this->assertFalse($embed->isPlayable());
    }

    /**
     * @return array<string, array{0: ?string}>
     */
    public static function rejectedLinks(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'not a url' => ['open.spotify.com/track/4cOdK2wGLETKBW3PvgPWqT'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html;base64,PHNjcmlwdD4='],
            'unknown host' => ['https://evil.example/track/123'],
            'lookalike host' => ['https://open.spotify.com.evil.example/track/4cOdK2wGLETKBW3PvgPWqT'],
            'spotify without an id' => ['https://open.spotify.com/track'],
            'spotify unknown resource' => ['https://open.spotify.com/user/4cOdK2wGLETKBW3PvgPWqT'],
            'youtube id with injection characters' => ['https://www.youtube.com/watch?v=abc"><script'],
            'youtube id of the wrong length' => ['https://www.youtube.com/watch?v=tooshort'],
            'soundcloud profile only' => ['https://soundcloud.com/forss'],
            'apple music truncated path' => ['https://music.apple.com/us/album/the-song'],
            'bandcamp unexpected path' => ['https://sometown.bandcamp.com/merch/t-shirt/extra'],
        ];
    }

    #[DataProvider('rejectedLinks')]
    public function test_it_rejects_anything_it_cannot_fully_recognise(?string $input): void
    {
        $this->assertNull(MusicLink::parse($input));
    }

    public function test_an_overlong_url_is_rejected_before_parsing(): void
    {
        $this->assertNull(MusicLink::parse('https://open.spotify.com/track/'.str_repeat('a', 4000)));
    }
}
