<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEC-UNUSED-BOILERPLATE-ROUTES: routes/api.php shipped Laravel's scaffold
 * `/items` and `/items/{id}` mock endpoints -- un-throttled, unauthenticated,
 * unused by any EDR agent or the SOC front-end. Removed entirely rather than
 * throttled/secured, since they serve no real purpose (fake in-memory data,
 * not backed by any model or feature).
 */
class UnusedBoilerplateRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unprefixed_items_route_no_longer_exists(): void
    {
        $this->getJson('/api/items')->assertNotFound();
        $this->getJson('/api/items/1')->assertNotFound();
    }

    public function test_v1_items_route_no_longer_exists(): void
    {
        $this->getJson('/api/v1/items')->assertNotFound();
        $this->getJson('/api/v1/items/1')->assertNotFound();
    }
}
