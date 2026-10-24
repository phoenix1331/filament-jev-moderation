<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Widgets;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesWidgetView;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\OverviewStats;

/**
 * Flagged content waiting, automatic blocks today, median time to review
 * and how often moderators overturn Jev. Shown above the review queue; a
 * host can also add it to its own dashboard, where the plugin's options
 * apply when the panel uses the plugin and defaults apply otherwise.
 */
class ModerationOverview extends StatsOverviewWidget
{
    use AuthorisesWidgetView;

    protected function getPollingInterval(): ?string
    {
        return '60s';
    }

    protected function getStats(): array
    {
        $plugin = JevModerationPlugin::current() ?? JevModerationPlugin::make();
        $stats = new OverviewStats($plugin);

        return [
            $this->waiting($stats),
            Stat::make(__('filament-jev-moderation::widgets.overview.blocked'), (string) $stats->blockedToday())
                ->description(__('filament-jev-moderation::widgets.overview.blocked_description'))
                ->color('gray'),
            $this->reviewTime($stats),
            $this->overrideRate($stats, $plugin),
        ];
    }

    private function waiting(OverviewStats $stats): Stat
    {
        $waiting = $stats->waiting();
        $oldest = $stats->oldestWaiting();

        return Stat::make(__('filament-jev-moderation::widgets.overview.waiting'), (string) $waiting)
            ->description($oldest === null
                ? __('filament-jev-moderation::widgets.overview.waiting_none')
                : __('filament-jev-moderation::widgets.overview.waiting_oldest', ['age' => $oldest->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]))
            ->color($waiting > 0 ? 'warning' : 'success');
    }

    private function reviewTime(OverviewStats $stats): Stat
    {
        ['seconds' => $seconds, 'reviews' => $reviews] = $stats->reviewTime();

        return Stat::make(
            __('filament-jev-moderation::widgets.overview.review_time'),
            $seconds === null ? __('filament-jev-moderation::widgets.overview.none') : self::duration($seconds),
        )
            ->description(trans_choice('filament-jev-moderation::widgets.overview.review_time_description', $reviews, ['count' => $reviews, 'days' => OverviewStats::DAYS]))
            ->color('gray');
    }

    /**
     * Highlighted once moderators overturn more than the plugin's warning
     * rate, with at least its minimum sample checked.
     */
    private function overrideRate(OverviewStats $stats, JevModerationPlugin $plugin): Stat
    {
        $agreement = $stats->agreement();
        $rate = $agreement->overrideRate();
        $high = $rate !== null && $agreement->checked() >= $plugin->getMinimumSample() && $rate > $plugin->getOverrideWarningRate();

        return Stat::make(
            __('filament-jev-moderation::widgets.overview.override_rate'),
            $rate === null ? __('filament-jev-moderation::widgets.overview.none') : (Number::percentage($rate * 100) ?: '0%'),
        )
            ->description(__('filament-jev-moderation::widgets.overview.override_rate_description', [
                'overturned' => $agreement->overturned,
                'checked' => $agreement->checked(),
                'days' => OverviewStats::DAYS,
            ]))
            ->color($high ? 'danger' : 'gray');
    }

    /**
     * Two units at most: "45s", "12m 30s", "3h 5m", "2d 4h".
     */
    private static function duration(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
