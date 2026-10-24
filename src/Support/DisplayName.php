<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Support;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Model;

/**
 * How a person (an author, reviewer or reporter) is named: Filament's
 * HasName, else a non-empty `name` attribute, else `#key`. They need not
 * be panel users.
 *
 * @internal
 */
final class DisplayName
{
    public static function of(Model $model): string
    {
        if ($model instanceof HasName) {
            $name = $model->getFilamentName();

            return is_string($name) ? $name : $name->toHtml();
        }

        $name = $model->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : '#'.self::key($model);
    }

    public static function key(Model $model): string
    {
        $key = $model->getKey();

        return is_int($key) || is_string($key) ? (string) $key : '';
    }
}
