<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Concerns;

use BackedEnum;
use Illuminate\Contracts\Support\Htmlable;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use UnitEnum;

/**
 * For moderation pages and resources: navigation group, icon and sort come
 * from the plugin options. The page's own $navigationSort is its position
 * within the block.
 */
trait HasModerationNavigation
{
    public static function getNavigationGroup(): string|UnitEnum|null
    {
        $plugin = JevModerationPlugin::current();

        if ($plugin === null) {
            return parent::getNavigationGroup();
        }

        return $plugin->getNavigationGroup();
    }

    public static function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        return JevModerationPlugin::current()?->getNavigationIcon() ?? parent::getNavigationIcon();
    }

    public static function getNavigationSort(): ?int
    {
        $base = JevModerationPlugin::current()?->getNavigationSort();

        if ($base === null) {
            return parent::getNavigationSort();
        }

        return $base + (parent::getNavigationSort() ?? 0);
    }
}
