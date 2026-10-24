<?php

declare(strict_types=1);

namespace Workbench\App\Filament\Pages;

use Filament\Pages\Dashboard;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;
use Livewire\Attributes\Url;
use Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable;
use Phoenix1331\FilamentJevModeration\Widgets\DecisionVolumeChart;
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;

/**
 * One plugin widget, full width, without the panel chrome, so the
 * screenshot script can capture each widget on its own. Workbench only.
 */
class WidgetPreview extends Dashboard
{
    protected static string $routePath = 'widget-preview';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $widget = '';

    public static function hideChrome(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => '<style>.fi-sidebar, .fi-topbar, .fi-header, .fi-sidebar-close-overlay { display: none !important; } .fi-main-ctn { padding-top: 0 !important; } .fi-main { padding-block: 1rem !important; }</style>',
            scopes: static::class,
        );
    }

    public function getColumns(): int
    {
        return 1;
    }

    /**
     * The calibration widget as it sits under the queue: flagged bands only.
     * The full table is on the thresholds page.
     *
     * @return list<class-string<Widget>|WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return match ($this->widget) {
            'moderation-overview' => [ModerationOverview::class],
            'decision-volume-chart' => [DecisionVolumeChart::class],
            'calibration-table' => [CalibrationTable::make(['onlyFlagged' => true])],
            default => [],
        };
    }
}
