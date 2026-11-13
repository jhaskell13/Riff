<?php

namespace App\Services;

use App\DTO\SupportTarget;

/**
 * Turns an artist-supplied "where to support us" link into a type, a
 * canonical URL, and the host to show beside it.
 *
 * The parse is strict in the same spirit as MusicLink, but for a different
 * reason. MusicLink is strict because its output reaches an iframe src; a
 * support link never does. This is strict because a support section is a
 * place people are asked to spend money, which makes it the most valuable
 * thing on the page to hijack. So:
 *
 *  - only http(s), so no javascript:, data:, or mailto: slips into an href;
 *  - no credentials in the URL (https://bandcamp.com@evil.example reads as
 *    Bandcamp to a human and resolves to evil.example to a browser);
 *  - a real, dotted, non-IP host, so a profile can't point into a private
 *    network or at something that only resolves on the viewer's machine;
 *  - for the types where going elsewhere would be a scam rather than a
 *    typo -- Bandcamp, tip jars -- the host must be on that type's
 *    allowlist from config('riff.support.types').
 *
 * Whatever survives is still a third-party link: the renderer pairs it with
 * rel="noopener noreferrer nofollow" and shows the host.
 */
class SupportLink
{
    /** Longest URL accepted, matching the column and MusicLink. */
    private const MAX_LENGTH = 2048;

    /**
     * Parse a link for a declared type. Returns null when the type is
     * unknown or the link is one Riff will not publish.
     */
    public static function parse(?string $url, ?string $type): ?SupportTarget
    {
        $config = self::typeConfig($type);

        if ($config === null) {
            return null;
        }

        $canonical = self::canonicalise($url);

        if ($canonical === null) {
            return null;
        }

        [$normalised, $host] = $canonical;

        /** @var list<string> $hosts */
        $hosts = $config['hosts'] ?? [];

        if ($hosts !== [] && ! self::hostMatches($host, $hosts)) {
            return null;
        }

        return new SupportTarget(
            type: (string) $type,
            label: (string) ($config['label'] ?? $type),
            url: $normalised,
            host: $host,
        );
    }

    /**
     * The type a link looks like, for prefilling the form. Only the
     * allowlisted types can be recognised from a host alone -- a store or
     * merch page lives on the band's own domain and is indistinguishable
     * from anything else.
     */
    public static function detect(?string $url): ?string
    {
        $canonical = self::canonicalise($url);

        if ($canonical === null) {
            return null;
        }

        [, $host] = $canonical;

        foreach ((array) config('riff.support.types') as $type => $config) {
            /** @var list<string> $hosts */
            $hosts = $config['hosts'] ?? [];

            if ($hosts !== [] && self::hostMatches($host, $hosts)) {
                return (string) $type;
            }
        }

        return null;
    }

    /** Human label for a stored type key. */
    public static function label(?string $type): ?string
    {
        $config = self::typeConfig($type);

        return $config === null ? null : (string) ($config['label'] ?? $type);
    }

    /** Material icon for a stored type key. */
    public static function icon(?string $type): string
    {
        $config = self::typeConfig($type);

        return (string) ($config['icon'] ?? 'link');
    }

    /**
     * The hosts a type accepts, for an error message that tells the artist
     * what would have worked.
     *
     * @return list<string>
     */
    public static function hostsFor(?string $type): array
    {
        $config = self::typeConfig($type);

        return array_values((array) ($config['hosts'] ?? []));
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function typeConfig(?string $type): ?array
    {
        if ($type === null) {
            return null;
        }

        $config = config('riff.support.types.'.$type);

        return is_array($config) ? $config : null;
    }

    /**
     * Validate and normalise a URL. Returns [canonical url, host] or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function canonicalise(?string $url): ?array
    {
        $url = trim((string) $url);

        if ($url === '' || mb_strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        // A control character or whitespace inside a URL is either an
        // encoding mistake or an attempt to smuggle something past a
        // reader's eye; either way it never belongs in an href.
        if (preg_match('/[\x00-\x20\x7F]/', $url)) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)) {
            return null;
        }

        // https://bandcamp.com@evil.example: parse_url reads the part
        // before the @ as credentials, so the host a browser resolves is
        // not the one a human reads. Riff publishes neither.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        if (! self::isPublicHostname($host)) {
            return null;
        }

        // The fragment is dropped (it never changes where a link goes and
        // is a favourite place to park junk); the query is kept, because
        // store and ticket links routinely carry a product or variant in
        // it. The path is left exactly as given -- re-encoding it risks
        // breaking a link that works.
        $canonical = 'https://'.$host;

        if (isset($parts['port']) && ! in_array((int) $parts['port'], [80, 443], true)) {
            // A support link on a non-standard port is not a storefront.
            return null;
        }

        $canonical .= $parts['path'] ?? '';

        if (isset($parts['query']) && $parts['query'] !== '') {
            $canonical .= '?'.$parts['query'];
        }

        return [$canonical, $host];
    }

    /**
     * A dotted, non-numeric hostname. Rules out bare names like "localhost"
     * and IP literals, so a published support link always points somewhere
     * on the public internet.
     */
    private static function isPublicHostname(string $host): bool
    {
        if ($host === '' || mb_strlen($host) > 253 || ! str_contains($host, '.')) {
            return false;
        }

        // An IPv4 literal, or IPv6 in brackets.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || str_starts_with($host, '[')) {
            return false;
        }

        return (bool) preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $host);
    }

    /**
     * @param  list<string>  $domains
     */
    private static function hostMatches(string $host, array $domains): bool
    {
        foreach ($domains as $domain) {
            $domain = strtolower($domain);

            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }
}
