<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use LogicException;
use Phoenix1331\JevModeration\Data\Policy;
use Phoenix1331\JevModeration\Database\Factories\ModerationDecisionFactory;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;
use Workbench\App\Support\DemoRandom;

/**
 * Demo data for the workbench panel and screenshots: 90 days of forum posts
 * and chat messages with the decision history the core would have written.
 *
 * Content is created quietly and its history written with the core's
 * factory, so confidences and timestamps are exact and no check runs.
 * Moderators overturn low-confidence flags far more often than confident
 * ones, so calibration has a story to tell, and Jev's answering version
 * changes halfway through. Randomness comes from DemoRandom, seeded, so
 * screenshots only change when the UI does.
 */
class DatabaseSeeder extends Seeder
{
    private const int DAYS = 90;

    /** Content older than this was answered by the previous Jev version. */
    private const int VERSION_SWITCH = 45;

    /** @var list<string> */
    private const array AUTHORS = ['Alex Morgan', 'Jordan Lee', 'Sam Taylor', 'Riley Chen', 'Casey Patel', 'Morgan Davies', 'Jamie Okafor', 'Robin Novak'];

    /** Writes most of the harmful content, so author history has a story. */
    private const string REPEAT_OFFENDER = 'Jordan Lee';

    /** @var list<string> */
    private const array APPROVE_NOTES = ['banter, not abuse', 'false positive', 'criticism of the product, not the person', 'fine in context'];

    /** @var list<string> */
    private const array REJECT_NOTES = ['confirmed', 'repeat offender, warned', 'removed and warned', 'clear breach'];

    /** @var list<string> */
    private const array REPORT_REASONS = ['abusive', 'spam', 'this is not ok', 'personal details', 'rude'];

    /** @var list<User> */
    private array $moderators = [];

    /** @var array<string, User> */
    private array $authors = [];

    public function run(): void
    {
        DemoRandom::seed(1331);

        $this->moderators = [
            User::factory()->create(['name' => 'Demo User', 'email' => 'demo@example.com']),
            User::factory()->create(['name' => 'Priya Shah', 'email' => 'priya@example.com']),
        ];

        foreach (self::AUTHORS as $name) {
            $this->authors[$name] = User::factory()->create([
                'name' => $name,
                'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            ]);
        }

        for ($day = self::DAYS; $day >= 0; $day--) {
            for ($i = DemoRandom::int(3, 6); $i > 0; $i--) {
                $this->item(JevModeration::policy('community'), $day, fn (string $about, User $author, Carbon $at): Model => ForumPost::factory()
                    ->about($about)
                    ->createQuietly(['user_id' => $author->id, 'created_at' => $at, 'updated_at' => $at]));
            }

            for ($i = DemoRandom::int(3, 6); $i > 0; $i--) {
                $this->item(JevModeration::policy('chat'), $day, fn (string $about, User $author, Carbon $at): Model => ChatMessage::factory()
                    ->about($about)
                    ->createQuietly(['sender_id' => $author->id, 'created_at' => $at, 'updated_at' => $at]));
            }
        }

        $this->deletedContent();
    }

    /**
     * One piece of content and its history, by weighted scenario.
     *
     * @param  Closure(string, User, Carbon): Model  $create
     */
    private function item(Policy $policy, int $day, Closure $create): void
    {
        $at = $this->moment($day);
        $roll = DemoRandom::int(1, 100);

        match (true) {
            $roll <= 52 => $this->passes($policy, $create('fine', $this->author(false), $at), $at, $day),
            $roll <= 60 => $this->passesThenReported($policy, $create, $at, $day),
            $roll <= 82 => $this->flagged($policy, $create, $at, $day),
            $roll <= 90 => $this->blocked($policy, $create, $at, $day),
            $roll <= 94 && $policy->uncertainBelow !== null => $this->uncertain($policy, $create('uncertain', $this->author(false), $at), $at, $day),
            $roll <= 97 => $this->failedThenRescanned($policy, $create('fine', $this->author(false), $at), $at, $day),
            default => $this->rescannedAfterPolicyChange($policy, $create, $at, $day),
        };
    }

    private function passes(Policy $policy, Model $model, Carbon $at, int $day): void
    {
        $this->jev($policy, $model, $at, $day, ModerationStatus::Passed, $policy->safeChoice, $this->between(0.90, 1.0));
    }

    /**
     * Jev passed it, a user reported it: harmful content is removed (missed
     * by Jev), borderline content is approved (report dismissed).
     *
     * @param  Closure(string, User, Carbon): Model  $create
     */
    private function passesThenReported(Policy $policy, Closure $create, Carbon $at, int $day): void
    {
        $harmful = DemoRandom::int(1, 10) <= 6;
        $category = $harmful ? $this->category($policy) : null;
        $model = $create($category ?? 'uncertain', $this->author($harmful), $at);

        $this->jev($policy, $model, $at, $day, ModerationStatus::Passed, $policy->safeChoice, $this->between(0.55, 0.85), hidden: $category);

        $reported = $at->copy()->addMinutes(DemoRandom::int(30, 2000));

        if ($reported->isFuture()) {
            return;
        }

        $reporter = $this->reporter();

        $this->row($policy, $model, $reported, [
            'status' => ModerationStatus::Flagged,
            'source' => ModerationSource::Report,
            'reviewed_by_type' => $reporter->getMorphClass(),
            'reviewed_by_id' => $reporter->getKey(),
            'note' => DemoRandom::pick(self::REPORT_REASONS),
        ]);

        $this->review($policy, $model, $reported, $day, approve: ! $harmful);
    }

    /**
     * Over a review threshold. Moderators overturn flags near the
     * threshold far more often than confident ones.
     *
     * @param  Closure(string, User, Carbon): Model  $create
     */
    private function flagged(Policy $policy, Closure $create, Carbon $at, int $day): void
    {
        $category = $this->category($policy);
        $rule = $policy->rules[$category];
        $top = $rule->blockAbove ?? 1.0;
        $probability = min(round($rule->reviewAbove + ($top - $rule->reviewAbove) * (DemoRandom::int(0, 100) / 100) ** 1.5, 2), $top - 0.01);

        $overturn = max(0.05, min(0.85, 0.85 - 3.0 * ($probability - $rule->reviewAbove)));
        $approve = DemoRandom::int(1, 100) <= (int) round($overturn * 100);

        $model = $create($approve ? 'uncertain' : $category, $this->author(! $approve), $at);
        $this->jev($policy, $model, $at, $day, ModerationStatus::Flagged, $category, $probability, reason: 'review_threshold');

        $reviewed = $this->review($policy, $model, $at, $day, $approve);

        // Now and then a second moderator reverses the first.
        if ($reviewed !== null && $approve && DemoRandom::int(1, 12) === 1) {
            $this->human($policy, $model, $reviewed->copy()->addHours(2), false, $this->moderators[1]);
        }
    }

    /**
     * Over a block threshold. Some blocks are appealed and reviewed.
     *
     * @param  Closure(string, User, Carbon): Model  $create
     */
    private function blocked(Policy $policy, Closure $create, Carbon $at, int $day): void
    {
        $blocking = array_keys(array_filter($policy->rules, fn ($rule): bool => $rule->blockAbove !== null));

        if ($blocking === []) {
            throw new LogicException("Policy [{$policy->name}] has no block thresholds to seed blocked content with.");
        }

        $category = DemoRandom::pick($blocking);
        $floor = $policy->rules[$category]->blockAbove ?? 1.0;

        $model = $create($category, $this->author(true), $at);
        $this->jev($policy, $model, $at, $day, ModerationStatus::Blocked, $category, $this->between($floor, 1.0), reason: 'block_threshold');

        if (DemoRandom::int(1, 5) === 1) {
            $this->review($policy, $model, $at, $day, approve: DemoRandom::int(1, 4) === 1);
        }
    }

    /**
     * No threshold fired but Jev was unsure: flagged on the policy floor,
     * with the safe choice as its category.
     */
    private function uncertain(Policy $policy, Model $model, Carbon $at, int $day): void
    {
        $this->jev($policy, $model, $at, $day, ModerationStatus::Flagged, $policy->safeChoice, $this->between(0.36, 0.41), reason: 'low_confidence');
        $this->review($policy, $model, $at, $day, approve: DemoRandom::int(1, 100) <= 65);
    }

    private function failedThenRescanned(Policy $policy, Model $model, Carbon $at, int $day): void
    {
        $this->row($policy, $model, $at, ['status' => ModerationStatus::Pending]);
        $this->row($policy, $model, $at->copy()->addMinutes(9), [
            'status' => ModerationStatus::Failed,
            'meta' => ['reason' => 'Jev returned HTTP 529: overloaded', 'retryable' => true],
        ]);

        $rescanned = $at->copy()->addDay();

        if (! $rescanned->isFuture()) {
            $this->jev($policy, $model, $rescanned, $day, ModerationStatus::Passed, $policy->safeChoice, $this->between(0.92, 1.0), source: ModerationSource::Rescan);
        }
    }

    /**
     * Passed, then a policy change and `rescan` flagged it.
     *
     * @param  Closure(string, User, Carbon): Model  $create
     */
    private function rescannedAfterPolicyChange(Policy $policy, Closure $create, Carbon $at, int $day): void
    {
        $category = $this->category($policy);
        $rule = $policy->rules[$category];
        $model = $create($category, $this->author(true), $at);

        $this->jev($policy, $model, $at, $day, ModerationStatus::Passed, $policy->safeChoice, $this->between(0.60, 0.80), hidden: $category);

        $rescanned = $at->copy()->addDays(2);

        if ($rescanned->isFuture()) {
            return;
        }

        $probability = min(round($rule->reviewAbove + 0.05, 2), 0.99);
        $this->jev($policy, $model, $rescanned, $day, ModerationStatus::Flagged, $category, $probability, reason: 'review_threshold', source: ModerationSource::Rescan);
        $this->review($policy, $model, $rescanned, $day, approve: false);
    }

    /**
     * A flagged post whose author deleted it: its decisions stay, the
     * plugin must leave it out of the queue.
     */
    private function deletedContent(): void
    {
        $policy = JevModeration::policy('community');
        $at = $this->moment(1);
        $post = ForumPost::factory()->about('harassment')->createQuietly(['user_id' => $this->authors[self::REPEAT_OFFENDER]->id, 'created_at' => $at, 'updated_at' => $at]);

        $this->jev($policy, $post, $at, 1, ModerationStatus::Flagged, 'harassment', 0.62, reason: 'review_threshold');
        $post->deleteQuietly();
    }

    /**
     * A pending row, then Jev's result a few seconds later, with the meta
     * the core's Checker writes. `hidden` is a harmful category Jev scored
     * below its threshold on content it passed.
     */
    private function jev(
        Policy $policy,
        Model $model,
        Carbon $at,
        int $day,
        ModerationStatus $status,
        string $category,
        float $probability,
        string $reason = 'clear',
        ModerationSource $source = ModerationSource::Jev,
        ?string $hidden = null,
    ): void {
        $this->row($policy, $model, $at, ['status' => ModerationStatus::Pending, 'source' => $source]);

        $probabilities = $this->probabilities($policy, $category, $probability, $hidden);
        $choice = (string) array_search(max($probabilities), $probabilities, true);
        $count = count($probabilities);

        $this->row($policy, $model, $at->copy()->addSeconds(DemoRandom::int(1, 4)), [
            'status' => $status,
            'source' => $source,
            'model' => $day > self::VERSION_SWITCH ? 'jev-1.13.0' : 'jev-1.14.0',
            'category' => $category,
            'confidence' => $probability,
            'meta' => [
                'reason' => $reason,
                'choice' => $choice,
                'jev_confidence' => round((max($probabilities) - 1 / $count) / (1 - 1 / $count), 2),
                'probabilities' => $probabilities,
                'driver' => 'jev',
                'latency_ms' => DemoRandom::int(280, 900),
                'input_tokens' => DemoRandom::int(470, 620),
                'request_id' => 'req_'.str_pad((string) DemoRandom::int(0, 999_999_999), 9, '0', STR_PAD_LEFT),
                'truncated' => false,
            ],
        ]);
    }

    /**
     * A moderator's decision some time after the item entered the queue,
     * unless that time has not come yet (it is still waiting). Most of the
     * last three days is still unreviewed, so the queue is never empty.
     */
    private function review(Policy $policy, Model $model, Carbon $queued, int $day, bool $approve): ?Carbon
    {
        $reviewed = $queued->copy()->addMinutes(DemoRandom::int(4, 1800));

        if ($reviewed->isFuture() || ($day <= 3 && DemoRandom::int(1, 10) <= 7)) {
            return null;
        }

        $this->human($policy, $model, $reviewed, $approve, $this->moderators[DemoRandom::int(0, 1)]);

        return $reviewed;
    }

    private function human(Policy $policy, Model $model, Carbon $at, bool $approve, User $moderator): void
    {
        $this->row($policy, $model, $at, [
            'status' => $approve ? ModerationStatus::Approved : ModerationStatus::Rejected,
            'source' => ModerationSource::Human,
            'reviewed_by_type' => $moderator->getMorphClass(),
            'reviewed_by_id' => $moderator->getKey(),
            'note' => DemoRandom::pick($approve ? self::APPROVE_NOTES : self::REJECT_NOTES),
        ]);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function row(Policy $policy, Model $model, Carbon $at, array $state): void
    {
        $key = $model->getKey();

        if (! is_int($key) && ! is_string($key)) {
            throw new LogicException('Seeded content needs an integer or string key, '.get_debug_type($key).' given.');
        }

        ModerationDecisionFactory::new()->forModel($model)->create([
            'source' => ModerationSource::Jev,
            'policy' => $policy->name,
            'policy_version' => $policy->version,
            'model' => null,
            'category' => null,
            'confidence' => null,
            'content_hash' => hash('sha256', $model->getMorphClass().':'.$key),
            'meta' => null,
            ...$state,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }

    /**
     * A distribution over the policy's choices with `category` at
     * `probability`, rounded to two decimals as Jev does. Other harmful
     * categories stay below their review thresholds.
     *
     * @return non-empty-array<string, float>
     */
    private function probabilities(Policy $policy, string $category, float $probability, ?string $hidden): array
    {
        $rest = 1 - $probability;
        $others = array_values(array_diff(array_keys($policy->choices), [$category, $policy->safeChoice]));
        $probabilities = array_fill_keys(array_keys($policy->choices), 0.0);
        $probabilities[$category] = $probability;

        if ($hidden !== null && $hidden !== $category) {
            $probabilities[$hidden] = round(min($rest * 0.8, $policy->rules[$hidden]->reviewAbove - 0.02), 2);
            $rest -= $probabilities[$hidden];
            $others = array_values(array_diff($others, [$hidden]));
        }

        if ($category !== $policy->safeChoice) {
            $probabilities[$policy->safeChoice] = round($rest * 0.8, 2);
            $rest -= $probabilities[$policy->safeChoice];
        }

        foreach ($others as $other) {
            $probabilities[$other] = round($rest / count($others), 2);
        }

        return $probabilities;
    }

    private function category(Policy $policy): string
    {
        $categories = array_keys($policy->rules);

        if ($categories === []) {
            throw new LogicException("Policy [{$policy->name}] has no category rules to seed flagged content with.");
        }

        return DemoRandom::pick($categories);
    }

    private function author(bool $harmful): User
    {
        if ($harmful && DemoRandom::int(1, 2) === 1) {
            return $this->authors[self::REPEAT_OFFENDER];
        }

        return $this->authors[DemoRandom::pick(self::AUTHORS)];
    }

    private function reporter(): User
    {
        return $this->authors[DemoRandom::pick(self::AUTHORS)];
    }

    private function between(float $low, float $high): float
    {
        return round($low + ($high - $low) * DemoRandom::int(0, 100) / 100, 2);
    }

    private function moment(int $day): Carbon
    {
        $at = Carbon::today()->subDays($day)->setTime(DemoRandom::int(7, 22), DemoRandom::int(0, 59), DemoRandom::int(0, 59));

        return $at->isFuture() ? Carbon::now()->subMinutes(DemoRandom::int(5, 120)) : $at;
    }
}
