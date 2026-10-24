<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

use Illuminate\Support\Carbon;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Enums\VerdictReason;

/**
 * One decision in a record's history, read from its row and `meta`.
 *
 * @internal
 */
final readonly class TimelineEntry
{
    /**
     * @param  string|null  $person  the reviewer, or the reporter of a report
     * @param  string|null  $reason  why Jev decided (a VerdictReason value), or why a check failed
     * @param  float|null  $jevConfidence  Jev's confidence in its top choice, which an uncertain_below floor compares
     * @param  list<CategoryProbability>  $probabilities  highest first
     * @param  bool  $current  the decision the page was opened on
     */
    public function __construct(
        public int $id,
        public ModerationStatus $status,
        public ModerationSource $source,
        public ?Carbon $at,
        public ?string $person,
        public ?string $note,
        public ?string $model,
        public ?string $category,
        public ?float $confidence,
        public string $policy,
        public string $policyVersion,
        public ?string $reason,
        public ?bool $retryable,
        public ?float $jevConfidence,
        public array $probabilities,
        public ?string $content,
        public bool $current,
    ) {}

    /**
     * Why it was decided, in words: a failed check's own message, or Jev's
     * verdict reason translated.
     */
    public function reasonLabel(): ?string
    {
        if ($this->reason === null || $this->status === ModerationStatus::Failed || VerdictReason::tryFrom($this->reason) === null) {
            return $this->reason;
        }

        return __("filament-jev-moderation::decision.reasons.{$this->reason}");
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            ModerationStatus::Pending, ModerationStatus::Failed => 'gray',
            ModerationStatus::Passed, ModerationStatus::Approved => 'success',
            ModerationStatus::Flagged => 'warning',
            ModerationStatus::Blocked, ModerationStatus::Rejected => 'danger',
        };
    }
}
