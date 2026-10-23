<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Concerns;

use Phoenix1331\FilamentJevModeration\JevModerationPlugin;

/**
 * For moderation widgets: dashboards skip them unless the plugin's
 * authorize() rule allows the user. On host dashboards without the plugin,
 * the local-only default applies.
 *
 * The widget also returns 403 on mount and on every Livewire request when
 * canView() is false, so its data is protected even when mounted directly:
 * older Filament 5 releases do not check this on mount themselves.
 */
trait AuthorisesWidgetView
{
    public static function canView(): bool
    {
        return JevModerationPlugin::allows();
    }

    public function mountAuthorisesWidgetView(): void
    {
        abort_unless(static::canView(), 403);
    }

    public function hydrateAuthorisesWidgetView(): void
    {
        abort_unless(static::canView(), 403);
    }
}
