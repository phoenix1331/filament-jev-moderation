<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Enums;

/**
 * What a moderator can do to queued content. The value is the Gate ability
 * checked on a `ModerationDecision` policy, when the app registers one.
 */
enum ReviewAction: string
{
    /** Overturn the flag or block: the content is shown. */
    case Approve = 'approve';

    /** Confirm the flag or block: the content is removed. */
    case Reject = 'reject';

    /** Remove the content under a corrected category. */
    case Reclassify = 'reclassify';
}
