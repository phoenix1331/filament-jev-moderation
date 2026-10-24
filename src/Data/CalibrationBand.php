<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

use Phoenix1331\FilamentJevModeration\Enums\CalibrationAdvice;

/**
 * One confidence band, `[lower, upper)`, for one policy and category (or
 * the policy's low-confidence flags, banded by Jev's own confidence), with
 * how moderators judged Jev's results in it.
 *
 * @internal
 */
final readonly class CalibrationBand
{
    /**
     * @param  string|null  $category  null for the low-confidence lane
     * @param  int  $overturned  flags and blocks a moderator approved
     * @param  int  $upheld  flags and blocks a moderator rejected
     * @param  int  $missed  passes a moderator rejected (after a report)
     * @param  int  $blocked  of the checked, how many Jev had blocked
     * @param  int  $reclassified  of the upheld, how many under another category
     * @param  float|null  $threshold  the value the advice points to
     */
    public function __construct(
        public string $policy,
        public ?string $category,
        public float $lower,
        public float $upper,
        public int $overturned = 0,
        public int $upheld = 0,
        public int $missed = 0,
        public int $blocked = 0,
        public int $reclassified = 0,
        public ?CalibrationAdvice $advice = null,
        public ?float $threshold = null,
    ) {}

    public function isLowConfidence(): bool
    {
        return $this->category === null;
    }

    /**
     * Flags and blocks a moderator checked: the override rate's denominator.
     */
    public function sample(): int
    {
        return $this->overturned + $this->upheld;
    }

    public function overrideRate(): ?float
    {
        return $this->sample() === 0 ? null : $this->overturned / $this->sample();
    }
}
