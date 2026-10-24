<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Number;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesWidgetView;
use Phoenix1331\FilamentJevModeration\Data\Calibration;
use Phoenix1331\FilamentJevModeration\Data\CalibrationBand;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\CalibrationPairs;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Support\CalibrationCalculator;

/**
 * How often moderators overturn Jev in each confidence band, per policy
 * and category, for one Jev version. Bands where they overturn more than
 * the plugin's warning rate, with enough reviews, are highlighted with the
 * threshold change they suggest.
 */
class CalibrationTable extends Widget
{
    use AuthorisesWidgetView;

    /** The Jev version shown; null for the newest. Checked against the versions seen. */
    public ?string $model = null;

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
        $calibration = $this->calibration();
        $policies = [];

        foreach ($calibration->bands as $band) {
            $policies[$band->policy][] = $band;
        }

        return [
            'calibration' => $calibration,
            'policies' => $policies,
            'days' => CalibrationPairs::DAYS,
            'rate' => Number::percentage($plugin->getOverrideWarningRate() * 100) ?: '0%',
            'sample' => $plugin->getMinimumSample(),
        ];
    }

    /**
     * Run once per render. Protected: Livewire lets the browser call any public method.
     */
    protected function calibration(): Calibration
    {
        $plugin = self::plugin();
        $calculator = new CalibrationCalculator($plugin->getOverrideWarningRate(), $plugin->getMinimumSample());
        $pairs = (new CalibrationPairs($plugin))->get();
        $calibration = $calculator->calculate($pairs, $this->model);

        // An unknown version (stale or tampered with) falls back to the newest.
        if ($this->model !== null && ! in_array($this->model, $calibration->models, true)) {
            $this->model = null;
            $calibration = $calculator->calculate($pairs);
        }

        return $calibration;
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
