<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    // The map (where / now lands) reads the genre catalogue.
    use RefreshDatabase;

    /**
     * The root sends everyone -- guests included -- to the map.
     */
    public function test_the_root_lands_on_the_map(): void
    {
        $this->get('/')->assertRedirect('/map');
        $this->followingRedirects()->get('/')->assertOk();
    }
}
