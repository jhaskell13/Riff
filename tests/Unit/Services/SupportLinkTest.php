<?php

namespace Tests\Unit\Services;

use App\Services\SupportLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A support section is the most valuable thing on an artist page to
 * hijack: it is where people are asked to spend money. These cover what
 * the parser must refuse as much as what it accepts.
 */
class SupportLinkTest extends TestCase
{
    #[Test]
    public function it_accepts_a_bandcamp_page(): void
    {
        $target = SupportLink::parse('https://thesilvermill.bandcamp.com', 'bandcamp');

        $this->assertNotNull($target);
        $this->assertSame('bandcamp', $target->type);
        $this->assertSame('https://thesilvermill.bandcamp.com', $target->url);
        $this->assertSame('thesilvermill.bandcamp.com', $target->host);
    }

    #[Test]
    public function it_keeps_the_query_but_drops_the_fragment(): void
    {
        // Store links routinely carry a product or variant in the query;
        // a fragment never changes where a link goes.
        $target = SupportLink::parse('https://shop.example.com/record?variant=7#reviews', 'store');

        $this->assertNotNull($target);
        $this->assertSame('https://shop.example.com/record?variant=7', $target->url);
    }

    #[Test]
    public function it_upgrades_http_to_https_and_strips_www(): void
    {
        $target = SupportLink::parse('http://www.example.com/shop', 'store');

        $this->assertNotNull($target);
        $this->assertSame('https://example.com/shop', $target->url);
    }

    #[Test]
    public function a_bandcamp_link_must_be_on_bandcamp(): void
    {
        // The allowlisted types are the ones where going elsewhere is a
        // scam rather than a typo.
        $this->assertNull(SupportLink::parse('https://notbandcamp.example.com/band', 'bandcamp'));
        $this->assertNull(SupportLink::parse('https://ko-fi.com/band', 'bandcamp'));
    }

    #[Test]
    public function a_tip_jar_must_be_on_a_known_host(): void
    {
        $this->assertNotNull(SupportLink::parse('https://ko-fi.com/band', 'tip_jar'));
        $this->assertNotNull(SupportLink::parse('https://www.patreon.com/band', 'tip_jar'));
        $this->assertNull(SupportLink::parse('https://pay-me-now.example.com/band', 'tip_jar'));
    }

    #[Test]
    public function an_untyped_store_link_may_be_any_host(): void
    {
        // A band's own shop is on its own domain; no allowlist could
        // anticipate it.
        $this->assertNotNull(SupportLink::parse('https://thesilvermill.com/shop', 'store'));
        $this->assertNotNull(SupportLink::parse('https://merch.example.co.uk/tees', 'merch'));
    }

    #[Test]
    public function it_refuses_an_unknown_type(): void
    {
        $this->assertNull(SupportLink::parse('https://example.com', 'crypto'));
        $this->assertNull(SupportLink::parse('https://example.com', null));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function rejectedUrls(): array
    {
        return [
            // Not a link to anywhere.
            [''],
            ['   '],
            ['example.com/shop'],
            // Schemes that must never reach an href.
            ['javascript:alert(1)'],
            ['data:text/html;base64,PHNjcmlwdD4='],
            ['mailto:band@example.com'],
            ['ftp://example.com/shop'],
            // Credentials: a human reads the host before the @, a browser
            // resolves the one after it.
            ['https://bandcamp.com@evil.example/band'],
            ['https://user:pass@example.com/shop'],
            // Not a public host.
            ['https://localhost/shop'],
            ['https://127.0.0.1/shop'],
            ['https://192.168.1.10/shop'],
            ['https://[::1]/shop'],
            ['https://example/shop'],
            // A storefront does not live on a odd port.
            ['https://example.com:8080/shop'],
            // Control characters smuggled *inside* the URL. Surrounding
            // whitespace is a different thing -- see the test below.
            ["https://exa\tmple.com/shop"],
            ["https://example.com/sh\x00op"],
            ['https://example.com/sh op'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedUrls')]
    public function it_refuses_links_it_will_not_publish(string $url): void
    {
        $this->assertNull(SupportLink::parse($url, 'store'), "Expected to reject: {$url}");
    }

    #[Test]
    public function it_trims_surrounding_whitespace_rather_than_refusing(): void
    {
        // People paste links with a trailing newline. That is a normal
        // paste, not an attack, and refusing it would be a bad error for
        // a correct link.
        $target = SupportLink::parse("  https://example.com/shop\n", 'store');

        $this->assertNotNull($target);
        $this->assertSame('https://example.com/shop', $target->url);
    }

    #[Test]
    public function it_refuses_an_over_long_url(): void
    {
        $this->assertNull(SupportLink::parse('https://example.com/'.str_repeat('a', 2100), 'store'));
    }

    #[Test]
    public function it_detects_a_type_from_an_allowlisted_host(): void
    {
        $this->assertSame('bandcamp', SupportLink::detect('https://band.bandcamp.com'));
        $this->assertSame('tip_jar', SupportLink::detect('https://ko-fi.com/band'));
        // A store is indistinguishable from anything else by host alone.
        $this->assertNull(SupportLink::detect('https://thesilvermill.com/shop'));
        $this->assertNull(SupportLink::detect('nonsense'));
    }

    #[Test]
    public function it_exposes_labels_icons_and_hosts_for_the_form(): void
    {
        $this->assertSame('Bandcamp', SupportLink::label('bandcamp'));
        $this->assertNull(SupportLink::label('nope'));
        $this->assertSame('album', SupportLink::icon('bandcamp'));
        // An unknown type still renders something rather than breaking.
        $this->assertSame('link', SupportLink::icon('nope'));
        $this->assertContains('bandcamp.com', SupportLink::hostsFor('bandcamp'));
        $this->assertSame([], SupportLink::hostsFor('store'));
    }
}
