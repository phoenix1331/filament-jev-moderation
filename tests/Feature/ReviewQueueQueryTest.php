<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

// Correlated subqueries and aliases: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();
});

afterEach(function (): void {
    Relation::morphMap([], merge: false);
});

/**
 * @return list<int>
 */
function queuedIds(?JevModerationPlugin $plugin = null): array
{
    return (new ReviewQueueQuery($plugin ?? JevModerationPlugin::make()))->builder()
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn (mixed $id): int => (int) $id)
        ->all();
}

function decisions(): ModerationDecisionFactory
{
    return ModerationDecisionFactory::new();
}

it('queues a record whose latest decision is a flag', function (): void {
    $flag = decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();

    expect(queuedIds())->toBe([$flag->id]);
});

it('excludes superseded decisions, keeping only the latest flag', function (): void {
    $post = ForumPost::factory()->createQuietly();

    decisions()->forModel($post)->passed()->create();
    decisions()->forModel($post)->flagged()->create();
    $report = decisions()->forModel($post)->reported()->create();

    expect(queuedIds())->toBe([$report->id]);
});

it('drops records once a human has reviewed them', function (string $review): void {
    $post = ForumPost::factory()->createQuietly();
    decisions()->forModel($post)->flagged()->create();
    decisions()->forModel($post)->{$review}()->create();

    expect(queuedIds())->toBe([]);
})->with(['approved', 'rejected']);

it('drops records whose latest decision is anything but a flag', function (string $state): void {
    $post = ForumPost::factory()->createQuietly();
    decisions()->forModel($post)->flagged()->create();
    decisions()->forModel($post)->{$state}()->create();

    expect(queuedIds())->toBe([]);
})->with(['pending', 'failed', 'passed', 'blocked']);

it('queues blocked records only with includeBlocked()', function (): void {
    $blocked = decisions()->forModel(ForumPost::factory()->createQuietly())->blocked()->create();
    $flagged = decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();

    expect(queuedIds())->toBe([$flagged->id])
        ->and(queuedIds(JevModerationPlugin::make()->includeBlocked()))->toBe([$blocked->id, $flagged->id])
        ->and((new ReviewQueueQuery(JevModerationPlugin::make()->includeBlocked()))->statuses())
        ->toBe([ModerationStatus::Flagged, ModerationStatus::Blocked]);
});

it('keeps records of different types with the same id apart', function (): void {
    $post = ForumPost::factory()->createQuietly();
    $message = ChatMessage::factory()->createQuietly();

    expect($message->getKey())->toEqual($post->getKey());

    $postFlag = decisions()->forModel($post)->flagged()->create();
    decisions()->forModel($message)->flagged()->create();
    decisions()->forModel($message)->approved()->create();

    expect(queuedIds())->toBe([$postFlag->id]);
});

it('excludes types whose class is missing or not moderated', function (): void {
    decisions()->flagged()->create(['moderatable_type' => 'App\\Models\\Removed', 'moderatable_id' => 1]);
    decisions()->forModel(User::factory()->create())->flagged()->create();
    $flag = decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();

    $query = new ReviewQueueQuery(JevModerationPlugin::make());

    expect(queuedIds())->toBe([$flag->id])
        ->and($query->modelTypes())->toBe([ForumPost::class => ForumPost::class]);
});

it('excludes decisions for deleted records', function (): void {
    $post = ForumPost::factory()->createQuietly();
    decisions()->forModel($post)->flagged()->create();
    $post->delete();

    expect(queuedIds())->toBe([]);
});

it('limits the queue to models()', function (): void {
    decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();
    $message = decisions()->forModel(ChatMessage::factory()->createQuietly())->flagged()->create();

    $plugin = JevModerationPlugin::make()->models([ChatMessage::class]);

    expect(queuedIds($plugin))->toBe([$message->id])
        ->and((new ReviewQueueQuery($plugin))->modelTypes())->toBe([ChatMessage::class => ChatMessage::class]);
});

it('is empty when no model type has decisions', function (): void {
    ForumPost::factory()->createQuietly();

    expect(queuedIds())->toBe([])
        ->and((new ReviewQueueQuery(JevModerationPlugin::make()))->modelTypes())->toBe([]);
});

it('resolves morph map aliases, whether found in decisions or given to models()', function (): void {
    Relation::morphMap(['forum-post' => ForumPost::class]);

    $flag = decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();

    expect($flag->moderatable_type)->toBe('forum-post')
        ->and(queuedIds())->toBe([$flag->id])
        ->and(queuedIds(JevModerationPlugin::make()->models([ForumPost::class])))->toBe([$flag->id])
        ->and((new ReviewQueueQuery(JevModerationPlugin::make()))->modelTypes())->toBe(['forum-post' => ForumPost::class]);
});

it('finds hidden records in pre mode', function (): void {
    config(['jev-moderation.mode' => 'pre']);

    $flag = decisions()->forModel(ForumPost::factory()->createQuietly())->flagged()->create();

    expect(ForumPost::query()->count())->toBe(0)
        ->and(queuedIds())->toBe([$flag->id])
        ->and(ModerationDecision::query()->count())->toBe(1);
});
