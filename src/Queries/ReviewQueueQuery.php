<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Concerns\Moderatable;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * The review queue: decisions that are still their record's latest row,
 * waiting for a human, on a live record of a model type the plugin shows.
 *
 * Starts from the `status, created_at` index and drops superseded rows
 * with NOT EXISTS, so only flagged rows are touched and every comparison
 * stays in SQL. Records are matched without the core's visibility scope,
 * which would otherwise hide every queued record in `pre` mode; host
 * scopes such as SoftDeletes still apply.
 *
 * @internal
 */
final readonly class ReviewQueueQuery
{
    public function __construct(private JevModerationPlugin $plugin) {}

    /**
     * @return Builder<ModerationDecision>
     */
    public function builder(): Builder
    {
        $types = $this->modelTypes();
        $table = (new ModerationDecision)->getTable();

        return ModerationDecision::query()
            ->whereIn("{$table}.status", array_map(static fn (ModerationStatus $status): string => $status->value, $this->statuses()))
            ->whereIn("{$table}.moderatable_type", array_keys($types))
            ->whereNotExists(static fn (QueryBuilder $newer): QueryBuilder => $newer->selectRaw('1')
                ->from("{$table} as newer")
                ->whereColumn('newer.moderatable_type', "{$table}.moderatable_type")
                ->whereColumn('newer.moderatable_id', "{$table}.moderatable_id")
                ->whereColumn('newer.id', '>', "{$table}.id"))
            ->whereHasMorph('moderatable', array_values($types), static function (Builder $record): void {
                $record->scopes('withoutModeration');
            });
    }

    /**
     * The statuses that wait for a human: flagged, plus blocked with includeBlocked().
     *
     * @return list<ModerationStatus>
     */
    public function statuses(): array
    {
        if (! $this->plugin->isBlockedIncluded()) {
            return [ModerationStatus::Flagged];
        }

        return [ModerationStatus::Flagged, ModerationStatus::Blocked];
    }

    /**
     * The model types shown, keyed by the `moderatable_type` stored for
     * them: the plugin's models(), else every type with decisions whose
     * class still exists and uses the Moderatable trait.
     *
     * @return array<string, class-string<Model>>
     */
    public function modelTypes(): array
    {
        $models = $this->plugin->getModels();

        if ($models !== null) {
            return array_combine(
                array_map(static fn (string $class): string => (new $class)->getMorphClass(), $models),
                $models,
            );
        }

        $types = [];

        foreach (ModerationDecision::query()->toBase()->distinct()->orderBy('moderatable_type')->pluck('moderatable_type') as $type) {
            $class = is_string($type) ? self::moderatableClass($type) : null;

            if ($class !== null) {
                $types[$type] = $class;
            }
        }

        return $types;
    }

    /**
     * @return class-string<Model>|null
     */
    private static function moderatableClass(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true) || ! in_array(Moderatable::class, class_uses_recursive($class), true)) {
            return null;
        }

        return $class;
    }
}
