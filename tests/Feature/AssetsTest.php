<?php

declare(strict_types=1);

use Composer\InstalledVersions;
use Filament\Support\Facades\FilamentAsset;
use Phoenix1331\FilamentJevModeration\FilamentJevModerationServiceProvider;

it('registers the stylesheet', function (): void {
    $styles = FilamentAsset::getStyles(['phoenix1331/filament-jev-moderation']);

    expect($styles)->toHaveCount(1)
        ->and($styles[0]->getId())->toBe('filament-jev-moderation')
        ->and($styles[0]->getPath())->toBe(FilamentJevModerationServiceProvider::stylesheet())
        ->and(file_exists($styles[0]->getPath()))->toBeTrue();
});

it('runs on a supported Filament major', function (): void {
    expect((int) InstalledVersions::getVersion('filament/support'))->toBeIn([4, 5]);
});

it('styles only its own fi-jev-moderation classes', function (): void {
    $css = (string) file_get_contents(FilamentJevModerationServiceProvider::stylesheet());
    preg_match_all('/^\s*(?:\.dark\s+)?\.([a-z0-9-]+)/m', $css, $matches);

    expect($matches[1])->not->toBeEmpty()
        ->each->toStartWith('fi-jev-moderation-');
});

it('loads its translations', function (): void {
    expect(__('filament-jev-moderation::navigation.group'))->toBe('Jev Moderation for Filament');
});
