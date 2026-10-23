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

    /**
     * Users, the core's decisions table and the workbench's moderated
     * tables, rolled back after the test so PostgreSQL, MySQL and MariaDB
     * start each test empty too.
     */
    protected function migrateModerationTables(): void
    {
        $this->loadLaravelMigrations();
        $this->loadMigrationsFrom(['--path' => [
            dirname(__DIR__).'/vendor/phoenix1331/laravel-jev-moderation/database/migrations',
            dirname(__DIR__).'/workbench/database/migrations',
        ]]);
    }

    protected function defineEnvironment($app): void
    {
        $app->make('view')->addNamespace('fixtures', __DIR__.'/Fixtures/views');
    }

    protected function getPackageProviders($app): array
    {
        return [
            FilamentJevModerationServiceProvider::class,
            TestPanelProvider::class,
        ];
    }
}
