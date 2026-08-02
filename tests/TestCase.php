<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ohne gebautes Bundle gibt es kein Vite-Manifest, und jede Seite
        // bricht mit „manifest not found" ab. Diese Tests prüfen Verhalten,
        // nicht das Bundle: Ob es entsteht, sagt der Zweig „Oberfläche"; ob es
        // ausgeliefert wird, der Integrationslauf mit dem gebauten Paket.
        $this->withoutVite();
    }
}
