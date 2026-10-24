<?php

declare(strict_types=1);
use Symfony\Component\Finder\Finder;

arch('no debugging calls are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'var_export', 'die'])
    ->not->toBeUsed();

arch('every file declares strict types')
    ->expect(['Phoenix1331\FilamentJevModeration', 'Phoenix1331\FilamentJevModeration\Tests'])
    ->toUseStrictTypes();

arch('php best practices')->preset()->php();

arch('no insecure functions')->preset()->security();

arch('the plugin works with any moderated model, never the demo or test ones')
    ->expect('Phoenix1331\FilamentJevModeration')
    ->not->toUse(['Workbench\App', 'Workbench\Database', 'Phoenix1331\FilamentJevModeration\Tests'])
    ->ignoring('Phoenix1331\FilamentJevModeration\Tests');

arch('the plugin never touches the core\'s internals')
    ->expect('Phoenix1331\FilamentJevModeration')
    ->not->toUse(['Phoenix1331\JevModeration\Moderation', 'Phoenix1331\JevModeration\Policies'])
    ->ignoring('Phoenix1331\FilamentJevModeration\Tests');

arch('the plugin sends nothing anywhere and writes nothing to logs')
    ->expect('Phoenix1331\FilamentJevModeration')
    ->not->toUse(['Illuminate\Support\Facades\Http', 'Illuminate\Http\Client', 'GuzzleHttp', 'Illuminate\Support\Facades\Log', 'Illuminate\Log', 'logger', 'info'])
    ->ignoring('Phoenix1331\FilamentJevModeration\Tests');

it('never prints unescaped output in a view', function (): void {
    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php') as $file) {
        expect($file->getContents())->not->toContain('{!!', "Unescaped output in resources/views/{$file->getRelativePathname()}");
    }
});

it('never writes a moderation decision itself: every review goes through the core', function (): void {
    $writes = '/->(?:create|createQuietly|forceCreate|insert|insertGetId|insertOrIgnore|upsert|update|updateOrCreate|updateQuietly|save|saveQuietly|push|delete|forceDelete|increment|decrement|truncate)\(|DB::(?:table|insert|update|delete|statement|unprepared)\(/';

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/src')->name('*.php') as $file) {
        preg_match_all($writes, $file->getContents(), $matches);

        expect($matches[0])->toBe([], "Database write in src/{$file->getRelativePathname()}");
    }
});
