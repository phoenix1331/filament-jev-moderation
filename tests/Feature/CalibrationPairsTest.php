<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\CalibrationPairs;
use Phoenix1331\FilamentJevModeration\Support\CalibrationCalculator;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;

// Correlated max(), grouping over a derived table and two self-joins: CI runs this file on PostgreSQL, MySQL and MariaDB too.
pest()->group('databases');

beforeEach(function (): void {
    $this->migrateModerationTables();

    Carbon::setTestNow('2026-10-23 12:00:00');
    JevModerationPlugin::get()->authorize(true);
});

/**
 * @return list<stdClass>
 */
function pairs(int $days = CalibrationPairs::DAYS, ?string $policy = null, ?string $policyVersion = null): array
{
    return (new CalibrationPairs(JevModerationPlugin::get(), $days, $policy, $policyVersion))->get();
}

/**
 * @return list<array{int, string, string}> Jev id, Jev status, review status
 */
function pairIds(): array
{
    return array_map(fn (stdClass $pair): array => [(int) $pair->jev_id, (string) $pair->jev_status, (string) $pair->human_status], pairs());
}

it('pairs each review with the latest earlier Jev or rescan result, never a report', function (): void {
    $post = forumPost();
    decisionFor($post)->flagged('spam', 0.55)->create();
    $rescan = decisionFor($post)->flagged('spam', 0.65)->create(['source' => ModerationSource::Rescan]);
    decisionFor($post)->reported('still spam')->create();
    decisionFor($post)->pending()->create();
    decisionFor($post)->failed()->create();
    decisionFor($post)->approved()->create();

    expect(pairIds())->toBe([[$rescan->id, 'flagged', 'approved']]);
});

it('counts only the latest review of each result', function (): void {
    $post = forumPost();
    $flag = decisionFor($post)->flagged()->create();
    decisionFor($post)->approved()->create();
    decisionFor($post)->rejected()->create();

    expect(pairIds())->toBe([[$flag->id, 'flagged', 'rejected']]);
});

it('pairs a passed result reported and then rejected, for the missed count', function (): void {
    $post = forumPost();
    $passed = decisionFor($post)->passed('fine', 0.9)->create();
    decisionFor($post)->reported()->create();
    decisionFor($post)->rejected()->create();

    expect(pairIds())->toBe([[$passed->id, 'passed', 'rejected']]);
});

it('leaves out reviews with nothing from Jev before them, and Jev results after them', function (): void {
    $post = forumPost();
    decisionFor($post)->reported()->create();
    decisionFor($post)->approved()->create();
    decisionFor($post)->flagged()->create();

    expect(pairs())->toBe([]);
});

it('keeps to the window, by when the review was made', function (): void {
    $old = forumPost();
    decisionFor($old)->flagged()->create(['created_at' => now()->subDays(100)]);
    decisionFor($old)->approved()->create(['created_at' => now()->subDays(91)]);

    $recent = forumPost();
    $flag = decisionFor($recent)->flagged()->create(['created_at' => now()->subDays(100)]);
    decisionFor($recent)->rejected()->create(['created_at' => now()->subDays(89)]);

    expect(pairIds())->toBe([[$flag->id, 'flagged', 'rejected']])
        ->and(pairs(days: 30))->toBe([]);
});

it('keeps to the model types the plugin shows', function (): void {
    $post = forumPost();
    $postFlag = decisionFor($post)->flagged()->create();
    decisionFor($post)->approved()->create();

    $message = ChatMessage::factory()->createQuietly();
    decisionFor($message)->flagged()->create();
    decisionFor($message)->approved()->create();

    JevModerationPlugin::get()->models([ForumPost::class]);

    expect(pairIds())->toBe([[$postFlag->id, 'flagged', 'approved']]);
});

it('filters by policy and policy version', function (): void {
    foreach ([['community', 'v1'], ['community', 'v2'], ['chat', 'v1']] as [$policy, $version]) {
        $post = forumPost();
        decisionFor($post)->flagged()->create(['policy' => $policy, 'policy_version' => $version]);
        decisionFor($post)->approved()->create(['policy' => $policy, 'policy_version' => $version]);
    }

    expect(pairs())->toHaveCount(3)
        ->and(pairs(policy: 'community'))->toHaveCount(2)
        ->and(pairs(policy: 'community', policyVersion: 'v2'))->toHaveCount(1)
        ->and(pairs(policyVersion: 'v1'))->toHaveCount(2);
});

it('returns the newest result first, so the newest Jev version leads', function (): void {
    $first = forumPost();
    decisionFor($first)->flagged()->create(['model' => 'jev-1.14.0']);
    decisionFor($first)->approved()->create();

    $second = forumPost();
    decisionFor($second)->flagged()->create(['model' => 'jev-1.15.0']);
    decisionFor($second)->approved()->create();

    expect(array_column(pairs(), 'model'))->toBe(['jev-1.15.0', 'jev-1.14.0']);
});

it('calculates exact bands from the database, whatever types the driver returns', function (): void {
    $review = function (string $jev, string $human, string $category, float $confidence, array $meta = []): void {
        $post = forumPost();
        decisionFor($post)->create([
            'status' => $jev, 'source' => ModerationSource::Jev, 'category' => $category, 'confidence' => $confidence,
            'meta' => $meta === [] ? ['reason' => 'review_threshold'] : $meta,
        ]);
        decisionFor($post)->{$human}()->create();
    };

    foreach (range(1, 4) as $ignored) {
        $review('flagged', 'approved', 'harassment', 0.42);
    }

    foreach (range(1, 6) as $ignored) {
        $review('flagged', 'rejected', 'harassment', 0.48);
    }

    $review('flagged', 'rejected', 'harassment', 0.5);
    $review('passed', 'rejected', 'harassment', 0.31);
    $review('flagged', 'approved', 'fine', 0.21, ['reason' => 'low_confidence', 'jev_confidence' => 0.21]);

    $calibration = (new CalibrationCalculator(0.3, 10))->calculate(pairs());

    expect(array_map(fn ($band): array => [$band->category, $band->lower, $band->overturned, $band->upheld, $band->missed, $band->advice?->value, $band->threshold], $calibration->bands))->toBe([
        ['harassment', 0.3, 0, 0, 1, null, null],
        ['harassment', 0.4, 4, 6, 0, 'raise_review_above', 0.5],
        ['harassment', 0.5, 0, 1, 0, null, null],
        [null, 0.2, 1, 0, 0, null, null],
    ])
        ->and($calibration->model)->toBe('jev-1.14.0')
        ->and(ModerationDecision::query()->count())->toBe(26);
});
