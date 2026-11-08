<?php

namespace Tests\Unit\Services;

use App\Contracts\EventProvider;
use App\Services\Events\TicketmasterEvents;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TicketmasterEventsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_it_is_bound_as_the_event_provider(): void
    {
        $this->assertInstanceOf(TicketmasterEvents::class, app(EventProvider::class));
    }

    public function test_returns_empty_without_an_api_key_and_sends_nothing(): void
    {
        Http::fake();

        $events = app(TicketmasterEvents::class)->eventsNear(41.5, -72.5);

        $this->assertTrue($events->isEmpty());
        Http::assertNothingSent();
    }

    public function test_maps_provider_events_into_local_events(): void
    {
        config(['services.ticketmaster.key' => 'test-key']);
        Http::fake([
            'app.ticketmaster.com/*' => Http::response([
                '_embedded' => [
                    'events' => [
                        [
                            'name' => 'Local Show',
                            'url' => 'https://example.com/show',
                            'dates' => ['start' => ['dateTime' => '2026-12-01T20:00:00Z']],
                            '_embedded' => [
                                'venues' => [[
                                    'name' => 'The Space',
                                    'city' => ['name' => 'Hamden'],
                                    'state' => ['stateCode' => 'CT'],
                                    'location' => ['latitude' => '41.40', 'longitude' => '-72.90'],
                                ]],
                            ],
                        ],
                    ],
                ],
            ]),
        ]);

        $events = app(TicketmasterEvents::class)->eventsNear(41.5, -72.5);

        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame('Local Show', $event->name);
        $this->assertSame('The Space', $event->venue);
        $this->assertSame('ticketmaster', $event->source);
        $this->assertSame(41.40, $event->lat);
    }

    public function test_returns_empty_on_provider_errors(): void
    {
        config(['services.ticketmaster.key' => 'test-key']);
        Http::fake([
            'app.ticketmaster.com/*' => Http::response('rate limited', 429),
        ]);

        $this->assertTrue(app(TicketmasterEvents::class)->eventsNear(41.5, -72.5)->isEmpty());
    }

    public function test_caches_results_per_location(): void
    {
        config(['services.ticketmaster.key' => 'test-key']);
        Http::fake([
            'app.ticketmaster.com/*' => Http::response(['_embedded' => ['events' => []]]),
        ]);

        $service = app(TicketmasterEvents::class);
        $service->eventsNear(41.5, -72.5);
        $service->eventsNear(41.5, -72.5);

        Http::assertSentCount(1);
    }
}
