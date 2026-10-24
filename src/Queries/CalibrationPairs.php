<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use stdClass;

/**
 * Each Jev result a moderator reviewed in the window, with the latest
 * review of it. A review pairs with the latest earlier `jev` or `rescan`
 * result for its record; reports, pending rows and failed checks never
 * pair. Values come back as the driver returns them (often strings), and
 * `meta` as JSON text, so no JSON SQL differs between databases.
 *
 * @internal
 */
final readonly class CalibrationPairs
{
    /** The default window, in days. */
    public const int DAYS = 90;

    public function __construct(
        private JevModerationPlugin $plugin,
        private int $days = self::DAYS,
        private ?string $policy = null,
        private ?string $policyVersion = null,
    ) {}

    /**
     * @return list<stdClass> jev_id, jev_status, category, confidence, model, policy, policy_version, meta, human_status, human_category
     */
    public function get(): array
    {
        $table = (new ModerationDecision)->getTable();
        $base = ModerationDecision::query()->toBase();

        $latestResult = (clone $base)->from("{$table} as result")
            ->selectRaw('max(result.id)')
            ->whereColumn('result.moderatable_type', 'review.moderatable_type')
            ->whereColumn('result.moderatable_id', 'review.moderatable_id')
            ->whereColumn('result.id', '<', 'review.id')
            ->whereIn('result.source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
            ->whereIn('result.status', [ModerationStatus::Passed->value, ModerationStatus::Flagged->value, ModerationStatus::Blocked->value]);

        $reviews = (clone $base)->from("{$table} as review")
            ->select('review.id as human_id')
            ->selectSub($latestResult, 'jev_id')
            ->where('review.source', ModerationSource::Human->value)
            ->whereIn('review.status', [ModerationStatus::Approved->value, ModerationStatus::Rejected->value])
            ->whereIn('review.moderatable_type', array_keys((new ReviewQueueQuery($this->plugin))->modelTypes()))
            ->where('review.created_at', '>=', Carbon::now()->subDays($this->days));

        // A moderator changing their mind must not count twice: the latest review per result wins.
        $latestReview = (clone $base)->fromSub($reviews, 'reviewed')
            ->selectRaw('jev_id, max(human_id) as human_id')
            ->whereNotNull('jev_id')
            ->groupBy('jev_id');

        $rows = (clone $base)->fromSub($latestReview, 'pairs')
            ->join("{$table} as jev", 'jev.id', '=', 'pairs.jev_id')
            ->join("{$table} as human", 'human.id', '=', 'pairs.human_id')
            ->select([
                'jev.id as jev_id', 'jev.status as jev_status', 'jev.category', 'jev.confidence', 'jev.model',
                'jev.policy', 'jev.policy_version', 'jev.meta', 'human.status as human_status', 'human.category as human_category',
            ])
            ->when($this->policy !== null, fn ($query) => $query->where('jev.policy', $this->policy))
            ->when($this->policyVersion !== null, fn ($query) => $query->where('jev.policy_version', $this->policyVersion))
            ->orderByDesc('jev.id')
            ->get();

        return array_values($rows->all());
    }
}
