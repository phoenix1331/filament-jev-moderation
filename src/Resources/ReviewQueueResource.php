<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources;

use BackedEnum;
use Filament\Models\Contracts\HasName;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Phoenix1331\FilamentJevModeration\Concerns\HasModerationNavigation;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
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
        $constraints = [];
        $authors = [];

        foreach ($queue->modelTypes() as $type => $class) {
            $constraints[$class] = static function (Builder $record): void {
                $record->scopes('withoutModeration');
            };

            $relation = $plugin->getAuthorRelation($type);

            if ($relation !== null) {
                $authors[$class] = [$relation->getRelationName()];
            }
        }

        return $queue->builder()
            ->select("{$table}.*")
            ->addSelect(['jev_category' => ModerationDecision::query()->toBase()
                ->select('earlier.category')
                ->from("{$table} as earlier")
                ->whereColumn('earlier.moderatable_type', "{$table}.moderatable_type")
                ->whereColumn('earlier.moderatable_id', "{$table}.moderatable_id")
                ->whereColumn('earlier.id', '<', "{$table}.id")
                ->whereIn('earlier.source', [ModerationSource::Jev->value, ModerationSource::Rescan->value])
                ->whereNotNull('earlier.category')
                ->orderByDesc('earlier.id')
                ->limit(1)])
            ->with(['moderatable' => static fn (MorphTo $morph): MorphTo => $morph->constrain($constraints)->morphWith($authors)]);
    }

    public static function table(Table $table): Table
    {
        $decisions = (new ModerationDecision)->getTable();

        return $table
            ->columns([
                TextColumn::make('moderatable_type')
                    ->label(__('filament-jev-moderation::queue.columns.model'))
                    ->formatStateUsing(static fn (string $state): string => JevModerationPlugin::get()->getModelLabel($state)),
                TextColumn::make('excerpt')
                    ->label(__('filament-jev-moderation::queue.columns.excerpt'))
                    ->state(static fn (ModerationDecision $record): ?string => self::excerpt($record))
                    ->wrap(),
                TextColumn::make('author')
                    ->label(__('filament-jev-moderation::queue.columns.author'))
                    ->state(static fn (ModerationDecision $record): ?string => self::authorName($record)),
                TextColumn::make('category')
                    ->label(__('filament-jev-moderation::queue.columns.category'))
                    ->state(static fn (ModerationDecision $record): ?string => self::category($record))
                    ->formatStateUsing(static fn (string $state): string => Str::ucfirst(str_replace('_', ' ', $state)))
                    ->badge()
                    ->color('danger'),
                TextColumn::make('confidence')
                    ->label(__('filament-jev-moderation::queue.columns.confidence'))
                    ->formatStateUsing(static fn (float|int|string $state): string => Number::percentage((float) $state * 100) ?: '')
                    ->sortable(),
                TextColumn::make('source')
                    ->label(__('filament-jev-moderation::queue.columns.source'))
                    ->formatStateUsing(static fn (ModerationSource $state): string => __("filament-jev-moderation::sources.{$state->value}"))
                    ->description(static fn (ModerationDecision $record): ?string => $record->source === ModerationSource::Report ? $record->note : null)
                    ->badge()
                    ->color(static fn (ModerationSource $state): string => $state === ModerationSource::Report ? 'warning' : 'gray'),
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
            ->emptyStateHeading(__('filament-jev-moderation::queue.empty.heading'))
            ->emptyStateDescription(__('filament-jev-moderation::queue.empty.description'))
            ->emptyStateIcon('heroicon-o-inbox-stack');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReviewQueue::route('/'),
        ];
    }

    protected static function queue(): ReviewQueueQuery
    {
        return new ReviewQueueQuery(JevModerationPlugin::get());
    }

    /**
     * The record's moderated fields as they are now, joined and shortened.
     */
    protected static function excerpt(ModerationDecision $decision): ?string
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

    protected static function authorName(ModerationDecision $decision): ?string
    {
        $record = $decision->getRelationValue('moderatable');
        $relation = JevModerationPlugin::get()->getAuthorRelation($decision->moderatable_type);

        if (! $record instanceof Model || $relation === null) {
            return null;
        }

        $author = $record->getRelationValue($relation->getRelationName());

        if ($author instanceof HasName) {
            $name = $author->getFilamentName();

            return is_string($name) ? $name : $name->toHtml();
        }

        if (! $author instanceof Model) {
            return null;
        }

        $name = $author->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : '#'.self::key($author);
    }

    /**
     * The deciding category, or for a report the category of the Jev
     * result before it.
     */
    protected static function category(ModerationDecision $decision): ?string
    {
        $category = $decision->category ?? $decision->getAttribute('jev_category');

        return is_string($category) ? $category : null;
    }

    private static function key(Model $model): string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
