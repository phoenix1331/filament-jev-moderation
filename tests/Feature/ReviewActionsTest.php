<?php

declare(strict_types=1);

use Filament\Notifications\Notification;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Phoenix1331\FilamentJevModeration\Data\ReviewOutcome;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\ReviewActions;
use Phoenix1331\FilamentJevModeration\Review\QueueReviewer;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Events\ReviewResolved;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// The stale check locks rows and compares ids: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    JevModerationPlugin::get()->authorize(true);
    $this->moderator = User::factory()->create(['name' => 'Demo Moderator']);
    $this->actingAs($this->moderator);
});

function latestDecisionOf(ModerationDecision $queued): ModerationDecision
{
    return ModerationDecision::query()
        ->where('moderatable_type', $queued->moderatable_type)
        ->where('moderatable_id', $queued->moderatable_id)
        ->latest('id')
        ->firstOrFail();
}

function reviewer(): QueueReviewer
{
    return new QueueReviewer(JevModerationPlugin::get());
}

describe('row actions', function (): void {
    it('records the review through the core, with the moderator and note', function (ReviewAction $action, ModerationStatus $status, string $title): void {
        Event::fake([ReviewResolved::class]);
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)
            ->callTableAction($action->value, $flag, ['note' => '  checked the thread  '])
            ->assertHasNoTableActionErrors()
            ->assertNotified($title)
            ->assertCanNotSeeTableRecords([$flag])
            ->assertCountTableRecords(0);

        $review = latestDecisionOf($flag);

        expect($review->id)->toBeGreaterThan($flag->id)
            ->and($review->status)->toBe($status)
            ->and($review->source)->toBe(ModerationSource::Human)
            ->and($review->note)->toBe('checked the thread')
            ->and($review->reviewed_by_type)->toBe($this->moderator->getMorphClass())
            ->and($review->reviewed_by_id)->toBe($this->moderator->getKey());

        Event::assertDispatched(ReviewResolved::class, fn (ReviewResolved $event): bool => $event->decision->is($review));
    })->with([
        'approve' => [ReviewAction::Approve, ModerationStatus::Approved, 'Approved 1 item'],
        'reject' => [ReviewAction::Reject, ModerationStatus::Rejected, 'Rejected 1 item'],
    ]);

    it('stores no note when it is left blank', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)->callTableAction('approve', $flag, ['note' => '   ']);

        expect(latestDecisionOf($flag)->note)->toBeNull();
    });

    it('limits the note length', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)
            ->callTableAction('reject', $flag, ['note' => str_repeat('a', ReviewActions::NOTE_LENGTH + 1)])
            ->assertHasTableActionErrors(['note' => 'max']);

        expect(latestDecisionOf($flag)->is($flag))->toBeTrue();
    });

    it('reviews content hidden by the core in pre mode', function (): void {
        config(['jev-moderation.mode' => 'pre']);
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)->callTableAction('approve', $flag)->assertNotified('Approved 1 item');

        expect(latestDecisionOf($flag)->status)->toBe(ModerationStatus::Approved);
    });

    it('hides an action the moderator may not take, on that row only', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(
            fn (ReviewAction $action, ?ModerationDecision $decision): bool => $action === ReviewAction::Approve || $decision?->category !== 'hate',
        );

        $hate = decisionFor(forumPost())->flagged('hate', 0.8)->create();
        $spam = decisionFor(forumPost())->flagged('spam', 0.8)->create();

        livewire(ListReviewQueue::class)
            ->assertTableActionVisible('approve', $hate)
            ->assertTableActionHidden('reject', $hate)
            ->assertTableActionVisible('reject', $spam);
    });
});

describe('bulk actions', function (): void {
    it('reviews a selection across model types', function (): void {
        Event::fake([ReviewResolved::class]);

        $post = decisionFor(forumPost())->flagged()->create();
        $message = decisionFor(ChatMessage::factory()->createQuietly())->flagged('spam', 0.7)->create();
        $untouched = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)
            ->callTableBulkAction('reject_selected', [$post, $message], ['note' => 'spam wave'])
            ->assertNotified('Rejected 2 items')
            ->assertCanSeeTableRecords([$untouched])
            ->assertCountTableRecords(1);

        expect(latestDecisionOf($post)->status)->toBe(ModerationStatus::Rejected)
            ->and(latestDecisionOf($message)->status)->toBe(ModerationStatus::Rejected)
            ->and(latestDecisionOf($message)->note)->toBe('spam wave')
            ->and(latestDecisionOf($untouched)->is($untouched))->toBeTrue();

        Event::assertDispatchedTimes(ReviewResolved::class, 2);
    });

    it('skips records the moderator may not review and says how many', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (?ModerationDecision $decision): bool => $decision?->category !== 'hate');

        $hate = decisionFor(forumPost())->flagged('hate', 0.8)->create();
        $spam = decisionFor(forumPost())->flagged('spam', 0.8)->create();

        livewire(ListReviewQueue::class)
            ->callTableBulkAction('approve_selected', [$hate, $spam])
            ->assertNotified('Approved 1 item');

        expect(latestDecisionOf($hate)->is($hate))->toBeTrue()
            ->and(latestDecisionOf($spam)->status)->toBe(ModerationStatus::Approved);
    });

    it('is hidden when the moderator may not take the action at all', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (ReviewAction $action): bool => $action === ReviewAction::Approve);

        livewire(ListReviewQueue::class)
            ->assertTableBulkActionVisible('approve_selected')
            ->assertTableBulkActionHidden('reject_selected');
    });

    it('reports records the core refuses without stopping the rest', function (): void {
        $post = decisionFor(forumPost())->flagged()->create();
        $message = decisionFor(ChatMessage::factory()->createQuietly())->flagged('spam', 0.7)->create();

        $page = livewire(ListReviewQueue::class);
        config(['jev-moderation.policies' => Arr::except((array) config('jev-moderation.policies'), 'chat')]);

        $page->callTableBulkAction('approve_selected', [$post, $message])
            ->assertNotified('Approved 1 item');

        expect(latestDecisionOf($post)->status)->toBe(ModerationStatus::Approved)
            ->and(latestDecisionOf($message)->is($message))->toBeTrue();
    });
});

describe('reviewer', function (): void {
    it('skips a record whose latest decision moved since the page loaded', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();
        $report = decisionFor($post)->reported('still rude')->create();

        $outcome = reviewer()->review([$flag], ReviewAction::Approve, user: $this->moderator);

        expect($outcome)->toEqual(new ReviewOutcome(skipped: 1))
            ->and(latestDecisionOf($flag)->is($report))->toBeTrue();
    });

    it('skips a record reviewed by another moderator first', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();

        expect(reviewer()->review([$flag], ReviewAction::Reject, user: $this->moderator))->toEqual(new ReviewOutcome(resolved: 1))
            ->and(reviewer()->review([$flag], ReviewAction::Approve, user: $this->moderator))->toEqual(new ReviewOutcome(skipped: 1))
            ->and(latestDecisionOf($flag)->status)->toBe(ModerationStatus::Rejected)
            ->and(ModerationDecision::query()->where('source', ModerationSource::Human)->count())->toBe(1);
    });

    it('skips a record that has been deleted', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();
        $post->delete();

        expect(reviewer()->review([$flag], ReviewAction::Approve, user: $this->moderator))->toEqual(new ReviewOutcome(skipped: 1));
    });

    it('skips a record whose model class no longer exists', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create(['moderatable_type' => 'App\\Models\\Removed']);

        expect(Relation::getMorphedModel('App\\Models\\Removed'))->toBeNull()
            ->and(reviewer()->review([$flag], ReviewAction::Approve, user: $this->moderator))->toEqual(new ReviewOutcome(skipped: 1));
    });

    it('collects the core\'s reason for each refused record', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();
        config(['jev-moderation.policies' => Arr::except((array) config('jev-moderation.policies'), 'community')]);

        $outcome = reviewer()->review([$flag], ReviewAction::Reject, user: $this->moderator);

        expect($outcome->resolved)->toBe(0)
            ->and($outcome->failures)->toHaveCount(1)
            ->and($outcome->failures[0])->toStartWith('No moderation policy named [community]');
    });

    it('records no moderator for a user that is not a model', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();
        JevModerationPlugin::get()->authorizeActionsUsing(fn (): bool => true);

        reviewer()->review([$flag], ReviewAction::Approve, user: new GenericUser(['id' => 9]));

        expect(latestDecisionOf($flag)->reviewed_by_id)->toBeNull();
    });
});

describe('notifications', function (): void {
    it('warns with the skipped count when only some were reviewed', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (?ModerationDecision $decision): bool => $decision?->category !== 'hate');

        $hate = decisionFor(forumPost())->flagged('hate', 0.8)->create();
        $spam = decisionFor(forumPost())->flagged('spam', 0.8)->create();

        livewire(ListReviewQueue::class)
            ->callTableBulkAction('reject_selected', [$hate, $spam])
            ->assertNotified(
                Notification::make()
                    ->title('Rejected 1 item')
                    ->body('Skipped 1 item that was already reviewed, has been removed, or is not yours to review.')
                    ->warning(),
            );
    });

    it('fails with the reason when nothing was reviewed', function (): void {
        $flag = decisionFor(forumPost())->flagged()->create();

        $page = livewire(ListReviewQueue::class);
        config(['jev-moderation.policies' => Arr::except((array) config('jev-moderation.policies'), 'community')]);

        $page->callTableAction('approve', $flag)->assertNotified(
            Notification::make()
                ->title('Nothing was approved')
                ->body('1 item could not be recorded: No moderation policy named [community]. Defined: '.implode(', ', JevModeration::policies()).'.')
                ->danger(),
        );
    });
});
