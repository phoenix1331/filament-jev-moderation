<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\Data\Agreement;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * The figures behind the overview widget, for the model types the plugin
 * shows. Agreement uses the core `jev-moderation:stats` definition, over
 * its default window, so the panel and the CLI agree.
 *
 * @internal
 */
final readonly class OverviewStats
{
    /** The window for review time and agreement, as `jev-moderation:stats` defaults to. */
    public const int DAYS = 7;

    private ReviewQueueQuery $queue;

    public function __construct(JevModerationPlugin $plugin)
    {
        $this->queue = new ReviewQueueQuery($plugin);
    }

    public function waiting(): int
    {
        return $this->queue->builder()->count();
    }

    public function oldestWaiting(): ?Carbon
    {
        $oldest = $this->queue->builder()->min((new ModerationDecision)->qualifyColumn('created_at'));

        return is_string($oldest) ? Carbon::parse($oldest) : null;
    }

    /**
     * Automatic blocks since midnight (app timezone): Jev and rescan rows,
     * not reports on content that was already blocked.
     */
    public function blockedToday(): int
    {
        return $this->decisions()
            ->where('status', ModerationStatus::Blocked->value)
            ->whereIn('source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
            ->where('created_at', '>=', Carbon::today())
            ->count();
    }

    /**
     * Median seconds from a record being queued to a moderator's review, for
     * reviews in the window. Each review is paired with the latest earlier
     * flag or block (any source) in SQL; timestamp arithmetic differs per
     * database, so the difference is taken here.
     *
     * @return array{seconds: int|null, reviews: int}
     */
    public function reviewTime(): array
    {
        $table = (new ModerationDecision)->getTable();

        $queuedAt = ModerationDecision::query()->toBase()
            ->from("{$table} as queued")
            ->select('queued.created_at')
            ->whereColumn('queued.moderatable_type', 'human.moderatable_type')
            ->whereColumn('queued.moderatable_id', 'human.moderatable_id')
            ->whereColumn('queued.id', '<', 'human.id')
            ->whereIn('queued.status', [ModerationStatus::Flagged->value, ModerationStatus::Blocked->value])
            ->orderByDesc('queued.id')
            ->limit(1);

        $pairs = $this->humans()
            ->select('human.created_at as reviewed_at')
            ->selectSub($queuedAt, 'queued_at')
            ->get();

        $durations = [];

        foreach ($pairs as $pair) {
            $reviewed = $pair->reviewed_at ?? null;
            $queued = $pair->queued_at ?? null;

            if (is_string($reviewed) && is_string($queued)) {
                $durations[] = max(0, (int) Carbon::parse($queued)->diffInSeconds(Carbon::parse($reviewed)));
            }
        }

        return ['seconds' => self::median($durations), 'reviews' => count($durations)];
    }

    /**
     * Moderator reviews in the window, each paired with Jev's latest earlier
     * result for the record, counted as `jev-moderation:stats` counts them.
     */
    public function agreement(): Agreement
    {
        $table = (new ModerationDecision)->getTable();
        $base = ModerationDecision::query()->toBase();

        $latestResult = (clone $base)->from("{$table} as prior")
            ->selectRaw('max(prior.id)')
            ->whereColumn('prior.moderatable_type', 'human.moderatable_type')
            ->whereColumn('prior.moderatable_id', 'human.moderatable_id')
            ->whereColumn('prior.id', '<', 'human.id')
            ->whereIn('prior.source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
            ->whereIn('prior.status', [ModerationStatus::Passed->value, ModerationStatus::Flagged->value, ModerationStatus::Blocked->value]);

        $jevStatus = (clone $base)->from("{$table} as result")
            ->select('result.status')
            ->where('result.id', '=', $latestResult);

        $rows = (clone $base)
            ->fromSub($this->humans()->select('human.status')->selectSub($jevStatus, 'jev_status'), 'paired')
            ->selectRaw('status, jev_status, count(*) as total')
            ->groupBy('status', 'jev_status')
            ->get();

        $agreement = new Agreement;

        foreach ($rows as $row) {
            $total = $row->total ?? 0;
            $agreement = $agreement->with(
                is_string($row->status ?? null) ? $row->status : '',
                is_string($row->jev_status ?? null) ? $row->jev_status : null,
                is_numeric($total) ? (int) $total : 0,
            );
        }

        return $agreement;
    }

    /**
     * Moderator approvals and rejections in the window, aliased `human`.
     */
    private function humans(): QueryBuilder
    {
        $table = (new ModerationDecision)->getTable();

        return ModerationDecision::query()->toBase()
            ->from("{$table} as human")
            ->where('human.source', ModerationSource::Human->value)
            ->whereIn('human.status', [ModerationStatus::Approved->value, ModerationStatus::Rejected->value])
            ->whereIn('human.moderatable_type', array_keys($this->queue->modelTypes()))
            ->where('human.created_at', '>=', Carbon::now()->subDays(self::DAYS));
    }

    private function decisions(): QueryBuilder
    {
        return ModerationDecision::query()->toBase()
            ->whereIn('moderatable_type', array_keys($this->queue->modelTypes()));
    }

    /**
     * @param  list<int>  $values
     */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $middle = intdiv(count($values), 2);

        return count($values) % 2 === 1
            ? $values[$middle]
            : intdiv($values[$middle - 1] + $values[$middle], 2);
    }
}
