<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Support;

use Phoenix1331\FilamentJevModeration\Data\Calibration;
use Phoenix1331\FilamentJevModeration\Data\CalibrationBand;
use Phoenix1331\FilamentJevModeration\Enums\CalibrationAdvice;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use stdClass;

/**
 * Turns reviewed Jev results into confidence bands for one Jev version.
 *
 * Each result lands in the `[lower, upper)` band of its `confidence` (the
 * deciding category's probability, which thresholds compare), per policy
 * and category; 1.0 joins the top band. Low-confidence flags go in their
 * policy's own lane, banded by Jev's confidence in its answer, since their
 * category is often the safe one. Overturned = a flag or block approved,
 * upheld = rejected, missed = a pass rejected; a pass approved (a report
 * dismissed) counts nowhere. A band with at least the minimum sample whose
 * override rate is above the warning rate gets one-sided advice.
 *
 * @internal
 */
final readonly class CalibrationCalculator
{
    /** Band width in ten-thousandths, the precision confidence is stored at. */
    private const int WIDTH = 1000;

    public function __construct(
        private float $warningRate,
        private int $minimumSample,
    ) {}

    /**
     * @param  iterable<stdClass>  $pairs  rows from CalibrationPairs, newest result first
     * @param  string|null  $model  the Jev version to show; null for the newest seen
     */
    public function calculate(iterable $pairs, ?string $model = null): Calibration
    {
        $models = [];
        $rows = [];

        foreach ($pairs as $pair) {
            $version = self::text($pair->model ?? null) ?? '';
            $models[$version] = true;
            $rows[] = [$version, $pair];
        }

        $models = array_map(strval(...), array_keys($models));
        $model ??= $models[0] ?? null;

        if ($model === null || ! in_array($model, $models, true)) {
            return new Calibration($models, $model, []);
        }

        $tallies = [];

        foreach ($rows as [$version, $pair]) {
            if ($version === $model) {
                self::tally($tallies, $pair);
            }
        }

        ksort($tallies, SORT_STRING);

        return new Calibration($models, $model, array_map($this->band(...), array_values($tallies)));
    }

    /**
     * @param  array<string, array{policy: string, category: string|null, index: int, overturned: int, upheld: int, missed: int, blocked: int, reclassified: int}>  $tallies
     */
    private static function tally(array &$tallies, stdClass $pair): void
    {
        $jev = ModerationStatus::tryFrom(self::text($pair->jev_status ?? null) ?? '');
        $human = ModerationStatus::tryFrom(self::text($pair->human_status ?? null) ?? '');
        $policy = self::text($pair->policy ?? null);
        $meta = self::meta($pair->meta ?? null);
        $lowConfidence = ($meta['reason'] ?? null) === 'low_confidence';
        $category = $lowConfidence ? null : self::text($pair->category ?? null);
        $confidence = self::ratio($lowConfidence ? ($meta['jev_confidence'] ?? null) : ($pair->confidence ?? null));

        if (! in_array($jev, [ModerationStatus::Passed, ModerationStatus::Flagged, ModerationStatus::Blocked], true)
            || ! in_array($human, [ModerationStatus::Approved, ModerationStatus::Rejected], true)
            || $policy === null || $confidence === null || (! $lowConfidence && $category === null)) {
            return;
        }

        $rejected = $human === ModerationStatus::Rejected;

        if ($jev === ModerationStatus::Passed && ! $rejected) {
            return;
        }

        $index = min(9, intdiv((int) round($confidence * 10000), self::WIDTH));
        // Policy, then categories with the low-confidence lane last, then bands in order.
        $key = sprintf('%s|%s|%d', $policy, $category === null ? '~' : $category, $index);
        $tallies[$key] ??= ['policy' => $policy, 'category' => $category, 'index' => $index, 'overturned' => 0, 'upheld' => 0, 'missed' => 0, 'blocked' => 0, 'reclassified' => 0];

        if ($jev === ModerationStatus::Passed) {
            $tallies[$key]['missed']++;

            return;
        }

        $tallies[$key][$rejected ? 'upheld' : 'overturned']++;
        $tallies[$key]['blocked'] += $jev === ModerationStatus::Blocked ? 1 : 0;

        $humanCategory = self::text($pair->human_category ?? null);
        $tallies[$key]['reclassified'] += $rejected && $humanCategory !== null && $humanCategory !== $category ? 1 : 0;
    }

    /**
     * @param  array{policy: string, category: string|null, index: int, overturned: int, upheld: int, missed: int, blocked: int, reclassified: int}  $tally
     */
    private function band(array $tally): CalibrationBand
    {
        $band = new CalibrationBand(
            policy: $tally['policy'],
            category: $tally['category'],
            lower: $tally['index'] / 10,
            upper: ($tally['index'] + 1) / 10,
            overturned: $tally['overturned'],
            upheld: $tally['upheld'],
            missed: $tally['missed'],
            blocked: $tally['blocked'],
            reclassified: $tally['reclassified'],
        );

        $rate = $band->overrideRate();

        if ($rate === null || $band->sample() < $this->minimumSample || $rate <= $this->warningRate) {
            return $band;
        }

        [$advice, $threshold] = match (true) {
            $band->isLowConfidence() => [CalibrationAdvice::LowerUncertainBelow, $band->lower],
            $band->blocked * 2 > $band->sample() => [CalibrationAdvice::RaiseBlockAbove, $band->upper],
            default => [CalibrationAdvice::RaiseReviewAbove, $band->upper],
        };

        return new CalibrationBand(
            $band->policy, $band->category, $band->lower, $band->upper,
            $band->overturned, $band->upheld, $band->missed, $band->blocked, $band->reclassified,
            $advice, $threshold,
        );
    }

    /**
     * Probabilities come back as floats, or as strings from decimal columns
     * and some drivers; anything else, or outside 0 to 1, is skipped.
     */
    private static function ratio(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $value = (float) $value;

        return $value >= 0.0 && $value <= 1.0 ? $value : null;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @return array<mixed>
     */
    private static function meta(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? $decoded : [];
    }
}
