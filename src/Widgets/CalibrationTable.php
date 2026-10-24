<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesWidgetView;
use Phoenix1331\FilamentJevModeration\Data\CalibrationBand;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Pages\Thresholds;
use Phoenix1331\FilamentJevModeration\Queries\CalibrationPairs;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Support\CalibrationCalculator;

/**
 * How often moderators overturn Jev in each confidence band, per policy
 * and category, for one Jev version. Bands where they overturn more than
 * the plugin's warning rate, with enough reviews, are highlighted with the
 * threshold change they suggest. With `onlyFlagged` (as above the review
 * queue) it lists just those bands and links to the thresholds page, which
 * shows every band.
 */
class CalibrationTable extends Widget
{
    use AuthorisesWidgetView;

    /** The Jev version shown; null for the newest. Checked against the versions seen. */
    public ?string $model = null;

    /** Show only the bands that suggest a change. */
    public bool $onlyFlagged = false;

    protected int|string|array $columnSpan = 'full';

    public function render(): View
    {
        return view('filament-jev-moderation::widgets.calibration', $this->getViewData());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $plugin = self::plugin();
        $calibration = CalibrationCalculator::forPlugin($plugin, $this->model);

        // An unknown version (stale or tampered with) fell back to the newest.
        if ($this->model !== null && $calibration->model !== $this->model) {
            $this->model = null;
        }

        $policies = [];

        foreach ($this->onlyFlagged ? $calibration->flagged() : $calibration->bands as $band) {
            $policies[$band->policy][] = $band;
        }

        return [
            'calibration' => $calibration,
            'policies' => $policies,
            'onlyFlagged' => $this->onlyFlagged,
            'thresholdsUrl' => JevModerationPlugin::current() === null ? null : Thresholds::getUrl(),
            'days' => CalibrationPairs::DAYS,
            'rate' => Number::percentage($plugin->getOverrideWarningRate() * 100) ?: '0%',
            'sample' => $plugin->getMinimumSample(),
        ];
    }

    public static function bandLabel(CalibrationBand $band): string
    {
        return $band->category === null
            ? __('filament-jev-moderation::widgets.calibration.low_confidence')
            : ReviewQueueResource::categoryLabel($band->category);
    }

    public static function advice(CalibrationBand $band): ?string
    {
        if ($band->advice === null || $band->threshold === null) {
            return null;
        }

        return __("filament-jev-moderation::widgets.calibration.advice.{$band->advice->value}", [
            'threshold' => number_format($band->threshold, 2),
        ]);
    }

    public static function percent(?float $rate): ?string
    {
        return $rate === null ? null : (Number::percentage($rate * 100) ?: '0%');
    }

    private static function plugin(): JevModerationPlugin
    {
        return JevModerationPlugin::current() ?? JevModerationPlugin::make();
    }
}
