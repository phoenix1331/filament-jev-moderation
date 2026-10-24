<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// The widget runs the calibration pairing: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    Carbon::setTestNow('2026-10-23 12:00:00');
    JevModerationPlugin::get()->authorize(true)->overrideWarningRate(0.3)->minimumSample(4);
    $this->actingAs(User::factory()->create());
});

/**
 * Jev's result for a new forum post (or chat message), then a moderator's review.
 *
 * @param  array<string, mixed>  $jev
 */
function reviewOf(string $status, string $review, string $category, float $confidence, array $jev = [], bool $chat = false): void
{
    $record = $chat ? ChatMessage::factory()->createQuietly() : forumPost();

    decisionFor($record)->create([
        'status' => $status,
        'source' => ModerationSource::Jev,
        'category' => $category,
        'confidence' => $confidence,
        'policy' => $chat ? 'chat' : 'community',
        'meta' => ['reason' => 'review_threshold'],
        ...$jev,
    ]);
    decisionFor($record)->{$review}()->create();
}

function times(int $count, Closure $callback): void
{
    foreach (range(1, $count) as $ignored) {
        $callback();
    }
}

it('shows an empty state before anything was reviewed', function (): void {
    livewire(CalibrationTable::class)
        ->assertSee('Calibration')
        ->assertSee('Nothing to calibrate yet')
        ->assertSee('Bands fill in as moderators approve and reject content Jev checked.')
        ->assertDontSeeHtml('<table');
});

it('explains the warning rate and minimum sample from the plugin', function (): void {
    livewire(CalibrationTable::class)
        ->assertSee('over the last 90 days. A band is highlighted when they overturned more than 30% of at least 4 checked flags and blocks.');

    JevModerationPlugin::get()->overrideWarningRate(0.25)->minimumSample(12);

    livewire(CalibrationTable::class)->assertSee('more than 25% of at least 12 checked');
});

it('lists bands per policy and category with their counts', function (): void {
    times(3, fn () => reviewOf('flagged', 'approved', 'harassment', 0.45));
    reviewOf('flagged', 'rejected', 'harassment', 0.46);
    reviewOf('flagged', 'rejected', 'spam', 0.75);
    reviewOf('passed', 'rejected', 'spam', 0.35);
    reviewOf('flagged', 'approved', 'sexual', 0.55, chat: true);

    livewire(CalibrationTable::class)
        ->assertSeeInOrder([
            'Policy: chat', 'Sexual', '0.5 – 0.6', '1', '1', '0', '100%', '0',
            'Policy: community',
            'Harassment', '0.4 – 0.5', '4', '3', '1', '75%', '0',
            'Spam', '0.3 – 0.4', '0', '0', '0', 'None checked', '1',
            'Spam', '0.7 – 0.8', '1', '0', '1', '0%', '0',
        ])
        ->assertSee('Jev version: jev-1.14.0');
});

it('highlights a band moderators overturn too often, with the suggested change', function (): void {
    times(3, fn () => reviewOf('flagged', 'approved', 'harassment', 0.45));
    reviewOf('flagged', 'rejected', 'harassment', 0.46);
    times(4, fn () => reviewOf('flagged', 'rejected', 'harassment', 0.62));

    livewire(CalibrationTable::class)
        ->assertSeeHtml('fi-jev-moderation-calibration-flagged')
        ->assertSeeInOrder([
            'Harassment', '0.4 – 0.5', '75%',
            'Moderators overturn most flags in this band: consider raising review_above to 0.50.',
            'Harassment', '0.6 – 0.7', '0%',
        ])
        ->assertSeeHtml('fi-jev-moderation-calibration-advice');
});

it('suggests raising block_above for overturned blocks', function (): void {
    times(4, fn () => reviewOf('blocked', 'approved', 'spam', 0.93));

    livewire(CalibrationTable::class)->assertSee('Moderators overturn most blocks in this band: consider raising block_above to 1.00.');
});

it('shows low-confidence flags in their own lane, with the floor to lower', function (): void {
    times(4, fn () => reviewOf('flagged', 'approved', 'fine', 0.24, ['meta' => ['reason' => 'low_confidence', 'jev_confidence' => 0.24]]));

    livewire(CalibrationTable::class)
        ->assertSeeInOrder(['Low-confidence flags', '0.2 – 0.3', '4', '4', '0', '100%'])
        ->assertSee('Moderators overturn most low-confidence flags in this band: consider lowering uncertain_below to 0.20.');
});

it('mutes the rate and gives no advice below the minimum sample', function (): void {
    times(3, fn () => reviewOf('flagged', 'approved', 'spam', 0.55));

    livewire(CalibrationTable::class)
        ->assertSeeHtml('<span class="fi-jev-moderation-muted">100%</span>')
        ->assertDontSeeHtml('fi-jev-moderation-calibration-flagged')
        ->assertDontSee('consider raising');
});

describe('Jev versions', function (): void {
    beforeEach(function (): void {
        times(4, fn () => reviewOf('flagged', 'approved', 'spam', 0.55, ['model' => 'jev-1.13.0']));
        reviewOf('flagged', 'rejected', 'spam', 0.65, ['model' => 'jev-1.14.0']);
    });

    it('shows the newest version, with a selector for older ones', function (): void {
        livewire(CalibrationTable::class)
            ->assertSeeHtml('wire:model.live="model"')
            ->assertSeeHtml('<option value="jev-1.14.0" selected')
            ->assertSeeInOrder(['Spam', '0.6 – 0.7', '1', '0', '1', '0%'])
            ->assertDontSee('0.5 – 0.6')
            ->assertDontSee('Jev version: jev-1.14.0');
    });

    it('switches to an older version without mixing them', function (): void {
        livewire(CalibrationTable::class)
            ->set('model', 'jev-1.13.0')
            ->assertSet('model', 'jev-1.13.0')
            ->assertSeeInOrder(['Spam', '4', '4', '0', '100%', 'consider raising review_above to 0.60'])
            ->assertDontSee('0.6 ');
    });

    it('falls back to the newest for a version it has not seen', function (): void {
        livewire(CalibrationTable::class)
            ->set('model', 'jev-0.1.0')
            ->assertSet('model', null)
            ->assertSeeInOrder(['Spam', '1', '0', '1', '0%'])
            ->assertDontSee('consider raising');
    });
});

describe('placement and access', function (): void {
    it('sits below the review queue and the volume chart, full width', function (): void {
        livewire(ListReviewQueue::class)->assertSeeLivewire(CalibrationTable::class);

        expect((new CalibrationTable)->getColumnSpan())->toBe('full');
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(fn (): bool|string => 'yes');

        expect(CalibrationTable::canView())->toBeFalse();
        livewire(CalibrationTable::class)->assertForbidden();
    });

    it('keeps its calculation off the public Livewire surface', function (): void {
        expect((new ReflectionMethod(CalibrationTable::class, 'calibration'))->isPublic())->toBeFalse();
    });
});
