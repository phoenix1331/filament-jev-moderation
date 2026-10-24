<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Review;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Phoenix1331\FilamentJevModeration\Data\ReviewOutcome;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Approves or rejects queued records through the core's Moderatable API,
 * which appends the human decision and fires ReviewResolved. This plugin
 * never writes a decision itself.
 *
 * The core records whatever it is asked, so two moderators acting on one
 * record would append two reviews. Each record is locked first and skipped
 * unless the row the moderator saw is still its latest decision.
 *
 * @internal
 */
final readonly class QueueReviewer
{
    public function __construct(private JevModerationPlugin $plugin) {}

    /**
     * @param  iterable<ModerationDecision>  $decisions  the queue rows acted on
     */
    public function review(iterable $decisions, ReviewAction $action, ?string $note = null, ?Authenticatable $user = null): ReviewOutcome
    {
        $outcome = new ReviewOutcome;
        $note = $note === null || trim($note) === '' ? null : trim($note);

        foreach ($decisions as $decision) {
            if (! $this->plugin->canReview($action, $decision, $user)) {
                $outcome = $outcome->withSkipped();

                continue;
            }

            try {
                $resolved = DB::transaction(fn (): bool => $this->resolve($decision, $action, $note, $user));
            } catch (InvalidArgumentException $exception) {
                // The record's policy was removed from config, or a category is no longer valid.
                $outcome = $outcome->withFailure($exception->getMessage());

                continue;
            }

            $outcome = $resolved ? $outcome->withResolved() : $outcome->withSkipped();
        }

        return $outcome;
    }

    private function resolve(ModerationDecision $decision, ReviewAction $action, ?string $note, ?Authenticatable $user): bool
    {
        $record = $this->lockedRecord($decision);
        $method = $action === ReviewAction::Approve ? 'approve' : 'reject';

        if ($record === null || ! method_exists($record, $method) || $this->hasMoved($decision)) {
            return false;
        }

        $record->{$method}($user instanceof Model ? $user : null, $note);

        return true;
    }

    /**
     * The moderated record, locked so a second moderator waits for this
     * review and then sees it. Hidden by the core or not, but never one a
     * host scope such as SoftDeletes removes.
     */
    private function lockedRecord(ModerationDecision $decision): ?Model
    {
        $class = Relation::getMorphedModel($decision->moderatable_type) ?? $decision->moderatable_type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        $query = $class::query();
        $query->scopes('withoutModeration');

        return $query->lockForUpdate()->find($decision->moderatable_id);
    }

    /**
     * Whether a newer decision exists for the record: reviewed, reported or
     * rescanned since the moderator loaded the page.
     */
    private function hasMoved(ModerationDecision $decision): bool
    {
        return ModerationDecision::query()
            ->where('moderatable_type', $decision->moderatable_type)
            ->where('moderatable_id', $decision->moderatable_id)
            ->where('id', '>', $decision->id)
            ->exists();
    }
}
