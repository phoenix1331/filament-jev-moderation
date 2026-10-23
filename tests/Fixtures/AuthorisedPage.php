<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures;

use Filament\Pages\Page;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesPageAccess;

/**
 * A bare page behind the plugin's page authorisation.
 */
class AuthorisedPage extends Page
{
    use AuthorisesPageAccess;

    protected static ?string $slug = 'authorised-page';

    public function getView(): string
    {
        return 'fixtures::page';
    }
}
