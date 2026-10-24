<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Enums;

/**
 * What a calibration band suggests when moderators overturn Jev there too
 * often. Only reviewed content has evidence, so advice is one-sided: it can
 * say a threshold acts too eagerly, never that it should act sooner.
 */
enum CalibrationAdvice: string
{
    /** Flags in this band are mostly wrong: raise review_above past it. */
    case RaiseReviewAbove = 'raise_review_above';

    /** Blocks in this band are mostly wrong: raise block_above past it. */
    case RaiseBlockAbove = 'raise_block_above';

    /** Low-confidence flags in this band are mostly wrong: lower uncertain_below to it. */
    case LowerUncertainBelow = 'lower_uncertain_below';

    /**
     * The status to re-check after making the change: content whose latest
     * decision it affects. `rescan` skips approved and rejected content
     * unless named, and moderators' decisions must stand.
     */
    public function rescanStatus(): string
    {
        return $this === self::RaiseBlockAbove ? 'blocked' : 'flagged';
    }
}
