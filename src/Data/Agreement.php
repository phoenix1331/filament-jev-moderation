<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * Moderator reviews against Jev's latest earlier result, counted as the
 * core's `jev-moderation:stats` counts them: upheld (rejected after a flag
 * or block), overturned (approved after one), missed (rejected after a
 * pass), dismissed (approved after a pass) and unchecked (no result).
 *
 * @internal
 */
final readonly class Agreement
{
    public function __construct(
        public int $upheld = 0,
        public int $overturned = 0,
        public int $missed = 0,
        public int $dismissed = 0,
        public int $unchecked = 0,
    ) {}

    /**
     * Add reviews by the moderator's status and Jev's (null when unchecked).
     */
    public function with(string $moderator, ?string $jev, int $count): self
    {
        $rejected = $moderator === ModerationStatus::Rejected->value;

        return match (true) {
            $jev === null => new self($this->upheld, $this->overturned, $this->missed, $this->dismissed, $this->unchecked + $count),
            $jev === ModerationStatus::Passed->value && $rejected => new self($this->upheld, $this->overturned, $this->missed + $count, $this->dismissed, $this->unchecked),
            $jev === ModerationStatus::Passed->value => new self($this->upheld, $this->overturned, $this->missed, $this->dismissed + $count, $this->unchecked),
            $rejected => new self($this->upheld + $count, $this->overturned, $this->missed, $this->dismissed, $this->unchecked),
            default => new self($this->upheld, $this->overturned + $count, $this->missed, $this->dismissed, $this->unchecked),
        };
    }

    /**
     * Flags and blocks a moderator checked.
     */
    public function checked(): int
    {
        return $this->upheld + $this->overturned;
    }

    /**
     * The share of checked flags and blocks moderators overturned, 0 to 1;
     * null before any were checked.
     */
    public function overrideRate(): ?float
    {
        return $this->checked() === 0 ? null : $this->overturned / $this->checked();
    }
}
