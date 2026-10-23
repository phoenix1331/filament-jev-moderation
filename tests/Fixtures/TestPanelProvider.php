<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures;

use Filament\Panel;
use Filament\PanelProvider;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;

class TestPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->pages([AuthorisedPage::class])
            ->widgets([AuthorisedWidget::class])
            ->plugin(JevModerationPlugin::make());
    }
}
