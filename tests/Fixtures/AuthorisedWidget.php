<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures;

use Filament\Widgets\StatsOverviewWidget;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesWidgetView;

/**
 * A bare widget behind the plugin's widget authorisation. Extends Filament's
 * stats widget for its shipped view, so no test-only view is needed.
 */
class AuthorisedWidget extends StatsOverviewWidget
{
    use AuthorisesWidgetView;

    protected function getStats(): array
    {
        return [];
    }
}
