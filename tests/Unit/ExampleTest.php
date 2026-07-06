<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * TEST-UNTRAITED: audited — extends plain PHPUnit\Framework\TestCase, never
 * boots the Laravel application, categorically DB-free. No DB trait needed.
 */
class ExampleTest extends TestCase
{
    public function test_php_version_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(80100, PHP_VERSION_ID, 'PHP 8.1+ required');
    }
}
