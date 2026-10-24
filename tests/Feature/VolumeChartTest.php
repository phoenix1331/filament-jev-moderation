<?php

declare(strict_types=1);

use Filament\Support\RawJs;
use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\DecisionVolume;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Widgets\DecisionVolumeChart;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// Grouping by date(created_at) differs per database: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    Carbon::setTestNow('2026-10-23 12:00:00');
    JevModerationPlugin::get()->authorize(true);
    $this->actingAs(User::factory()->create());
});

function volume(int $days): array
{
    return (new DecisionVolume(JevModerationPlugin::get()))->perDay($days);
}

/**
 * @return array<string, mixed>
 */
function chartData(?string $filter = null): array
{
    $chart = livewire(DecisionVolumeChart::class);

    if ($filter !== null) {
        $chart->set('filter', $filter);
    }

    return (fn (): array => $this->getData())->call($chart->instance());
}

describe('counts', function (): void {
    it('counts decisions per day by status, with zeros for quiet days', function (): void {
        $post = forumPost();
        decisionFor($post)->passed()->create(['created_at' => '2026-10-23 00:00:00']);
        decisionFor($post)->passed()->create(['created_at' => '2026-10-23 11:59:00']);
        decisionFor($post)->flagged()->create(['created_at' => '2026-10-21 23:59:59']);
        decisionFor($post)->approved()->create(['created_at' => '2026-10-17 00:00:00']);
        decisionFor($post)->failed()->create(['created_at' => '2026-10-22 08:00:00']);

        expect(volume(7))->toBe([
            'days' => ['2026-10-17', '2026-10-18', '2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'],
            'counts' => [
                'passed' => [0, 0, 0, 0, 0, 0, 2],
                'approved' => [1, 0, 0, 0, 0, 0, 0],
                'flagged' => [0, 0, 0, 0, 1, 0, 0],
                'rejected' => [0, 0, 0, 0, 0, 0, 0],
                'blocked' => [0, 0, 0, 0, 0, 0, 0],
                'failed' => [0, 0, 0, 0, 0, 1, 0],
            ],
        ]);
    });

    it('leaves out pending rows, days before the window and types the plugin does not show', function (): void {
        decisionFor(forumPost())->pending()->create();
        decisionFor(forumPost())->blocked()->create(['created_at' => '2026-10-16 23:59:59']);
        decisionFor(ChatMessage::factory()->createQuietly())->blocked()->create();

        JevModerationPlugin::get()->models([ForumPost::class]);

        expect(array_sum(array_merge(...array_values(volume(7)['counts']))))->toBe(0);
    });

    it('stacks shown, then waiting, then removed, then failed checks', function (): void {
        expect(array_keys(volume(1)['counts']))->toBe(['passed', 'approved', 'flagged', 'rejected', 'blocked', 'failed'])
            ->and(array_map(fn (ModerationStatus $status): string => $status->value, DecisionVolume::STATUSES))->toBe(array_keys(DecisionVolumeChart::COLOURS));
    });
});

describe('chart', function (): void {
    it('labels days and statuses, over the last 30 days by default', function (): void {
        decisionFor(forumPost())->flagged()->create();

        $data = chartData();

        expect($data['labels'])->toHaveCount(30)
            ->and($data['labels'][0])->toBe('24 Sep')
            ->and(end($data['labels']))->toBe('23 Oct')
            ->and(array_column($data['datasets'], 'label'))->toBe(['Passed', 'Approved', 'Flagged', 'Rejected', 'Blocked', 'Check failed'])
            ->and(array_column($data['datasets'], 'status'))->toBe(['passed', 'approved', 'flagged', 'rejected', 'blocked', 'failed'])
            ->and(end($data['datasets'][2]['data']))->toBe(1);
    });

    it('offers 7, 30 and 90 days, and falls back to 30 for anything else', function (?string $filter, int $days): void {
        expect(chartData($filter)['labels'])->toHaveCount($days);
    })->with([
        ['7', 7],
        ['90', 90],
        ['365', 30],
        ['all', 30],
    ]);

    it('lists each status total as text beside the chart', function (): void {
        decisionFor(forumPost())->flagged()->create();
        decisionFor(forumPost())->flagged()->create(['created_at' => now()->subDays(10)]);
        decisionFor(forumPost())->blocked()->create(['created_at' => now()->subDays(3)]);

        livewire(DecisionVolumeChart::class)
            ->assertSee('Decisions per day')
            ->assertSee('Passed 0 · Approved 0 · Flagged 2 · Rejected 0 · Blocked 1 · Check failed 0')
            ->set('filter', '7')
            ->assertSee('Flagged 1 · Rejected 0 · Blocked 1');
    });

    it('colours each status from a fixed light and dark set, resolved at draw time', function (): void {
        $options = (fn (): RawJs => $this->getOptions())->call(livewire(DecisionVolumeChart::class)->instance());
        $script = $options->toHtml();

        foreach (DecisionVolumeChart::COLOURS as $status => [$light, $dark]) {
            expect($script)->toContain("{$status}: '{$light}'")->toContain("{$status}: '{$dark}'");
        }

        expect($script)->toContain("document.documentElement.classList.contains('dark')")
            ->toContain('[context.dataset.status]')
            ->toContain('stacked: true');
    });

    it('uses no double quote in its options, which Filament renders inside an attribute', function (): void {
        $options = (fn (): RawJs => $this->getOptions())->call(livewire(DecisionVolumeChart::class)->instance());

        expect($options->toHtml())->not->toContain('"');
    });
});

describe('placement and access', function (): void {
    it('sits below the review queue, full width', function (): void {
        livewire(ListReviewQueue::class)->assertSeeLivewire(DecisionVolumeChart::class);

        expect((new DecisionVolumeChart)->getColumnSpan())->toBe('full');
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(false);

        expect(DecisionVolumeChart::canView())->toBeFalse();
        livewire(DecisionVolumeChart::class)->assertForbidden();
    });

    it('polls once a minute', function (): void {
        livewire(DecisionVolumeChart::class)->assertSeeHtml('wire:poll.60s');
    });
});
