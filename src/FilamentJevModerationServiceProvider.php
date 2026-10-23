<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration;

use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Support\ServiceProvider;

class FilamentJevModerationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filament-jev-moderation');
        $this->publishes([
            __DIR__.'/../resources/lang' => $this->app->langPath('vendor/filament-jev-moderation'),
        ], 'filament-jev-moderation-translations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'filament-jev-moderation');

        // Published with `php artisan filament:assets`, like Filament's own.
        FilamentAsset::register([
            Css::make('filament-jev-moderation', self::stylesheet()),
        ], package: 'phoenix1331/filament-jev-moderation');
    }

    /**
     * Filament 4 and 5 share colour variables (`var(--gray-500)`), so one sheet serves both.
     */
    public static function stylesheet(): string
    {
        return __DIR__.'/../resources/dist/filament-jev-moderation.css';
    }
}
