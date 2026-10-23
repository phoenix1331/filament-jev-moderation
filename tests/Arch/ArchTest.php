<?php

declare(strict_types=1);

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
