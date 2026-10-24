<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Number;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesPageAccess;
use Phoenix1331\FilamentJevModeration\Concerns\HasModerationNavigation;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\CalibrationPairs;
use Phoenix1331\FilamentJevModeration\Support\CalibrationCalculator;
use Phoenix1331\FilamentJevModeration\Support\ThresholdOverview;

/**
 * Read-only: each policy's thresholds from config, with every calibration
 * band alongside and, where moderators overturn Jev too often, the change
 * to make in config and the rescan command to run after it.
 */
class Thresholds extends Page
{
    use AuthorisesPageAccess;
    use HasModerationNavigation;

    /** The Jev version calibration is shown for; null for the newest. */
    public ?string $model = null;

    protected static ?string $slug = 'moderation/thresholds';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('filament-jev-moderation::thresholds.navigation_label');
    }

    public function getTitle(): string
    {
        return __('filament-jev-moderation::thresholds.title');
    }

    public function getSubheading(): string
    {
        return __('filament-jev-moderation::thresholds.subheading');
    }

    public function getView(): string
    {
        return 'filament-jev-moderation::pages.thresholds';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plugin = JevModerationPlugin::get();
        $calibration = CalibrationCalculator::forPlugin($plugin, $this->model);

        if ($this->model !== null && $calibration->model !== $this->model) {
            $this->model = null;
        }

        return [
            'calibration' => $calibration,
            'policies' => (new ThresholdOverview($plugin))->for($calibration),
            'days' => CalibrationPairs::DAYS,
            'rate' => Number::percentage($plugin->getOverrideWarningRate() * 100) ?: '0%',
            'sample' => $plugin->getMinimumSample(),
        ];
    }
}
