<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * TEST-UNTRAITED: audited — intentionally DB-free (unauthenticated route
 * assertions only). No DB trait needed.
 */
class ExampleTest extends TestCase
{
    public function test_login_page_is_accessible(): void
    {
        $this->get('/login')->assertStatus(200);
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
