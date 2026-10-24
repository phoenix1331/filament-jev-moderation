<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources;

use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Phoenix1331\FilamentJevModeration\Concerns\HasModerationNavigation;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\AuthorHistory;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ViewDecisionHistory;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\ReviewActions;
use Phoenix1331\FilamentJevModeration\Support\DisplayName;
use Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable;
use Phoenix1331\FilamentJevModeration\Widgets\DecisionVolumeChart;
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Content waiting for a moderator, across every moderated model. A list
 * page only: decisions are append-only, so there is nothing to create,
 * edit or delete here.
 */
class ReviewQueueResource extends Resource
{
    use HasModerationNavigation;

    /** Characters of content shown per row. */
    public const int EXCERPT_LENGTH = 140;

    protected static ?string $model = ModerationDecision::class;

    protected static ?string $slug = 'moderation/queue';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-inbox-stack';

    protected static ?int $navigationSort = 1;

    protected static bool $isGloballySearchable = false;

    public static function getNavigationLabel(): string
    {
        return __('filament-jev-moderation::queue.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filament-jev-moderation::queue.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filament-jev-moderation::queue.plural_model_label');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = self::queue()->builder()->count();

        return $count === 0 ? null : (string) $count;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function canViewAny(): bool
    {
        return JevModerationPlugin::allows();
    }

    public static function canView(Model $record): bool
    {
        return JevModerationPlugin::allows();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * The queue, with each record loaded past the core's visibility scope
     * (host scopes such as SoftDeletes still apply) and its author, and the
     * category of the Jev result before a report, which carries none.
     *
     * @return Builder<ModerationDecision>
     */
    public static function getEloquentQuery(): Builder
    {
        $queue = self::queue();
        $plugin = JevModerationPlugin::get();
        $table = (new ModerationDecision)->getTable();
        $authors = [];

        foreach ($queue->modelTypes() as $type => $class) {
            $relation = $plugin->getAuthorRelation($type);

            if ($relation !== null) {
                $authors[$class] = [$relation->getRelationName()];
            }
        }

        return $queue->builder()
            ->select("{$table}.*")
            ->addSelect(['jev_category' => $queue->jevCategory()])
            ->with(['moderatable' => static fn (MorphTo $morph): MorphTo => $morph->constrain($queue->recordConstraints())->morphWith($authors)]);
    }

    public static function table(Table $table): Table
    {
        $decisions = (new ModerationDecision)->getTable();

        return $table
            ->columns([
                // The type sits above the content, not in its own column, so the table fits beside an open sidebar.
                TextColumn::make('excerpt')
                    ->label(__('filament-jev-moderation::queue.columns.excerpt'))
                    ->state(static fn (ModerationDecision $record): ?string => self::excerpt($record))
                    ->description(static fn (ModerationDecision $record): string => JevModerationPlugin::get()->getModelLabel($record->moderatable_type), position: 'above')
                    ->wrap(),
                TextColumn::make('author')
                    ->label(__('filament-jev-moderation::queue.columns.author'))
                    ->state(static fn (ModerationDecision $record): ?string => self::authorName($record))
                    ->url(static fn (ModerationDecision $record): ?string => self::authorUrl($record)),
                TextColumn::make('category')
                    ->label(__('filament-jev-moderation::queue.columns.category'))
                    ->state(static fn (ModerationDecision $record): ?string => self::queuedCategory($record))
                    ->formatStateUsing(static fn (string $state): string => self::categoryLabel($state))
                    // The source sits under the category, not in its own column, so the table fits beside an open sidebar.
                    ->description(static fn (ModerationDecision $record): string => self::sourceLine($record))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('confidence')
                    ->label(__('filament-jev-moderation::queue.columns.confidence'))
                    ->formatStateUsing(static fn (float|int|string $state): string => Number::percentage((float) $state * 100) ?: '')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('filament-jev-moderation::queue.columns.age'))
                    ->since()
                    ->dateTimeTooltip()
                    ->sortable(),
            ])
            // Most confident first, then the longest waiting; reports carry no confidence and come last.
            ->defaultSort(static fn (Builder $query): Builder => $query
                ->orderByRaw('case when confidence is null then 1 else 0 end')
                ->orderByDesc("{$decisions}.confidence")
                ->orderBy("{$decisions}.created_at")
                ->orderBy("{$decisions}.id"))
            ->filters([
                SelectFilter::make('moderatable_type')
                    ->label(__('filament-jev-moderation::queue.filters.model'))
                    ->multiple()
                    ->options(static fn (): array => self::modelOptions()),
                SelectFilter::make('category')
                    ->label(__('filament-jev-moderation::queue.filters.category'))
                    ->multiple()
                    ->options(static fn (): array => self::categoryOptions())
                    ->query(static fn (Builder $query, array $data): Builder => self::whereCategory($query, $data['values'] ?? null)),
                Filter::make('confidence')
                    ->schema([
                        TextInput::make('from')
                            ->label(__('filament-jev-moderation::queue.filters.confidence_from'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                        TextInput::make('until')
                            ->label(__('filament-jev-moderation::queue.filters.confidence_until'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%'),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => self::whereConfidence($query, $data))
                    ->indicateUsing(static fn (array $data): array => self::confidenceIndicators($data)),
                SelectFilter::make('source')
                    ->label(__('filament-jev-moderation::queue.filters.source'))
                    ->multiple()
                    ->options(static fn (): array => self::sourceOptions()),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('filament-jev-moderation::queue.filters.created_from')),
                        DatePicker::make('until')
                            ->label(__('filament-jev-moderation::queue.filters.created_until')),
                    ])
                    ->query(static fn (Builder $query, array $data): Builder => self::whereCreated($query, $data))
                    ->indicateUsing(static fn (array $data): array => self::createdIndicators($data)),
            ])
            ->recordActions([
                ReviewActions::viewRecord(),
                ReviewActions::row(ReviewAction::Approve),
                ReviewActions::row(ReviewAction::Reject),
                ReviewActions::row(ReviewAction::Reclassify),
            ], position: RecordActionsPosition::BeforeColumns)
            ->toolbarActions([
                ReviewActions::bulk(ReviewAction::Approve),
                ReviewActions::bulk(ReviewAction::Reject),
            ])
            ->emptyStateHeading(__('filament-jev-moderation::queue.empty.heading'))
            ->emptyStateDescription(__('filament-jev-moderation::queue.empty.description'))
            ->emptyStateIcon('heroicon-o-inbox-stack');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewQueue::route('/'),
            'view' => ViewDecisionHistory::route('/{record}'),
            'author' => AuthorHistory::route('/authors/{author}'),
        ];
    }

    /**
     * The widgets on the queue page. Filament 4 registers a resource's widget
     * components from this list; without it every widget update (lazy load,
     * filter change) fails with 419.
     */
    public static function getWidgets(): array
    {
        return [ModerationOverview::class, DecisionVolumeChart::class, CalibrationTable::class];
    }

    protected static function queue(): ReviewQueueQuery
    {
        return new ReviewQueueQuery(JevModerationPlugin::get());
    }

    /**
     * @return array<string, string>
     */
    protected static function modelOptions(): array
    {
        $plugin = JevModerationPlugin::get();
        $options = [];

        foreach (array_keys(self::queue()->modelTypes()) as $type) {
            $options[$type] = $plugin->getModelLabel($type);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function categoryOptions(): array
    {
        $options = [];

        foreach (self::queue()->categories() as $category) {
            $options[$category] = self::categoryLabel($category);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    protected static function sourceOptions(): array
    {
        $options = [];

        foreach (ModerationSource::cases() as $source) {
            $options[$source->value] = __("filament-jev-moderation::sources.{$source->value}");
        }

        return $options;
    }

    /**
     * Matches the category column: a report matches on the category of the
     * Jev result before it.
     *
     * @param  Builder<ModerationDecision>  $query
     * @return Builder<ModerationDecision>
     */
    protected static function whereCategory(Builder $query, mixed $values): Builder
    {
        $values = is_array($values) ? array_values(array_filter($values, is_string(...))) : [];

        if ($values === []) {
            return $query;
        }

        $column = (new ModerationDecision)->qualifyColumn('category');
        $queue = self::queue();

        return $query->where(static fn (Builder $query): Builder => $query
            ->whereIn($column, $values)
            ->orWhere(static fn (Builder $report): Builder => $report
                ->whereNull($column)
                ->where(static function (Builder $match) use ($queue, $values): void {
                    foreach ($values as $value) {
                        $match->getQuery()->orWhere(static fn (QueryBuilder $earlier): QueryBuilder => $queue->jevCategory($earlier), '=', $value);
                    }
                })));
    }

    /**
     * Confidence between two percentages, both inclusive. Reports, which
     * carry none, drop out once either bound is set.
     *
     * @param  Builder<ModerationDecision>  $query
     * @param  array<mixed>  $data
     * @return Builder<ModerationDecision>
     */
    protected static function whereConfidence(Builder $query, array $data): Builder
    {
        $column = (new ModerationDecision)->qualifyColumn('confidence');
        $from = self::percentage($data['from'] ?? null);
        $until = self::percentage($data['until'] ?? null);

        return $query
            ->when($from !== null, static fn (Builder $query): Builder => $query->where($column, '>=', round((float) $from / 100, 4)))
            ->when($until !== null, static fn (Builder $query): Builder => $query->where($column, '<=', round((float) $until / 100, 4)));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Indicator>
     */
    protected static function confidenceIndicators(array $data): array
    {
        $indicators = [];

        foreach (['from', 'until'] as $bound) {
            $value = self::percentage($data[$bound] ?? null);

            if ($value !== null) {
                $indicators[] = Indicator::make(__("filament-jev-moderation::queue.filters.indicators.confidence_{$bound}", [
                    'value' => Number::percentage($value),
                ]))->removeField($bound);
            }
        }

        return $indicators;
    }

    /**
     * Queued on or between two dates, both inclusive.
     *
     * @param  Builder<ModerationDecision>  $query
     * @param  array<mixed>  $data
     * @return Builder<ModerationDecision>
     */
    protected static function whereCreated(Builder $query, array $data): Builder
    {
        $column = (new ModerationDecision)->qualifyColumn('created_at');
        $from = self::date($data['from'] ?? null);
        $until = self::date($data['until'] ?? null);

        return $query
            ->when($from !== null, static fn (Builder $query): Builder => $query->whereDate($column, '>=', (string) $from))
            ->when($until !== null, static fn (Builder $query): Builder => $query->whereDate($column, '<=', (string) $until));
    }

    /**
     * @param  array<mixed>  $data
     * @return list<Indicator>
     */
    protected static function createdIndicators(array $data): array
    {
        $indicators = [];

        foreach (['from', 'until'] as $bound) {
            $value = self::date($data[$bound] ?? null);

            if ($value !== null) {
                $indicators[] = Indicator::make(__("filament-jev-moderation::queue.filters.indicators.created_{$bound}", [
                    'date' => Carbon::parse($value)->toFormattedDateString(),
                ]))->removeField($bound);
            }
        }

        return $indicators;
    }

    /**
     * A percentage from 0 to 100, or null when blank or out of range.
     */
    private static function percentage(mixed $value): ?float
    {
        if (! is_numeric($value) || $value < 0 || $value > 100) {
            return null;
        }

        return (float) $value;
    }

    /**
     * A Y-m-d date, or null when blank or malformed.
     */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * The record's moderated fields as they are now, joined and shortened.
     *
     * @internal
     */
    public static function excerpt(ModerationDecision $decision): ?string
    {
        $record = $decision->getRelationValue('moderatable');

        if (! $record instanceof Model || ! method_exists($record, 'moderatableContent')) {
            return null;
        }

        $content = $record->moderatableContent();

        if (! is_array($content) || $content === []) {
            return null;
        }

        return Str::limit(implode(' · ', array_filter($content, is_string(...))), self::EXCERPT_LENGTH);
    }

    /**
     * The author of the decision's record, through the model's author relation.
     *
     * @internal
     */
    public static function author(ModerationDecision $decision): ?Model
    {
        $record = $decision->getRelationValue('moderatable');
        $relation = JevModerationPlugin::get()->getAuthorRelation($decision->moderatable_type);

        if (! $record instanceof Model || $relation === null) {
            return null;
        }

        $author = $record->getRelationValue($relation->getRelationName());

        return $author instanceof Model ? $author : null;
    }

    /**
     * @internal
     */
    public static function authorName(ModerationDecision $decision): ?string
    {
        $author = self::author($decision);

        return $author === null ? null : DisplayName::of($author);
    }

    /**
     * @internal
     */
    public static function authorUrl(ModerationDecision $decision): ?string
    {
        $author = self::author($decision);

        return $author === null ? null : AuthorHistory::urlFor($author);
    }

    /**
     * The deciding category, or for a report the category of the Jev
     * result before it.
     *
     * @internal
     */
    public static function queuedCategory(ModerationDecision $decision): ?string
    {
        $category = $decision->category ?? $decision->getAttribute('jev_category');

        return is_string($category) ? $category : null;
    }

    /**
     * A category name in sentence case: `personal_data` reads "Personal data".
     *
     * @internal
     */
    public static function categoryLabel(string $category): string
    {
        return Str::ucfirst(str_replace('_', ' ', $category));
    }

    /**
     * Where the queued decision came from, with the reporter's reason for a
     * report: "Jev", or "Report · rude to a new member".
     *
     * @internal
     */
    public static function sourceLine(ModerationDecision $decision): string
    {
        $source = __("filament-jev-moderation::sources.{$decision->source->value}");

        if ($decision->source !== ModerationSource::Report || $decision->note === null || $decision->note === '') {
            return $source;
        }

        return "{$source} · {$decision->note}";
    }
}
