<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pojistka: žádný test nesmí volat skutečnou síť (typicky ARES).
        // Test, který HTTP potřebuje, si musí explicitně nastavit Http::fake().
        Http::preventStrayRequests();
    }
}
