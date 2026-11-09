<?php

namespace App\Services;

use App\DTO\MusicEmbed;

/**
 * Turns an artist-supplied music link into a provider, a canonical URL, and
 * where possible an embed URL.
 *
 * The parse is deliberately strict: an unrecognised host, an unexpected path
 * shape, or an identifier with unexpected characters all return null rather
 * than a best guess. Everything here feeds an iframe eventually, so the only
 * values that survive are ones matched against an explicit pattern.
 */
class MusicLink
{
    /**
     * Providers Riff can embed or link, in the order they are tried.
     *
     * @var array<string, string>
     */
    public const PROVIDERS = [
        'spotify' => 'Spotify',
        'youtube' => 'YouTube',
        'soundcloud' => 'SoundCloud',
        'apple_music' => 'Apple Music',
        'bandcamp' => 'Bandcamp',
    ];

    public static function parse(?string $url): ?MusicEmbed
    {
        $url = trim((string) $url);

        if ($url === '' || mb_strlen($url) > 2048) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        $host = preg_replace('/^www\./', '', strtolower($parts['host']));
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);

        return match (true) {
            $host === 'open.spotify.com' => self::spotify($path),
            in_array($host, ['youtube.com', 'm.youtube.com', 'music.youtube.com'], true) => self::youtube($path, $query),
            $host === 'youtu.be' => self::youtube('/embed'.$path, []),
            $host === 'soundcloud.com' => self::soundcloud($path),
            $host === 'music.apple.com' => self::appleMusic($path, $query),
            $host === 'bandcamp.com' || str_ends_with($host, '.bandcamp.com') => self::bandcamp($host, $path),
            default => null,
        };
    }

    /**
     * Human label for a stored provider key.
     */
    public static function label(?string $provider): ?string
    {
        return self::PROVIDERS[$provider] ?? null;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        return array_values(array_filter(explode('/', $path), fn ($segment) => $segment !== ''));
    }

    private static function spotify(string $path): ?MusicEmbed
    {
        $segments = self::segments($path);

        // Localised links carry an /intl-xx prefix that the embed drops.
        if (isset($segments[0]) && preg_match('/^intl-[a-z]{2}$/', $segments[0])) {
            array_shift($segments);
        }

        $type = $segments[0] ?? '';
        $id = $segments[1] ?? '';

        if (! in_array($type, ['track', 'album', 'artist', 'playlist'], true)) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9]{16,40}$/', $id)) {
            return null;
        }

        return new MusicEmbed(
            provider: 'spotify',
            label: self::PROVIDERS['spotify'],
            url: "https://open.spotify.com/{$type}/{$id}",
            embedUrl: "https://open.spotify.com/embed/{$type}/{$id}",
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function youtube(string $path, array $query): ?MusicEmbed
    {
        $id = null;
        $list = null;

        if ($path === '/watch' && is_string($query['v'] ?? null)) {
            $id = $query['v'];
        } elseif (preg_match('#^/(?:shorts|embed|live|v)/([^/?]+)#', $path, $matches)) {
            $id = $matches[1];
        } elseif ($path === '/playlist' && is_string($query['list'] ?? null)) {
            $list = $query['list'];
        }

        if ($id !== null && preg_match('/^[A-Za-z0-9_-]{11}$/', $id)) {
            return new MusicEmbed(
                provider: 'youtube',
                label: self::PROVIDERS['youtube'],
                url: "https://www.youtube.com/watch?v={$id}",
                // youtube-nocookie keeps a profile visit from seeding ad
                // cookies for a viewer who never pressed play.
                embedUrl: "https://www.youtube-nocookie.com/embed/{$id}",
            );
        }

        if ($list !== null && preg_match('/^[A-Za-z0-9_-]{12,64}$/', $list)) {
            return new MusicEmbed(
                provider: 'youtube',
                label: self::PROVIDERS['youtube'],
                url: "https://www.youtube.com/playlist?list={$list}",
                embedUrl: "https://www.youtube-nocookie.com/embed/videoseries?list={$list}",
            );
        }

        return null;
    }

    private static function soundcloud(string $path): ?MusicEmbed
    {
        $segments = self::segments($path);

        // user/track, or user/sets/playlist.
        $valid = match (count($segments)) {
            2 => true,
            3 => $segments[1] === 'sets',
            default => false,
        };

        if (! $valid) {
            return null;
        }

        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9_-]+$/', $segment)) {
                return null;
            }
        }

        $canonical = 'https://soundcloud.com/'.implode('/', $segments);

        return new MusicEmbed(
            provider: 'soundcloud',
            label: self::PROVIDERS['soundcloud'],
            url: $canonical,
            embedUrl: 'https://w.soundcloud.com/player/?url='.rawurlencode($canonical).'&visual=false&hide_related=true',
        );
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private static function appleMusic(string $path, array $query): ?MusicEmbed
    {
        $segments = self::segments($path);

        if (count($segments) !== 4) {
            return null;
        }

        [$storefront, $kind, $slug, $id] = $segments;

        if (! preg_match('/^[a-z]{2}$/', $storefront)
            || ! in_array($kind, ['album', 'song', 'playlist'], true)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', $slug)
            || ! preg_match('/^[A-Za-z0-9.-]+$/', $id)) {
            return null;
        }

        $suffix = '';

        // ?i=<track id> selects one track inside an album link.
        if (is_string($query['i'] ?? null) && preg_match('/^\d+$/', $query['i'])) {
            $suffix = '?i='.$query['i'];
        }

        $tail = "{$storefront}/{$kind}/{$slug}/{$id}{$suffix}";

        return new MusicEmbed(
            provider: 'apple_music',
            label: self::PROVIDERS['apple_music'],
            url: "https://music.apple.com/{$tail}",
            embedUrl: "https://embed.music.apple.com/{$tail}",
        );
    }

    /**
     * Bandcamp is link-only: its player needs a numeric item id that only
     * appears in the page HTML, not in the public URL.
     */
    private static function bandcamp(string $host, string $path): ?MusicEmbed
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.bandcamp\.com$/', $host)) {
            return null;
        }

        $segments = self::segments($path);

        if ($segments === []) {
            return new MusicEmbed('bandcamp', self::PROVIDERS['bandcamp'], "https://{$host}");
        }

        if (count($segments) !== 2
            || ! in_array($segments[0], ['track', 'album'], true)
            || ! preg_match('/^[A-Za-z0-9._-]+$/', $segments[1])) {
            return null;
        }

        return new MusicEmbed('bandcamp', self::PROVIDERS['bandcamp'], "https://{$host}/{$segments[0]}/{$segments[1]}");
    }
}
