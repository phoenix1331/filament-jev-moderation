<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Concerns;

use Phoenix1331\FilamentJevModeration\JevModerationPlugin;

/**
 * For moderation pages: hides them from navigation and returns 403 on mount
 * and every Livewire request unless the plugin's authorize() rule allows the user.
 */
trait AuthorisesPageAccess
{
    public static function canAccess(): bool
    {
        return JevModerationPlugin::allows();
    }
}
