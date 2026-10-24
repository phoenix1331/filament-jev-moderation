<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

/**
 * One bar in a result's probability list.
 *
 * @internal
 */
final readonly class CategoryProbability
{
    public function __construct(
        public string $category,
        public float $probability,
        public bool $deciding,
    ) {}
}
