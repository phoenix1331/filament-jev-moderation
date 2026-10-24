<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Phoenix1331\FilamentJevModeration\Data\ReviewOutcome;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Review\QueueReviewer;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Approve and reject, for one queued record or a selection. Each asks for
 * an optional note, records through QueueReviewer and reports what was
 * resolved, skipped and refused.
 *
 * @internal
 */
final class ReviewActions
{
    /** Longest note a moderator can leave. */
    public const int NOTE_LENGTH = 2000;

    public static function row(ReviewAction $action): Action
    {
        return Action::make($action->value)
            ->label(__("filament-jev-moderation::queue.actions.{$action->value}.label"))
            ->icon(self::icon($action))
            ->color(self::color($action))
            ->authorize(static fn (ModerationDecision $record): bool => JevModerationPlugin::get()->canReview($action, $record))
            ->modalHeading(__("filament-jev-moderation::queue.actions.{$action->value}.modal_heading"))
            ->modalDescription(__("filament-jev-moderation::queue.actions.{$action->value}.modal_description"))
            ->modalSubmitActionLabel(__("filament-jev-moderation::queue.actions.{$action->value}.label"))
            ->schema(self::noteField())
            ->action(static function (ModerationDecision $record, array $data) use ($action): void {
                self::notify($action, self::review([$record], $action, $data));
            });
    }

    public static function bulk(ReviewAction $action): BulkAction
    {
        return BulkAction::make("{$action->value}_selected")
            ->label(__("filament-jev-moderation::queue.actions.{$action->value}.bulk_label"))
            ->icon(self::icon($action))
            ->color(self::color($action))
            ->authorize(static fn (): bool => JevModerationPlugin::get()->canReview($action))
            ->modalHeading(__("filament-jev-moderation::queue.actions.{$action->value}.bulk_modal_heading"))
            ->modalDescription(__("filament-jev-moderation::queue.actions.{$action->value}.modal_description"))
            ->modalSubmitActionLabel(__("filament-jev-moderation::queue.actions.{$action->value}.label"))
            ->schema(self::noteField())
            ->deselectRecordsAfterCompletion()
            ->action(static function (Collection $records, array $data) use ($action): void {
                self::notify($action, self::review($records->filter(static fn (mixed $record): bool => $record instanceof ModerationDecision), $action, $data));
            });
    }

    /**
     * @return list<Textarea>
     */
    private static function noteField(): array
    {
        return [
            Textarea::make('note')
                ->label(__('filament-jev-moderation::queue.actions.note.label'))
                ->helperText(__('filament-jev-moderation::queue.actions.note.helper'))
                ->maxLength(self::NOTE_LENGTH)
                ->rows(3),
        ];
    }

    /**
     * @param  iterable<ModerationDecision>  $decisions
     * @param  array<mixed>  $data
     */
    private static function review(iterable $decisions, ReviewAction $action, array $data): ReviewOutcome
    {
        $note = $data['note'] ?? null;

        return (new QueueReviewer(JevModerationPlugin::get()))
            ->review($decisions, $action, is_string($note) ? $note : null, Filament::auth()->user());
    }

    private static function notify(ReviewAction $action, ReviewOutcome $outcome): void
    {
        $prefix = "filament-jev-moderation::queue.actions.{$action->value}";
        $failed = count($outcome->failures);
        $body = [];

        if ($outcome->skipped > 0) {
            $body[] = trans_choice('filament-jev-moderation::queue.actions.skipped', $outcome->skipped, ['count' => $outcome->skipped]);
        }

        if ($failed > 0) {
            $body[] = trans_choice('filament-jev-moderation::queue.actions.failed', $failed, ['count' => $failed, 'reason' => $outcome->failures[0]]);
        }

        $notification = Notification::make()
            ->title($outcome->resolved > 0
                ? trans_choice("{$prefix}.done", $outcome->resolved, ['count' => $outcome->resolved])
                : __("{$prefix}.none"))
            ->body($body === [] ? null : implode(' ', $body));

        match (true) {
            $outcome->resolved === 0 => $notification->danger(),
            $body !== [] => $notification->warning(),
            default => $notification->success(),
        };

        $notification->send();
    }

    private static function icon(ReviewAction $action): string
    {
        return $action === ReviewAction::Approve ? 'heroicon-o-check' : 'heroicon-o-x-mark';
    }

    private static function color(ReviewAction $action): string
    {
        return $action === ReviewAction::Approve ? 'success' : 'danger';
    }
}
