<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Decisions per day by status for the shown model types, grouped in SQL
 * and filled with zeros for quiet days. Pending is left out: every check
 * writes a pending row first, so it would only echo the total.
 *
 * @internal
 */
final readonly class DecisionVolume
{
    /** Bottom of the stack first: shown, then waiting, then removed, then failed checks. */
    public const array STATUSES = [
        ModerationStatus::Passed,
        ModerationStatus::Approved,
        ModerationStatus::Flagged,
        ModerationStatus::Rejected,
        ModerationStatus::Blocked,
        ModerationStatus::Failed,
    ];

    public function __construct(private JevModerationPlugin $plugin) {}

    /**
     * @return array{days: list<string>, counts: array<string, list<int>>} days as Y-m-d, oldest first; counts per status value, one per day
     */
    public function perDay(int $days): array
    {
        $first = Carbon::today()->subDays($days - 1);
        $dates = [];

        for ($day = $first->copy(); $day->lte(Carbon::today()); $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        $counts = [];

        foreach (self::STATUSES as $status) {
            $counts[$status->value] = array_fill(0, count($dates), 0);
        }

        $rows = ModerationDecision::query()->toBase()
            ->selectRaw('date(created_at) as day, status, count(*) as total')
            ->whereIn('moderatable_type', array_keys((new ReviewQueueQuery($this->plugin))->modelTypes()))
            ->whereIn('status', array_keys($counts))
            ->where('created_at', '>=', $first)
            ->groupByRaw('date(created_at), status')
            ->get();

        $index = array_flip($dates);

        foreach ($rows as $row) {
            $day = is_string($row->day ?? null) ? Carbon::parse($row->day)->toDateString() : null;
            $status = is_string($row->status ?? null) ? $row->status : null;
            $total = $row->total ?? 0;

            if ($day !== null && $status !== null && isset($index[$day], $counts[$status])) {
                $counts[$status][$index[$day]] = is_numeric($total) ? (int) $total : 0;
            }
        }

        return ['days' => $dates, 'counts' => $counts];
    }
}
