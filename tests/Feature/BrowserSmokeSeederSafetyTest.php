<?php

namespace Tests\Feature;

use Database\Seeders\BrowserSmokeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BrowserSmokeSeederSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_smoke_seeder_refuses_the_normal_test_database(): void
    {
        config()->set('browser-smoke.password', 'Valid-Smoke-Password-29A!');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not a positively identified Phase 2.9A disposable database');

        $this->seed(BrowserSmokeSeeder::class);
    }
}
