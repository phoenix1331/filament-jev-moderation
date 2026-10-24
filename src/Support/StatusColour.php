<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Support;

use Phoenix1331\JevModeration\Enums\ModerationStatus;

/**
 * The Filament colour for each moderation status, so badges agree on
 * every page: green for shown, amber for waiting, red for removed.
 *
 * @internal
 */
final class StatusColour
{
    public static function for(ModerationStatus $status): string
    {
        return match ($status) {
            ModerationStatus::Pending, ModerationStatus::Failed => 'gray',
            ModerationStatus::Passed, ModerationStatus::Approved => 'success',
            ModerationStatus::Flagged => 'warning',
            ModerationStatus::Blocked, ModerationStatus::Rejected => 'danger',
        };
    }
}
