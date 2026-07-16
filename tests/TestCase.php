<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Feature tests render Blade layouts that use the @vite directive. Stub Vite
        // so tests never require a built `public/build/manifest.json` — this decouples
        // the PHP test job from the frontend build job in CI (they run separately).
        if (method_exists($this, 'withoutVite')) {
            $this->withoutVite();
        }
    }
}
