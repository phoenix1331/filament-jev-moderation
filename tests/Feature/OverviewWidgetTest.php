<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Phoenix1331\FilamentJevModeration\Data\Agreement;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\OverviewStats;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// Review time and agreement pair rows with correlated subqueries: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    Carbon::setTestNow('2026-10-23 12:00:00');
    JevModerationPlugin::get()->authorize(true);
    $this->actingAs(User::factory()->create());
});

function stats(): OverviewStats
{
    return new OverviewStats(JevModerationPlugin::get());
}

/**
 * Jev's result, then a moderator's review of it.
 */
function reviewed(ModerationStatus $jev, ModerationStatus $moderator, string $at = '-1 hour'): void
{
    $post = forumPost();
    decisionFor($post)->create(['status' => $jev, 'source' => ModerationSource::Jev, 'created_at' => Carbon::now()->modify('-2 hours')]);
    decisionFor($post)->create(['status' => $moderator, 'source' => ModerationSource::Human, 'category' => null, 'confidence' => null, 'created_at' => Carbon::now()->modify($at)]);
}

describe('waiting', function (): void {
    it('counts the queue and names the oldest wait', function (): void {
        decisionFor(forumPost())->flagged()->create(['created_at' => now()->subHours(5)]);
        decisionFor(forumPost())->flagged()->create(['created_at' => now()->subHour()]);
        decisionFor(forumPost())->blocked()->create(['created_at' => now()->subDay()]);

        expect(stats()->waiting())->toBe(2)
            ->and(stats()->oldestWaiting()?->toDateTimeString())->toBe('2026-10-23 07:00:00');

        livewire(ModerationOverview::class)->assertSeeInOrder(['Waiting for review', '2', 'Oldest waiting 5 hours']);
    });

    it('says when the queue is clear', function (): void {
        expect(stats()->oldestWaiting())->toBeNull();

        livewire(ModerationOverview::class)->assertSeeInOrder(['Waiting for review', '0', 'The queue is clear']);
    });
});

describe('blocked today', function (): void {
    it('counts Jev and rescan blocks since midnight, not reports or earlier blocks', function (): void {
        decisionFor(forumPost())->blocked()->create(['created_at' => now()->startOfDay()]);
        decisionFor(forumPost())->blocked()->create(['source' => ModerationSource::Rescan, 'created_at' => now()->subHour()]);
        decisionFor(forumPost())->blocked()->create(['created_at' => now()->startOfDay()->subSecond()]);
        decisionFor(forumPost())->create(['status' => ModerationStatus::Blocked, 'source' => ModerationSource::Report, 'created_at' => now()]);
        decisionFor(forumPost())->flagged()->create(['created_at' => now()]);

        expect(stats()->blockedToday())->toBe(2);

        livewire(ModerationOverview::class)->assertSeeInOrder(['Blocked today', '2', 'Removed by Jev since midnight']);
    });
});

describe('median time to review', function (): void {
    it('pairs each review with the latest earlier flag or block and takes the median', function (): void {
        $quick = forumPost();
        decisionFor($quick)->reported()->create(['created_at' => now()->subMinutes(90)]);
        decisionFor($quick)->approved()->create(['created_at' => now()->subHour()]);

        $middle = forumPost();
        decisionFor($middle)->flagged()->create(['created_at' => now()->subHours(9)]);
        decisionFor($middle)->flagged()->create(['source' => ModerationSource::Rescan, 'created_at' => now()->subHours(3)]);
        decisionFor($middle)->rejected()->create(['created_at' => now()->subHour()]);

        $slow = forumPost();
        decisionFor($slow)->blocked()->create(['created_at' => now()->subHours(10)]);
        decisionFor($slow)->approved()->create(['created_at' => now()->subHours(4)]);

        expect(stats()->reviewTime())->toBe(['seconds' => 7200, 'reviews' => 3]);

        livewire(ModerationOverview::class)->assertSeeInOrder(['Median time to review', '2h', 'From 3 reviews in the last 7 days']);
    });

    it('averages the middle two of an even count', function (): void {
        foreach ([1, 2] as $hours) {
            $post = forumPost();
            decisionFor($post)->flagged()->create(['created_at' => now()->subHours($hours + 1)]);
            decisionFor($post)->approved()->create(['created_at' => now()->subHour()]);
        }

        expect(stats()->reviewTime())->toBe(['seconds' => 5400, 'reviews' => 2]);

        livewire(ModerationOverview::class)->assertSee('1h 30m');
    });

    it('leaves out reviews outside the window and reviews of content never queued', function (): void {
        $old = forumPost();
        decisionFor($old)->flagged()->create(['created_at' => now()->subDays(9)]);
        decisionFor($old)->approved()->create(['created_at' => now()->subDays(8)]);

        $neverQueued = forumPost();
        decisionFor($neverQueued)->passed()->create(['created_at' => now()->subHours(2)]);
        decisionFor($neverQueued)->rejected()->create(['created_at' => now()->subHour()]);

        expect(stats()->reviewTime())->toBe(['seconds' => null, 'reviews' => 0]);

        livewire(ModerationOverview::class)->assertSeeInOrder(['Median time to review', 'None yet', 'No reviews in the last 7 days']);
    });
});

describe('override rate', function (): void {
    it('counts reviews exactly as jev-moderation:stats does', function (): void {
        reviewed(ModerationStatus::Flagged, ModerationStatus::Rejected);
        reviewed(ModerationStatus::Blocked, ModerationStatus::Rejected);
        reviewed(ModerationStatus::Flagged, ModerationStatus::Rejected);
        reviewed(ModerationStatus::Flagged, ModerationStatus::Approved);
        reviewed(ModerationStatus::Passed, ModerationStatus::Rejected);
        reviewed(ModerationStatus::Passed, ModerationStatus::Approved);
        reviewed(ModerationStatus::Flagged, ModerationStatus::Approved, at: '-8 days');

        $unchecked = forumPost();
        decisionFor($unchecked)->reported()->create(['created_at' => now()->subHours(2)]);
        decisionFor($unchecked)->approved()->create(['created_at' => now()->subHour()]);

        $agreement = stats()->agreement();

        expect($agreement)->toEqual(new Agreement(upheld: 3, overturned: 1, missed: 1, dismissed: 1, unchecked: 1))
            ->and($agreement->overrideRate())->toBe(0.25);

        Artisan::call('jev-moderation:stats');
        $cli = Artisan::output();

        expect($cli)->toMatch('/Moderators upheld Jev \(rejected\)\s*\.*\s*3/')
            ->toMatch('/Moderators overturned Jev \(approved\)\s*\.*\s*1/')
            ->toMatch('/Upheld share\s*\.*\s*75%/')
            ->toMatch('/Missed by Jev \(passed, then rejected\)\s*\.*\s*1/')
            ->toMatch('/Reports dismissed \(passed, then approved\)\s*\.*\s*1/')
            ->toMatch('/Not checked by Jev\s*\.*\s*1/');

        livewire(ModerationOverview::class)->assertSeeInOrder(['Override rate', '25%', 'Moderators overturned 1 of 4 flags and blocks in the last 7 days']);
    });

    it('has no rate before any flag or block was checked', function (): void {
        reviewed(ModerationStatus::Passed, ModerationStatus::Rejected);

        expect(stats()->agreement()->overrideRate())->toBeNull();

        livewire(ModerationOverview::class)->assertSeeInOrder(['Override rate', 'None yet']);
    });

    // The override rate is the only stat that can be red.
    it('turns red above the warning rate once the minimum sample is checked', function (int $overturned, int $upheld, bool $red): void {
        JevModerationPlugin::get()->overrideWarningRate(0.3)->minimumSample(4);

        foreach (range(1, $overturned) as $ignored) {
            reviewed(ModerationStatus::Flagged, ModerationStatus::Approved);
        }

        foreach (range(1, $upheld) as $ignored) {
            reviewed(ModerationStatus::Flagged, ModerationStatus::Rejected);
        }

        $widget = livewire(ModerationOverview::class);

        $red ? $widget->assertSeeHtml('fi-color-danger') : $widget->assertDontSeeHtml('fi-color-danger');
    })->with([
        'above the rate, enough reviews' => [2, 2, true],
        'above the rate, too few reviews' => [2, 1, false],
        'at the rate' => [3, 7, false],
    ]);
});

describe('placement and access', function (): void {
    it('sits above the review queue', function (): void {
        livewire(ListReviewQueue::class)->assertSeeLivewire(ModerationOverview::class);
    });

    it('counts only the model types the plugin shows', function (): void {
        decisionFor(forumPost())->flagged()->create();
        decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();
        decisionFor(ChatMessage::factory()->createQuietly())->blocked()->create();

        JevModerationPlugin::get()->models([ForumPost::class]);

        expect(stats()->waiting())->toBe(1)
            ->and(stats()->blockedToday())->toBe(0);
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(fn (): int => 1);

        expect(ModerationOverview::canView())->toBeFalse();
        livewire(ModerationOverview::class)->assertForbidden();
    });

    it('works on a host dashboard without the plugin, with default options, in local only', function (): void {
        decisionFor(forumPost())->flagged()->create();
        Filament::setCurrentPanel(Panel::make()->id('host'));

        expect(ModerationOverview::canView())->toBeFalse();

        app()->detectEnvironment(fn (): string => 'local');

        expect(ModerationOverview::canView())->toBeTrue();
        livewire(ModerationOverview::class)->assertSeeInOrder(['Waiting for review', '1']);
    });

    it('polls once a minute', function (): void {
        livewire(ModerationOverview::class)->assertSeeHtml('wire:poll.60s');
    });
});
