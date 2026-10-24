<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Data;

/**
 * One policy as the thresholds page shows it: its thresholds from config,
 * the shown models that use it, its calibration bands and the rescan
 * commands to run after acting on a suggestion.
 *
 * @internal
 */
final readonly class PolicyThresholds
{
    /**
     * @param  list<array{category: string, reviewAbove: float|null, blockAbove: float|null}>  $rules  every harmful choice in policy order; null thresholds never act
     * @param  array<string, string>  $models  stored morph type to label
     * @param  list<CalibrationBand>  $bands
     * @param  array<string, list<string>>  $commands  advice value to rescan commands, one per model
     * @param  string|null  $error  why the policy could not be read from config
     */
    public function __construct(
        public string $name,
        public ?string $version = null,
        public ?float $uncertainBelow = null,
        public array $rules = [],
        public array $models = [],
        public array $bands = [],
        public array $commands = [],
        public ?string $error = null,
    ) {}

    public function usedBy(): string
    {
        return $this->models === []
            ? __('filament-jev-moderation::thresholds.no_models')
            : __('filament-jev-moderation::thresholds.models', ['models' => implode(', ', $this->models)]);
    }

    /**
     * @return list<CalibrationBand>
     */
    public function flagged(): array
    {
        return array_values(array_filter($this->bands, static fn (CalibrationBand $band): bool => $band->advice !== null));
    }
}
