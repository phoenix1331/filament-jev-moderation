<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Phoenix1331\FilamentJevModeration\Data\TimelineEntry;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\DecisionTimeline;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ViewDecisionHistory;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\Resources\ForumPostResource;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->migrateModerationTables();

    JevModerationPlugin::get()->authorize(true);
    $this->actingAs(User::factory()->create(['name' => 'Demo Moderator']));
});

/**
 * A Jev result as the core's Checker stores it.
 *
 * @param  array<string, float>  $probabilities
 * @return array<string, mixed>
 */
function resultMeta(string $reason, array $probabilities, ?float $jevConfidence = null): array
{
    return [
        'reason' => $reason,
        'choice' => array_key_first($probabilities),
        'jev_confidence' => $jevConfidence ?? max($probabilities),
        'probabilities' => $probabilities,
        'driver' => 'jev',
        'latency_ms' => 420,
    ];
}

function history(ModerationDecision $decision): Testable
{
    return livewire(ViewDecisionHistory::class, ['record' => $decision->getKey()]);
}

describe('route', function (): void {
    it('opens from a queue row', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();
        $url = ReviewQueueResource::getUrl('view', ['record' => $flag]);

        expect($url)->toEndWith("/admin/moderation/queue/{$flag->id}")
            ->and(livewire(ListReviewQueue::class)->instance()->getTable()->getRecordUrl($flag))->toBe($url);

        $this->get($url)->assertOk()->assertSee('Forum post #'.$flag->moderatable_id);
    });

    it('is titled after the record, with a history breadcrumb', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();

        $page = history($flag)->assertOk();

        expect($page->instance()->getTitle())->toBe("Forum post #{$post->getKey()}")
            ->and($page->instance()->getBreadcrumb())->toBe('History');
    });

    it('opens on any decision, not only queued ones', function (): void {
        $post = forumPost();
        $passed = decisionFor($post)->passed()->create();
        decisionFor($post)->flagged()->create();

        history($passed)->assertOk()->assertSeeHtml('id="decision-'.$passed->id.'"');
    });

    it('is not found for an unknown decision or a model type the plugin does not show', function (): void {
        $message = decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();
        JevModerationPlugin::get()->models([ForumPost::class]);

        livewire(ViewDecisionHistory::class, ['record' => 999])->assertNotFound();
        history($message)->assertNotFound();
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();
        JevModerationPlugin::get()->authorize(fn (): int => 1);

        expect(ReviewQueueResource::canView($flag))->toBeFalse();
        history($flag)->assertForbidden();
    });
});

describe('content', function (): void {
    it('shows the record\'s moderated fields as they are now, and its author', function (): void {
        $flag = decisionFor(forumPost('Re: servicing', "First line\nSecond line", 'Jordan Lee'))->flagged()->create();

        history($flag)
            ->assertSeeInOrder(['Title', 'Re: servicing', 'Body', 'First line'])
            ->assertSee('Author: Jordan Lee');
    });

    it('shows content the core hides in pre mode', function (): void {
        config(['jev-moderation.mode' => 'pre']);
        $flag = decisionFor(forumPost('Hidden title'))->flagged()->create();

        history($flag)->assertSee('Hidden title');
    });

    it('says when the record has been deleted, and keeps its history', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();
        $post->delete();

        history($flag)
            ->assertSee('This content has been deleted. Its history is kept.')
            ->assertSee('Flagged');
    });

    it('says when every moderated field is empty', function (): void {
        $flag = decisionFor(forumPost(' ', ' '))->flagged()->create();

        history($flag)->assertSee('Every moderated field is empty.');
    });

    it('links to the record in the host resource when there is one', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();

        history($flag)
            ->assertActionVisible('view_record')
            ->assertActionHasUrl('view_record', ForumPostResource::getUrl('view', ['record' => $post]));

        history(decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create())->assertActionHidden('view_record');
    });
});

describe('timeline', function (): void {
    it('lists every check, report, review, rescan and failure, newest first, marking the one opened', function (): void {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $post = forumPost();

        decisionFor($post)->failed('Jev returned HTTP 529: overloaded', retryable: true)->create(['created_at' => now()->subDays(5)]);
        decisionFor($post)->passed('fine', 0.81)->create(['created_at' => now()->subDays(4)]);
        $report = decisionFor($post)->reported('rude to a new member', User::factory()->create(['name' => 'Riley Reporter']))->create(['created_at' => now()->subDays(3)]);
        decisionFor($post)->flagged('harassment', 0.66)->create(['source' => ModerationSource::Rescan, 'created_at' => now()->subDays(2)]);
        decisionFor($post)->rejected(User::factory()->create(['name' => 'Morgan Moderator']), 'confirmed after reading the thread', 'hate')->create(['created_at' => now()->subDay()]);

        history($report)
            ->assertSeeInOrder([
                'Rejected', 'Moderator', '1 day ago', 'Hate', 'Morgan Moderator', 'confirmed after reading the thread',
                'Flagged', 'Rescan', '2 days ago', 'Harassment', '66%',
                'Flagged', 'Report', 'Opened from here', '3 days ago', 'Riley Reporter', 'Reason given', 'rude to a new member',
                'Passed', 'Jev', '4 days ago', 'Fine', '81%',
                'Check failed', 'Jev', '5 days ago', 'Jev returned HTTP 529: overloaded', 'Will be retried',
            ])
            ->assertSeeHtml('id="decision-'.$report->id.'"')
            ->assertSeeHtml('fi-jev-moderation-timeline-item-current');
    });

    it('shows the Jev version and the policy name and version at decision time', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create([
            'model' => 'jev-1.15.2',
            'policy' => 'community',
            'policy_version' => str_repeat('ab', 32),
        ]);

        history($flag)
            ->assertSeeInOrder(['Jev version', 'jev-1.15.2', 'Policy', 'community', 'abababababab'])
            ->assertSeeHtml('title="'.str_repeat('ab', 32).'"');
    });

    it('draws category probabilities highest first, highlighting the deciding one', function (): void {
        $flag = decisionFor(forumPost())->flagged('harassment', 0.62)->create([
            'meta' => resultMeta('review_threshold', ['fine' => 0.2, 'harassment' => 0.62, 'hate' => 0.031, 'spam' => 0.149]),
        ]);

        history($flag)
            ->assertSee('Category probabilities')
            ->assertSee('A category reached its review threshold')
            ->assertSeeInOrder(['Harassment', '62%', 'Fine', '20%', 'Spam', '14.9%', 'Hate', '3.1%'])
            ->assertSeeHtml('style="width: 62%"')
            ->assertSeeHtml('style="width: 3.1%"');

        $bars = (new DecisionTimeline)->for($flag)[0]->probabilities;

        expect(array_map(fn ($bar): array => [$bar->category, $bar->deciding], $bars))->toBe([
            ['harassment', true], ['fine', false], ['spam', false], ['hate', false],
        ]);
    });

    it('shows Jev\'s own confidence for a low-confidence flag', function (): void {
        $flag = decisionFor(forumPost())->flagged('fine', 0.24)->create([
            'meta' => resultMeta('low_confidence', ['fine' => 0.24, 'spam' => 0.22], jevConfidence: 0.24),
        ]);

        history($flag)->assertSeeInOrder(['Jev\'s confidence', '24%', 'Why', 'Jev was not confident enough in its answer']);
    });

    it('keeps a reason it does not recognise as written', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create(['meta' => ['reason' => 'custom_rule']]);

        history($flag)->assertSee('custom_rule');
    });

    it('says when a failed check will not be retried', function (): void {
        $failed = decisionFor(forumPost())->failed('Jev rejected the request: content too long', retryable: false)->create();

        history($failed)->assertSee('Will not be retried');
    });

    it('shows the content checked when the core stored a copy', function (): void {
        $flag = decisionFor(forumPost())->flagged()->withContent('What Jev saw at the time')->create();

        history($flag)->assertSeeInOrder(['Content checked', 'What Jev saw at the time']);
    });

    it('names a reviewer by key when their class is gone or they were deleted', function (): void {
        $post = forumPost();
        $gone = User::factory()->create();
        decisionFor($post)->approved()->create(['reviewed_by_type' => 'App\\Models\\Removed', 'reviewed_by_id' => 7]);
        decisionFor($post)->approved($gone)->create();
        $goneKey = $gone->getKey();
        $gone->delete();

        $people = array_map(fn (TimelineEntry $entry): ?string => $entry->person, (new DecisionTimeline)->for(ModerationDecision::query()->firstOrFail()));

        expect($people)->toBe(["#{$goneKey}", '#7']);
    });

    it('ignores probabilities that are not numbers and keeps the rest between 0 and 1', function (): void {
        $flag = decisionFor(forumPost())->flagged('spam', 0.9)->create([
            'meta' => ['reason' => 'review_threshold', 'probabilities' => ['spam' => 1.2, 'fine' => '0.1', 'hate' => -0.1, 3 => 0.5], 'jev_confidence' => 'high', 'retryable' => 'no'],
        ]);

        $entry = (new DecisionTimeline)->for($flag)[0];

        expect(array_map(fn ($bar): array => [$bar->category, $bar->probability], $entry->probabilities))->toBe([['spam', 1.0], ['hate', 0.0]])
            ->and($entry->jevConfidence)->toBeNull()
            ->and($entry->retryable)->toBeNull();
    });

    it('shows no probabilities when meta has none', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create(['meta' => null]);

        history($flag)->assertDontSee('Category probabilities');
    });

    it('colours each status', function (ModerationStatus $status, string $colour): void {
        $entry = (new DecisionTimeline)->for(decisionFor(forumPost())->create(['status' => $status]))[0];

        expect($entry->statusColor())->toBe($colour);
    })->with([
        [ModerationStatus::Pending, 'gray'],
        [ModerationStatus::Failed, 'gray'],
        [ModerationStatus::Passed, 'success'],
        [ModerationStatus::Flagged, 'warning'],
        [ModerationStatus::Blocked, 'danger'],
        [ModerationStatus::Approved, 'success'],
        [ModerationStatus::Rejected, 'danger'],
    ]);
});
