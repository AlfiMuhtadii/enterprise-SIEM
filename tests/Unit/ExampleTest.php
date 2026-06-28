<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExampleTest extends TestCase
{
    public function test_php_version_meets_minimum(): void
    {
        $this->assertGreaterThanOrEqual(80100, PHP_VERSION_ID, 'PHP 8.1+ required');
    }
}
