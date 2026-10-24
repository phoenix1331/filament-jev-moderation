<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages;

use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Number;
use Livewire\Attributes\Locked;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\AuthorHistoryQuery;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Support\DisplayName;
use Phoenix1331\FilamentJevModeration\Support\StatusColour;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Every decision about one author's content, across model types, with
 * counts per status for the last 7 and 30 days. The author is named by
 * key and morph type (`?type=`), since one panel can moderate content by
 * more than one kind of author.
 */
class AuthorHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = ReviewQueueResource::class;

    #[Locked]
    public int|string $author;

    #[Locked]
    public string $type = '';

    private ?Model $authorModel = null;

    public function mount(int|string $author): void
    {
        $type = request()->query('type');

        $this->author = $author;
        $this->type = is_string($type) ? $type : '';

        $this->getAuthor();
    }

    public static function urlFor(Model $author): string
    {
        return ReviewQueueResource::getUrl('author', ['author' => $author->getKey(), 'type' => $author->getMorphClass()]);
    }

    /**
     * The author; 403 without access, then 404 when the type is not a model, the author does not
     * exist, or no shown model type names this kind of author.
     */
    public function getAuthor(): Model
    {
        if ($this->authorModel !== null) {
            return $this->authorModel;
        }

        // Before looking the author up, so a denied user cannot probe which authors exist.
        abort_unless(ReviewQueueResource::canAccess(), 403);

        $class = Relation::getMorphedModel($this->type) ?? $this->type;
        $author = is_a($class, Model::class, true) ? $class::query()->find($this->author) : null;

        abort_unless($author instanceof Model && $this->history($author)->relations() !== [], 404);

        return $this->authorModel = $author;
    }

    public function getView(): string
    {
        return 'filament-jev-moderation::pages.author-history';
    }

    public function getTitle(): string
    {
        return DisplayName::of($this->getAuthor());
    }

    public function getSubheading(): string
    {
        return __('filament-jev-moderation::author.subheading');
    }

    public function getBreadcrumb(): string
    {
        return __('filament-jev-moderation::author.breadcrumb');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->history($this->getAuthor())->builder()
                ->with(['moderatable' => static fn (MorphTo $morph): MorphTo => $morph->constrain((new ReviewQueueQuery(JevModerationPlugin::get()))->recordConstraints())]))
            ->columns([
                TextColumn::make('moderatable_type')
                    ->label(__('filament-jev-moderation::author.columns.model'))
                    ->formatStateUsing(static fn (string $state): string => JevModerationPlugin::get()->getModelLabel($state)),
                TextColumn::make('excerpt')
                    ->label(__('filament-jev-moderation::author.columns.excerpt'))
                    ->state(static fn (ModerationDecision $record): ?string => ReviewQueueResource::excerpt($record))
                    ->wrap(),
                TextColumn::make('status')
                    ->label(__('filament-jev-moderation::author.columns.status'))
                    ->formatStateUsing(static fn (ModerationStatus $state): string => __("filament-jev-moderation::statuses.{$state->value}"))
                    ->badge()
                    ->color(static fn (ModerationStatus $state): string => StatusColour::for($state)),
                TextColumn::make('source')
                    ->label(__('filament-jev-moderation::author.columns.source'))
                    ->formatStateUsing(static fn (ModerationSource $state): string => __("filament-jev-moderation::sources.{$state->value}")),
                TextColumn::make('category')
                    ->label(__('filament-jev-moderation::author.columns.category'))
                    ->formatStateUsing(static fn (string $state): string => ReviewQueueResource::categoryLabel($state)),
                TextColumn::make('confidence')
                    ->label(__('filament-jev-moderation::author.columns.confidence'))
                    ->formatStateUsing(static fn (float|int|string $state): string => Number::percentage((float) $state * 100) ?: ''),
                TextColumn::make('created_at')
                    ->label(__('filament-jev-moderation::author.columns.when'))
                    ->since()
                    ->dateTimeTooltip(),
            ])
            ->defaultSort((new ModerationDecision)->qualifyColumn('id'), 'desc')
            ->recordUrl(static fn (ModerationDecision $record): string => ReviewQueueResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading(__('filament-jev-moderation::author.empty.heading'))
            ->emptyStateDescription(__('filament-jev-moderation::author.empty.description'))
            ->emptyStateIcon('heroicon-o-user');
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'counts' => $this->history($this->getAuthor())->counts(),
            'windows' => AuthorHistoryQuery::WINDOWS,
        ];
    }

    private function history(Model $author): AuthorHistoryQuery
    {
        return new AuthorHistoryQuery(JevModerationPlugin::get(), $author);
    }
}
