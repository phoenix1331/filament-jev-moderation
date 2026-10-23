<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// The category fallback and the default sort are SQL: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    JevModerationPlugin::get()->authorize(true);
    $this->actingAs(User::factory()->create(['name' => 'Demo Moderator']));
});

function decisionFor(Model $model): ModerationDecisionFactory
{
    return ModerationDecisionFactory::new()->forModel($model);
}

function forumPost(string $title = 'Re: servicing', string $body = 'Only an idiot would pay that much.', string $author = 'Jordan Lee'): ForumPost
{
    return ForumPost::factory()
        ->for(User::factory()->state(['name' => $author]))
        ->createQuietly(['title' => $title, 'body' => $body]);
}

describe('registration', function (): void {
    it('is registered by the plugin with translated labels', function (): void {
        expect(Filament::getCurrentOrDefaultPanel()?->getResources())->toContain(ReviewQueueResource::class)
            ->and(ReviewQueueResource::getNavigationLabel())->toBe('Review queue')
            ->and(ReviewQueueResource::getPluralModelLabel())->toBe('Review queue')
            ->and(ReviewQueueResource::getModelLabel())->toBe('queued item')
            ->and(ReviewQueueResource::getUrl())->toEndWith('/admin/moderation/queue');
    });

    it('takes its navigation group, icon and sort from the plugin', function (): void {
        expect(ReviewQueueResource::getNavigationGroup())->toBe('Moderation')
            ->and(ReviewQueueResource::getNavigationIcon())->toBe('heroicon-o-inbox-stack')
            ->and(ReviewQueueResource::getNavigationSort())->toBe(1);

        JevModerationPlugin::get()->navigationGroup('Trust and safety')->navigationIcon('heroicon-o-shield-check')->navigationSort(40);

        expect(ReviewQueueResource::getNavigationGroup())->toBe('Trust and safety')
            ->and(ReviewQueueResource::getNavigationIcon())->toBe('heroicon-o-shield-check')
            ->and(ReviewQueueResource::getNavigationSort())->toBe(41);
    });

    it('keeps its own navigation on a panel without the plugin', function (): void {
        Filament::setCurrentPanel(Panel::make()->id('host'));

        expect(ReviewQueueResource::getNavigationGroup())->toBeNull()
            ->and(ReviewQueueResource::getNavigationIcon())->toBe('heroicon-o-inbox-stack')
            ->and(ReviewQueueResource::getNavigationSort())->toBe(1);
    });

    it('offers no create, edit or delete', function (): void {
        $decision = decisionFor(forumPost())->flagged()->create();

        expect(ReviewQueueResource::canCreate())->toBeFalse()
            ->and(ReviewQueueResource::canEdit($decision))->toBeFalse()
            ->and(ReviewQueueResource::canDelete($decision))->toBeFalse()
            ->and(ReviewQueueResource::canDeleteAny())->toBeFalse()
            ->and(array_keys(ReviewQueueResource::getPages()))->toBe(['index']);
    });
});

describe('access', function (): void {
    it('is denied unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(fn (): string => 'yes');

        expect(ReviewQueueResource::canAccess())->toBeFalse();
        livewire(ListReviewQueue::class)->assertForbidden();
    });

    it('is allowed once authorize() returns true', function (): void {
        expect(ReviewQueueResource::canAccess())->toBeTrue();
        livewire(ListReviewQueue::class)->assertOk();
    });

    it('is denied by default outside local and allowed in local', function (): void {
        $this->app->forgetInstance(JevModerationPlugin::class);
        Filament::getCurrentOrDefaultPanel()?->plugin(JevModerationPlugin::make());

        expect(ReviewQueueResource::canAccess())->toBeFalse();

        app()->detectEnvironment(fn (): string => 'local');

        expect(ReviewQueueResource::canAccess())->toBeTrue();
    });
});

describe('columns', function (): void {
    it('shows the type, current content, author, category, confidence, source and age', function (): void {
        Carbon::setTestNow('2026-10-23 12:00:00');

        $post = forumPost('Re: servicing', 'Only an idiot would pay that much.');
        $flag = decisionFor($post)->flagged('harassment', 0.72)->create(['created_at' => now()->subHours(3)]);

        livewire(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$flag])
            ->assertTableColumnFormattedStateSet('moderatable_type', 'Forum post', $flag)
            ->assertTableColumnStateSet('excerpt', 'Re: servicing · Only an idiot would pay that much.', $flag)
            ->assertTableColumnStateSet('author', 'Jordan Lee', $flag)
            ->assertTableColumnFormattedStateSet('category', 'Harassment', $flag)
            ->assertTableColumnFormattedStateSet('confidence', '72%', $flag)
            ->assertTableColumnFormattedStateSet('source', 'Jev', $flag)
            ->assertTableColumnFormattedStateSet('created_at', '3 hours ago', $flag);
    });

    it('uses model labels from the plugin', function (): void {
        JevModerationPlugin::get()->modelLabels([ForumPost::class => 'Forum thread']);
        $flag = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableColumnFormattedStateSet('moderatable_type', 'Forum thread', $flag);
    });

    it('shortens long content', function (): void {
        $flag = decisionFor(forumPost('Long', str_repeat('word ', 100)))->flagged()->create();

        $excerpt = livewire(ListReviewQueue::class)->instance()->getTable()->getColumn('excerpt')?->record($flag)->getState();

        expect($excerpt)->toBeString()->toEndWith('...')
            ->and(mb_strlen((string) $excerpt))->toBe(ReviewQueueResource::EXCERPT_LENGTH + 3);
    });

    it('shows content hidden by the core in pre mode', function (): void {
        config(['jev-moderation.mode' => 'pre']);
        $flag = decisionFor(forumPost('Hidden', 'Hidden body'))->flagged()->create();

        expect(ForumPost::query()->count())->toBe(0);

        livewire(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$flag])
            ->assertTableColumnStateSet('excerpt', 'Hidden · Hidden body', $flag);
    });

    it('names authors through each model\'s author relation', function (): void {
        JevModerationPlugin::get()->authorRelations([ChatMessage::class => 'sender']);

        $message = ChatMessage::factory()->for(User::factory()->state(['name' => 'Sam Patel']), 'sender')->createQuietly();
        $flag = decisionFor($message)->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableColumnStateSet('author', 'Sam Patel', $flag);
    });

    it('shows no author when the model has no author relation', function (): void {
        $flag = decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableColumnStateSet('author', null, $flag);
    });

    it('falls back to the author key when the author has no name', function (): void {
        $post = forumPost(author: '');
        $flag = decisionFor($post)->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableColumnStateSet('author', '#'.$post->user_id, $flag);
    });

    it('shows no author once the author is deleted', function (): void {
        $post = forumPost();
        $flag = decisionFor($post)->flagged()->create();
        User::query()->whereKey($post->user_id)->delete();

        livewire(ListReviewQueue::class)->assertTableColumnStateSet('author', null, $flag);
    });

    it('shows no excerpt when every moderated field is empty', function (): void {
        $flag = decisionFor(forumPost(' ', ' '))->flagged()->create();

        livewire(ListReviewQueue::class)->assertTableColumnStateSet('excerpt', null, $flag);
    });

    it('shows a report with the reason and the category Jev gave before it', function (): void {
        $post = forumPost();
        decisionFor($post)->passed('harassment', 0.31)->create();
        $report = decisionFor($post)->reported('rude to a new member')->create();

        expect($report->category)->toBeNull();

        livewire(ListReviewQueue::class)
            ->assertTableColumnFormattedStateSet('category', 'Harassment', $report)
            ->assertTableColumnFormattedStateSet('source', 'Report', $report)
            ->assertTableColumnHasDescription('source', 'rude to a new member', $report)
            ->assertTableColumnStateSet('confidence', null, $report);
    });

    it('shows multi-word categories in sentence case', function (): void {
        $flag = decisionFor(forumPost())->flagged('personal_data', 0.8)->create();

        livewire(ListReviewQueue::class)->assertTableColumnFormattedStateSet('category', 'Personal data', $flag);
    });
});

describe('rows', function (): void {
    it('sorts by confidence, highest first, then longest waiting, with reports last', function (): void {
        $low = decisionFor(forumPost())->flagged('spam', 0.61)->create(['created_at' => now()->subHour()]);
        $report = decisionFor(forumPost())->reported()->create(['created_at' => now()->subDays(2)]);
        $highNewer = decisionFor(forumPost())->flagged('spam', 0.91)->create(['created_at' => now()->subHour()]);
        $highOlder = decisionFor(forumPost())->flagged('spam', 0.91)->create(['created_at' => now()->subDay()]);

        livewire(ListReviewQueue::class)->assertCanSeeTableRecords([$highOlder, $highNewer, $low, $report], inOrder: true);
    });

    it('lists only the queue, never deleted content or reviewed records', function (): void {
        $deleted = forumPost();
        $gone = decisionFor($deleted)->flagged()->create();
        $deleted->delete();

        $reviewed = forumPost();
        $approvedFlag = decisionFor($reviewed)->flagged()->create();
        decisionFor($reviewed)->approved()->create();

        $waiting = decisionFor(forumPost())->flagged()->create();

        livewire(ListReviewQueue::class)
            ->assertCanSeeTableRecords([$waiting])
            ->assertCanNotSeeTableRecords([$gone, $approvedFlag])
            ->assertCountTableRecords(1);
    });

    it('shows an empty state when nothing is waiting', function (): void {
        livewire(ListReviewQueue::class)
            ->assertCountTableRecords(0)
            ->assertSee('Nothing waiting for review');
    });
});

describe('navigation badge', function (): void {
    it('counts the records waiting and hides at zero', function (): void {
        expect(ReviewQueueResource::getNavigationBadge())->toBeNull();

        decisionFor(forumPost())->flagged()->create();
        decisionFor(forumPost())->flagged()->create();
        decisionFor(forumPost())->blocked()->create();

        expect(ReviewQueueResource::getNavigationBadge())->toBe('2')
            ->and(ReviewQueueResource::getNavigationBadgeColor())->toBe('warning');

        JevModerationPlugin::get()->includeBlocked();

        expect(ReviewQueueResource::getNavigationBadge())->toBe('3');
    });

    it('appears on the navigation item', function (): void {
        decisionFor(forumPost())->flagged()->create();

        $item = collect(ReviewQueueResource::getNavigationItems())->first();

        expect($item)->toBeInstanceOf(NavigationItem::class)
            ->and($item?->getBadge())->toBe('1');
    });
});

it('runs the same number of queries for one record as for five', function (): void {
    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        livewire(ListReviewQueue::class);

        return $queries;
    };

    decisionFor(forumPost())->flagged()->create();
    $one = $count();

    foreach (range(1, 4) as $ignored) {
        decisionFor(forumPost())->flagged()->create();
    }

    expect(ModerationDecision::query()->count())->toBe(5)
        ->and($count())->toBe($one);
});
