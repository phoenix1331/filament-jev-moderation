<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use Phoenix1331\FilamentJevModeration\FilamentJevModerationServiceProvider;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\TestPanelProvider;

abstract class TestCase extends Orchestra
{
    // Loads every Filament and Livewire provider without a hand-kept list.
    protected $enablesPackageDiscoveries = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            FilamentJevModerationServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
