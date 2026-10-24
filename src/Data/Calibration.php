<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

/**
 * Calibration for one Jev version: its bands, and the versions available.
 *
 * @internal
 */
final readonly class Calibration
{
    /**
     * @param  list<string>  $models  Jev versions with reviewed results, newest first
     * @param  string|null  $model  the version shown; null when nothing was reviewed
     * @param  list<CalibrationBand>  $bands  by policy, then category (low-confidence last), then band
     */
    public function __construct(
        public array $models,
        public ?string $model,
        public array $bands,
    ) {}

    /**
     * @return list<CalibrationBand>
     */
    public function flagged(): array
    {
        return array_values(array_filter($this->bands, static fn (CalibrationBand $band): bool => $band->advice !== null));
    }
}
