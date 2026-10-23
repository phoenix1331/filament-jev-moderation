<?php

declare(strict_types=1);

namespace Workbench\App\Support;

use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Seeded randomness shared by the demo seeder and factories, so every
 * workbench build is identical and screenshots only change with the UI.
 */
final class DemoRandom
{
    private static ?Randomizer $randomizer = null;

    public static function seed(int $seed): void
    {
        self::$randomizer = new Randomizer(new Mt19937($seed));
    }

    public static function int(int $min, int $max): int
    {
        self::$randomizer ??= new Randomizer(new Mt19937(1331));

        return self::$randomizer->getInt($min, $max);
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $options
     * @return T
     */
    public static function pick(array $options): mixed
    {
        return $options[self::int(0, count($options) - 1)];
    }
}
