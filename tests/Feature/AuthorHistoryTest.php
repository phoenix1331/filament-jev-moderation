<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Livewire\Features\SupportTesting\Testable;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\AuthorHistoryQuery;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\AuthorHistory;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ViewDecisionHistory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

// Authors match through EXISTS per type and counts use conditional sums: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    JevModerationPlugin::get()->authorize(true)->authorRelations([ChatMessage::class => 'sender']);
    $this->actingAs(User::factory()->create(['name' => 'Demo Moderator']));
});

function authorPage(User $author): Testable
{
    return authorPageFor($author->getKey(), $author->getMorphClass());
}

function authorPageFor(int|string $key, ?string $type): Testable
{
    return Livewire\Livewire::withQueryParams($type === null ? [] : ['type' => $type])->test(AuthorHistory::class, ['author' => $key]);
}

function postBy(User $author): ForumPost
{
    return ForumPost::factory()->for($author)->createQuietly();
}

function messageBy(User $author): ChatMessage
{
    return ChatMessage::factory()->for($author, 'sender')->createQuietly();
}

describe('route', function (): void {
    it('is linked from the queue\'s author column and the history page', function (): void {
        $author = User::factory()->create(['name' => 'Jordan Lee']);
        $flag = decisionFor(postBy($author))->flagged()->create();
        $url = AuthorHistory::urlFor($author);

        expect($url)->toEndWith('/admin/moderation/queue/authors/'.$author->getKey().'?type='.urlencode($author->getMorphClass()));

        $row = livewire(ListReviewQueue::class)->instance()->getTable()->getRecords()->firstOrFail();

        expect(ReviewQueueResource::authorUrl($row))->toBe($url);

        livewire(ViewDecisionHistory::class, ['record' => $flag->getKey()])->assertSeeHtml('href="'.e($url).'"');

        $this->get($url)->assertOk()->assertSee('Jordan Lee');
    });

    it('has no author link when the record has no author', function (): void {
        JevModerationPlugin::get()->authorRelations([]);
        decisionFor(ChatMessage::factory()->createQuietly())->flagged()->create();

        $row = livewire(ListReviewQueue::class)->instance()->getTable()->getRecords()->firstOrFail();

        expect(ReviewQueueResource::authorUrl($row))->toBeNull();
    });

    it('is titled after the author, with an author breadcrumb', function (): void {
        decisionFor(postBy(User::factory()->create()))->flagged()->create();

        $page = authorPage(User::factory()->create(['name' => 'Sam Patel']))->assertOk();

        expect($page->instance()->getTitle())->toBe('Sam Patel')
            ->and($page->instance()->getBreadcrumb())->toBe('Author')
            ->and($page->instance()->getSubheading())->toBe('Every decision about their content, across every moderated type they author.');
    });

    it('is not found for an unknown author, a type that is not a model, or a type nobody authors', function (): void {
        $author = User::factory()->create();
        $post = postBy($author);

        authorPageFor(999, $author->getMorphClass())->assertNotFound();
        authorPageFor($author->getKey(), 'App\\Nothing')->assertNotFound();
        authorPageFor($author->getKey(), null)->assertNotFound();
        authorPageFor($post->getKey(), $post->getMorphClass())->assertNotFound();
    });

    it('is forbidden unless authorize() returns exactly true', function (): void {
        JevModerationPlugin::get()->authorize(fn (): string => 'yes');

        authorPage(User::factory()->create())->assertForbidden();
        authorPageFor(999, 'App\\Nothing')->assertForbidden();
    });

    it('checks access again on every Livewire request', function (): void {
        $author = User::factory()->create();
        decisionFor(postBy($author))->flagged()->create();

        $page = authorPage($author)->assertOk();
        JevModerationPlugin::get()->authorize(false);

        $page->call('$refresh')->assertForbidden();
    });
});

describe('decisions', function (): void {
    it('lists every decision about the author\'s content across model types, newest first', function (): void {
        $author = User::factory()->create();
        $post = postBy($author);
        $message = messageBy($author);

        $postPassed = decisionFor($post)->passed()->create();
        $postFlagged = decisionFor($post)->flagged()->create();
        $messageBlocked = decisionFor($message)->blocked()->create();
        $postApproved = decisionFor($post)->approved()->create();

        authorPage($author)
            ->assertCanSeeTableRecords([$postApproved, $messageBlocked, $postFlagged, $postPassed], inOrder: true)
            ->assertCountTableRecords(4)
            ->assertTableColumnFormattedStateSet('moderatable_type', 'Chat message', $messageBlocked)
            ->assertTableColumnFormattedStateSet('status', 'Approved', $postApproved)
            ->assertTableColumnFormattedStateSet('source', 'Moderator', $postApproved)
            ->assertTableColumnFormattedStateSet('category', 'Spam', $messageBlocked)
            ->assertTableColumnFormattedStateSet('confidence', '97%', $messageBlocked)
            ->assertTableColumnStateSet('excerpt', $message->body, $messageBlocked);
    });

    it('leaves out other authors\' content', function (): void {
        $author = User::factory()->create();
        $other = User::factory()->create();

        $mine = decisionFor(postBy($author))->flagged()->create();
        $theirs = decisionFor(postBy($other))->flagged()->create();
        $theirMessage = decisionFor(messageBy($other))->flagged()->create();

        authorPage($author)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs, $theirMessage])
            ->assertCountTableRecords(1);
    });

    it('matches each type through its own author relation', function (): void {
        $author = User::factory()->create();
        $other = User::factory()->create();

        $message = ChatMessage::factory()->for($author, 'sender')->createQuietly();
        $byMessage = decisionFor($message)->flagged()->create();
        decisionFor(postBy($other))->flagged()->create();

        expect(array_keys((new AuthorHistoryQuery(JevModerationPlugin::get(), $author))->relations()))
            ->toBe([(new ChatMessage)->getMorphClass(), (new ForumPost)->getMorphClass()]);

        authorPage($other)->assertCanNotSeeTableRecords([$byMessage]);
        authorPage($author)->assertCanSeeTableRecords([$byMessage]);
    });

    it('leaves out types without an author relation and types the plugin does not show', function (): void {
        $author = User::factory()->create();
        $post = decisionFor(postBy($author))->flagged()->create();
        $message = decisionFor(messageBy($author))->flagged()->create();

        JevModerationPlugin::get()->authorRelations([]);
        authorPage($author)->assertCanSeeTableRecords([$post])->assertCanNotSeeTableRecords([$message]);

        JevModerationPlugin::get()->authorRelations([ChatMessage::class => 'sender'])->models([ChatMessage::class]);
        authorPage($author)->assertCanSeeTableRecords([$message])->assertCanNotSeeTableRecords([$post]);
    });

    it('includes content the core hides, but not deleted content', function (): void {
        config(['jev-moderation.mode' => 'pre']);
        $author = User::factory()->create();
        $hidden = decisionFor(postBy($author))->flagged()->create();
        $deletedPost = postBy($author);
        $deleted = decisionFor($deletedPost)->flagged()->create();
        $deletedPost->delete();

        authorPage($author)->assertCanSeeTableRecords([$hidden])->assertCanNotSeeTableRecords([$deleted]);
    });

    it('opens each decision\'s history', function (): void {
        $author = User::factory()->create();
        $flag = decisionFor(postBy($author))->flagged()->create();

        expect(authorPage($author)->instance()->getTable()->getRecordUrl($flag))
            ->toBe(ReviewQueueResource::getUrl('view', ['record' => $flag]));
    });

    it('shows an empty state for an author with no decisions', function (): void {
        $author = User::factory()->create();
        postBy($author);
        decisionFor(postBy(User::factory()->create()))->flagged()->create();

        authorPage($author)
            ->assertOk()
            ->assertCountTableRecords(0)
            ->assertSee('No decisions yet');
    });
});

describe('counts', function (): void {
    it('counts decisions per status over the last 7 and 30 days', function (): void {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $author = User::factory()->create();
        $post = postBy($author);
        $message = messageBy($author);

        decisionFor($post)->flagged()->create(['created_at' => now()->subDays(2)]);
        decisionFor($message)->flagged()->create(['created_at' => now()->subDays(6)]);
        decisionFor($post)->rejected()->create(['created_at' => now()->subDays(7)->subMinute()]);
        decisionFor($post)->passed()->create(['created_at' => now()->subDays(20)]);
        decisionFor($message)->blocked()->create(['created_at' => now()->subDays(29)]);
        decisionFor($post)->blocked()->create(['created_at' => now()->subDays(31)]);
        decisionFor(postBy(User::factory()->create()))->flagged()->create(['created_at' => now()->subDay()]);

        expect((new AuthorHistoryQuery(JevModerationPlugin::get(), $author))->counts())->toBe([
            'pending' => [7 => 0, 30 => 0],
            'failed' => [7 => 0, 30 => 0],
            'passed' => [7 => 0, 30 => 1],
            'flagged' => [7 => 2, 30 => 2],
            'blocked' => [7 => 0, 30 => 1],
            'approved' => [7 => 0, 30 => 0],
            'rejected' => [7 => 0, 30 => 1],
        ]);

        authorPage($author)
            ->assertSeeInOrder(['Recent decisions', 'Status', 'Last 7 days', 'Last 30 days'])
            ->assertSeeInOrder(['Flagged', '2', '2', 'Blocked', '0', '1']);
    });

    it('shows zeros for an author with no decisions', function (): void {
        $author = User::factory()->create();
        decisionFor(postBy(User::factory()->create()))->flagged()->create();

        expect(array_unique(array_merge(...array_values((new AuthorHistoryQuery(JevModerationPlugin::get(), $author))->counts()))))->toBe([0]);
    });

    it('counts human reviews and reports as their own rows', function (): void {
        $author = User::factory()->create();
        $post = postBy($author);
        decisionFor($post)->reported()->create();
        decisionFor($post)->approved()->create(['source' => ModerationSource::Human]);

        $counts = (new AuthorHistoryQuery(JevModerationPlugin::get(), $author))->counts();

        expect($counts['flagged'])->toBe([7 => 1, 30 => 1])
            ->and($counts['approved'])->toBe([7 => 1, 30 => 1])
            ->and(ModerationDecision::query()->count())->toBe(2);
    });
});
