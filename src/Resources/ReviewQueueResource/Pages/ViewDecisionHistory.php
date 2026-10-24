<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Livewire\Attributes\Locked;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\DecisionTimeline;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Everything recorded for one moderated record: its content as it is now,
 * and every Jev result, report, review, rescan and failed check, with the
 * category probabilities, Jev version and policy version of each. Opened
 * from any of the record's decisions, which is highlighted.
 */
class ViewDecisionHistory extends Page
{
    protected static string $resource = ReviewQueueResource::class;

    #[Locked]
    public int|string $record;

    private ?ModerationDecision $decision = null;

    public function mount(int|string $record): void
    {
        $this->record = $record;

        abort_unless(ReviewQueueResource::canView($this->getDecision()), 403);
    }

    public function getView(): string
    {
        return 'filament-jev-moderation::pages.decision-history';
    }

    public function getTitle(): string
    {
        $decision = $this->getDecision();

        return __('filament-jev-moderation::decision.title', [
            'model' => JevModerationPlugin::get()->getModelLabel($decision->moderatable_type),
            'key' => $decision->moderatable_id,
        ]);
    }

    public function getBreadcrumb(): string
    {
        return __('filament-jev-moderation::decision.breadcrumb');
    }

    /**
     * The decision the page was opened on, with its record loaded past the
     * core's visibility scope; 404 for an unknown id or a type the plugin
     * does not show.
     */
    public function getDecision(): ModerationDecision
    {
        if ($this->decision !== null) {
            return $this->decision;
        }

        $queue = new ReviewQueueQuery(JevModerationPlugin::get());

        $decision = ModerationDecision::query()
            ->whereIn('moderatable_type', array_keys($queue->modelTypes()))
            ->whereKey($this->record)
            ->firstOrFail();

        $decision->load(['moderatable' => static fn (MorphTo $morph): MorphTo => $morph->constrain($queue->recordConstraints())]);

        return $this->decision = $decision;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $decision = $this->getDecision();
        $record = $decision->getRelationValue('moderatable');

        return [
            'decision' => $decision,
            'author' => ReviewQueueResource::authorName($decision),
            'content' => $record instanceof Model && method_exists($record, 'moderatableContent') ? self::fields($record->moderatableContent()) : null,
            'entries' => (new DecisionTimeline)->for($decision),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view_record')
                ->label(__('filament-jev-moderation::queue.actions.view_record'))
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->color('gray')
                ->url(fn (): ?string => JevModerationPlugin::get()->getRecordUrl($this->getDecision()))
                ->openUrlInNewTab()
                ->visible(fn (): bool => JevModerationPlugin::get()->getRecordUrl($this->getDecision()) !== null),
        ];
    }

    /**
     * @return array<string, string> field name to text, empty fields left out
     */
    private static function fields(mixed $content): array
    {
        return array_filter(is_array($content) ? $content : [], static fn (mixed $value, mixed $field): bool => is_string($field) && is_string($value) && trim($value) !== '', ARRAY_FILTER_USE_BOTH);
    }
}
