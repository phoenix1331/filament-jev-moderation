<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Every decision about one author's content, across the shown model types
 * whose author relation points at the author's class. Decisions carry no
 * author, so each type is matched through its relation's foreign key on
 * the live record (core visibility scope removed, host scopes such as
 * SoftDeletes kept).
 *
 * @internal
 */
final readonly class AuthorHistoryQuery
{
    /** The windows counted, in days. */
    public const array WINDOWS = [7, 30];

    public function __construct(
        private JevModerationPlugin $plugin,
        private Model $author,
    ) {}

    /**
     * The author relation of each shown type that can belong to this author,
     * keyed by `moderatable_type`.
     *
     * @return array<string, BelongsTo<Model, Model>>
     */
    public function relations(): array
    {
        $relations = [];

        foreach ((new ReviewQueueQuery($this->plugin))->modelTypes() as $type => $class) {
            $relation = $this->plugin->getAuthorRelation($type);

            if ($relation !== null && $relation->getRelated()->getMorphClass() === $this->author->getMorphClass()) {
                $relations[$type] = $relation;
            }
        }

        return $relations;
    }

    /**
     * @return Builder<ModerationDecision>
     */
    public function builder(): Builder
    {
        $relations = $this->relations();
        $key = $this->author->getKey();

        return ModerationDecision::query()
            ->whereIn((new ModerationDecision)->qualifyColumn('moderatable_type'), array_keys($relations))
            ->whereHasMorph('moderatable', array_map(static fn (BelongsTo $relation): string => $relation->getParent()::class, array_values($relations)), static function (Builder $record) use ($relations, $key): void {
                $relation = $relations[$record->getModel()->getMorphClass()];

                $record->scopes('withoutModeration');
                $record->where($relation->getQualifiedForeignKeyName(), $key);
            });
    }

    /**
     * Decisions per status over the last 7 and 30 days, every status
     * listed, zeros included.
     *
     * @return array<string, array<int, int>> status value to window to count
     */
    public function counts(): array
    {
        $now = Carbon::now();
        $column = (new ModerationDecision)->qualifyColumn('created_at');
        $counts = [];

        foreach (ModerationStatus::cases() as $status) {
            $counts[$status->value] = array_fill_keys(self::WINDOWS, 0);
        }

        $rows = $this->builder()
            ->toBase()
            ->select((new ModerationDecision)->qualifyColumn('status'))
            // The outer query has no joins (authors match through EXISTS), so created_at is unambiguous.
            ->selectRaw('sum(case when created_at >= ? then 1 else 0 end) as recent', [$now->copy()->subDays(self::WINDOWS[0])])
            ->selectRaw('count(*) as total')
            ->where($column, '>=', $now->copy()->subDays(self::WINDOWS[1]))
            ->groupBy((new ModerationDecision)->qualifyColumn('status'))
            ->get();

        foreach ($rows as $row) {
            $status = isset($row->status) && is_string($row->status) ? $row->status : null;

            if ($status !== null && isset($counts[$status])) {
                $counts[$status] = [
                    self::WINDOWS[0] => self::integer($row->recent ?? 0),
                    self::WINDOWS[1] => self::integer($row->total ?? 0),
                ];
            }
        }

        return $counts;
    }

    /**
     * Aggregates come back as strings on some drivers.
     */
    private static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
