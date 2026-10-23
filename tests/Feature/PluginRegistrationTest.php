<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;

it('registers the plugin on the panel', function (): void {
    expect(Filament::getPanel('admin')->hasPlugin(JevModerationPlugin::ID))->toBeTrue();
});

it('resolves the registered plugin instance', function (): void {
    expect(JevModerationPlugin::get())
        ->toBeInstanceOf(JevModerationPlugin::class)
        ->getId()->toBe('jev-moderation');
});

it('boots on a panel', function (): void {
    $plugin = JevModerationPlugin::make();
    $plugin->boot(Panel::make()->id('other'));

    expect($plugin)->toBeInstanceOf(JevModerationPlugin::class);
});

it('rejects a container binding that is not the plugin', function (): void {
    app()->bind(JevModerationPlugin::class, fn (): stdClass => new stdClass);

    JevModerationPlugin::make();
})->throws(LogicException::class, 'Plugin [jev-moderation] is not an instance of');
