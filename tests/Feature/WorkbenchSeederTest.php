<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

// One test: seeding takes a few seconds, so every expectation shares one run.
it('seeds a complete, deterministic demo', function (): void {
    $this->loadLaravelMigrations();
    $this->artisan('migrate', [
        '--path' => [
            dirname(__DIR__, 2).'/vendor/phoenix1331/laravel-jev-moderation/database/migrations',
            dirname(__DIR__, 2).'/workbench/database/migrations',
        ],
        '--realpath' => true,
    ])->assertSuccessful();

    $this->seed(DatabaseSeeder::class);

    // The demo login, both moderated models and their history.
    expect(User::query()->where('email', 'demo@example.com')->exists())->toBeTrue()
        ->and(ForumPost::query()->withoutModeration()->count())->toBeGreaterThan(100)
        ->and(ChatMessage::query()->withoutModeration()->count())->toBeGreaterThan(100);

    // Every status and source the plugin shows.
    $pairs = ModerationDecision::query()->get()
        ->map(fn (ModerationDecision $decision): string => $decision->source->value.':'.$decision->status->value)
        ->unique();

    expect($pairs->all())->toContain(
        'jev:pending', 'jev:passed', 'jev:flagged', 'jev:blocked', 'jev:failed',
        'report:flagged', 'rescan:passed', 'rescan:flagged', 'human:approved', 'human:rejected',
    );

    // Content still waiting in the review queue.
    $waiting = ModerationDecision::query()
        ->where('status', ModerationStatus::Flagged)
        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('moderation_decisions as newer')
            ->whereColumn('newer.moderatable_type', 'moderation_decisions.moderatable_type')
            ->whereColumn('newer.moderatable_id', 'moderation_decisions.moderatable_id')
            ->whereColumn('newer.id', '>', 'moderation_decisions.id'))
        ->count();

    expect($waiting)->toBeGreaterThanOrEqual(5);

    // Low-confidence flags and two Jev versions for calibration.
    $results = ModerationDecision::query()->whereNotNull('model')->get();

    expect($results->pluck('model')->unique()->sort()->values()->all())->toBe(['jev-1.13.0', 'jev-1.14.0'])
        ->and($results->contains(fn (ModerationDecision $decision): bool => ($decision->meta['reason'] ?? null) === 'low_confidence'))->toBeTrue();

    // The history of deleted content stays.
    $orphans = ModerationDecision::query()
        ->where('moderatable_type', (new ForumPost)->getMorphClass())
        ->whereNotIn('moderatable_id', ForumPost::query()->withoutModeration()->select('id'))
        ->count();

    expect($orphans)->toBeGreaterThan(0);

    // Nothing in the future; reviews only by the demo moderators.
    $moderators = User::query()->whereIn('email', ['demo@example.com', 'priya@example.com'])->pluck('id')->all();

    expect(ModerationDecision::query()->where('created_at', '>', Carbon::now())->count())->toBe(0)
        ->and(ModerationDecision::query()->where('source', ModerationSource::Human)->pluck('reviewed_by_id')->unique()->diff($moderators)->all())->toBe([]);

    // Seeded randomness: the same seed gives the same history.
    $first = ModerationDecision::query()->orderBy('id')->limit(50)->get(['status', 'category', 'confidence'])->toArray();
    $this->artisan('db:wipe')->assertSuccessful();
    $this->loadLaravelMigrations();
    $this->artisan('migrate', [
        '--path' => [
            dirname(__DIR__, 2).'/vendor/phoenix1331/laravel-jev-moderation/database/migrations',
            dirname(__DIR__, 2).'/workbench/database/migrations',
        ],
        '--realpath' => true,
    ])->assertSuccessful();
    $this->seed(DatabaseSeeder::class);

    expect(ModerationDecision::query()->orderBy('id')->limit(50)->get(['status', 'category', 'confidence'])->toArray())->toBe($first);
});
