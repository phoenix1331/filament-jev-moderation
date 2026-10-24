<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Widgets;

use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\Concerns\AuthorisesWidgetView;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\DecisionVolume;

/**
 * Decisions per day, stacked by status. Each status keeps one colour in
 * every chart; the light and dark sets were each checked for lightness,
 * chroma, colour-blind and normal-vision separation between neighbouring
 * segments, in this stacking order. Amber and magenta sit below 3:1 on a
 * light surface, so the totals are also given as text.
 */
class DecisionVolumeChart extends ChartWidget
{
    use AuthorisesWidgetView;

    /** Status value to [light, dark] colour. */
    public const array COLOURS = [
        'passed' => ['#008300', '#008300'],
        'approved' => ['#e87ba4', '#d55181'],
        'flagged' => ['#eda100', '#c98500'],
        'rejected' => ['#6250d6', '#9085e9'],
        'blocked' => ['#e34948', '#e66767'],
        'failed' => ['#2a78d6', '#3987e5'],
    ];

    /** The windows offered, in days. */
    public const array WINDOWS = [7, 30, 90];

    public ?string $filter = '30';

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): string
    {
        return __('filament-jev-moderation::widgets.volume.heading');
    }

    public function getDescription(): string
    {
        $totals = [];

        foreach ($this->volume()['counts'] as $status => $perDay) {
            $totals[] = __('filament-jev-moderation::widgets.volume.total', [
                'status' => __("filament-jev-moderation::statuses.{$status}"),
                'count' => array_sum($perDay),
            ]);
        }

        return implode(' · ', $totals);
    }

    protected function getPollingInterval(): ?string
    {
        return '60s';
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * Keyed by days; PHP stores numeric keys as integers.
     *
     * @return array<int, string>
     */
    protected function getFilters(): array
    {
        $filters = [];

        foreach (self::WINDOWS as $days) {
            $filters[$days] = __('filament-jev-moderation::widgets.volume.window', ['days' => $days]);
        }

        return $filters;
    }

    /**
     * @return array{labels: list<string>, datasets: list<array{label: string, status: string, data: list<int>}>}
     */
    protected function getData(): array
    {
        $volume = $this->volume();
        $datasets = [];

        foreach ($volume['counts'] as $status => $perDay) {
            $datasets[] = [
                'label' => __("filament-jev-moderation::statuses.{$status}"),
                'status' => $status,
                'data' => $perDay,
            ];
        }

        return [
            'labels' => array_map(static fn (string $day): string => Carbon::parse($day)->isoFormat('D MMM'), $volume['days']),
            'datasets' => $datasets,
        ];
    }

    /**
     * Colours resolve per status at draw time, so a theme switch repaints
     * them. Filament renders these options inside a double-quoted
     * attribute, so the script uses single quotes only.
     */
    protected function getOptions(): RawJs
    {
        $light = [];
        $dark = [];

        foreach (self::COLOURS as $status => [$lightColour, $darkColour]) {
            $light[] = "{$status}: '{$lightColour}'";
            $dark[] = "{$status}: '{$darkColour}'";
        }

        $light = implode(', ', $light);
        $dark = implode(', ', $dark);

        return RawJs::make(<<<JS
            {
                datasets: {
                    bar: {
                        backgroundColor: (context) => (document.documentElement.classList.contains('dark') ? { {$dark} } : { {$light} })[context.dataset.status],
                        borderColor: 'rgba(0, 0, 0, 0)',
                        borderWidth: { top: 2 },
                        borderSkipped: 'bottom',
                        maxBarThickness: 32,
                    },
                },
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: { mode: 'index', intersect: false },
                },
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } },
                },
            }
            JS);
    }

    /**
     * @return array{days: list<string>, counts: array<string, list<int>>}
     */
    private function volume(): array
    {
        $days = is_numeric($this->filter) && in_array((int) $this->filter, self::WINDOWS, true) ? (int) $this->filter : 30;

        return (new DecisionVolume(JevModerationPlugin::current() ?? JevModerationPlugin::make()))->perDay($days);
    }
}
