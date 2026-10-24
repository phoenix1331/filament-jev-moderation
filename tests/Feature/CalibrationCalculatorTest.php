<?php

declare(strict_types=1);

use Phoenix1331\FilamentJevModeration\Data\Calibration;
use Phoenix1331\FilamentJevModeration\Data\CalibrationBand;
use Phoenix1331\FilamentJevModeration\Enums\CalibrationAdvice;
use Phoenix1331\FilamentJevModeration\Support\CalibrationCalculator;

/**
 * A pair as a driver returns it: decimals and ids as strings, meta as JSON text.
 *
 * @param  array<string, mixed>  $meta
 */
function pair(string $jev, string $human, ?string $category, string|float|int|null $confidence, array $meta = [], string $policy = 'community', string $model = 'jev-1.14.0', ?string $humanCategory = null): stdClass
{
    return (object) [
        'jev_id' => '1',
        'jev_status' => $jev,
        'category' => $category,
        'confidence' => $confidence,
        'model' => $model,
        'policy' => $policy,
        'policy_version' => 'abc',
        'meta' => $meta === [] ? null : json_encode($meta),
        'human_status' => $human,
        'human_category' => $humanCategory,
    ];
}

/**
 * @param  list<stdClass>  $pairs
 */
function calibrate(array $pairs, float $rate = 0.3, int $sample = 10, ?string $model = null): Calibration
{
    return (new CalibrationCalculator($rate, $sample))->calculate($pairs, $model);
}

/**
 * @return list<array<string, mixed>>
 */
function summary(Calibration $calibration): array
{
    return array_map(fn (CalibrationBand $band): array => [
        'band' => "{$band->policy}/".($band->category ?? 'low confidence')." {$band->lower}-{$band->upper}",
        'overturned' => $band->overturned,
        'upheld' => $band->upheld,
        'missed' => $band->missed,
        'sample' => $band->sample(),
        'advice' => $band->advice?->value,
        'threshold' => $band->threshold,
    ], $calibration->bands);
}

/**
 * @return list<stdClass>
 */
function repeatPair(int $times, stdClass $pair): array
{
    return array_fill(0, $times, $pair);
}

describe('counting', function (): void {
    it('counts overturned, upheld and missed per band with exact figures', function (): void {
        $calibration = calibrate([
            pair('flagged', 'approved', 'harassment', '0.4500'),
            pair('flagged', 'approved', 'harassment', '0.4999'),
            pair('flagged', 'rejected', 'harassment', '0.4000'),
            pair('flagged', 'rejected', 'harassment', '0.5000'),
            pair('blocked', 'rejected', 'harassment', '0.9700'),
            pair('passed', 'rejected', 'harassment', '0.3100'),
            pair('passed', 'approved', 'harassment', '0.2000'),
            pair('flagged', 'approved', 'spam', '0.6200'),
        ]);

        expect(summary($calibration))->toBe([
            ['band' => 'community/harassment 0.3-0.4', 'overturned' => 0, 'upheld' => 0, 'missed' => 1, 'sample' => 0, 'advice' => null, 'threshold' => null],
            ['band' => 'community/harassment 0.4-0.5', 'overturned' => 2, 'upheld' => 1, 'missed' => 0, 'sample' => 3, 'advice' => null, 'threshold' => null],
            ['band' => 'community/harassment 0.5-0.6', 'overturned' => 0, 'upheld' => 1, 'missed' => 0, 'sample' => 1, 'advice' => null, 'threshold' => null],
            ['band' => 'community/harassment 0.9-1', 'overturned' => 0, 'upheld' => 1, 'missed' => 0, 'sample' => 1, 'advice' => null, 'threshold' => null],
            ['band' => 'community/spam 0.6-0.7', 'overturned' => 1, 'upheld' => 0, 'missed' => 0, 'sample' => 1, 'advice' => null, 'threshold' => null],
        ])
            ->and($calibration->bands[1]->overrideRate())->toBe(2 / 3)
            ->and($calibration->bands[0]->overrideRate())->toBeNull();
    });

    it('puts each edge in the band above it, and 1.0 in the top band', function (string|float $confidence, float $lower): void {
        $band = calibrate([pair('flagged', 'approved', 'spam', $confidence)])->bands[0];

        expect($band->lower)->toBe($lower);
    })->with([
        ['0.5000', 0.5],
        ['0.4999', 0.4],
        ['0.7000', 0.7],
        [0.7, 0.7],
        [0.3, 0.3],
        ['0.0000', 0.0],
        ['0.9999', 0.9],
        ['1.0000', 0.9],
        [1, 0.9],
    ]);

    it('bands low-confidence flags in their own lane by Jev\'s confidence', function (): void {
        $calibration = calibrate([
            pair('flagged', 'approved', 'fine', '0.2200', ['reason' => 'low_confidence', 'jev_confidence' => 0.22]),
            pair('flagged', 'rejected', 'spam', '0.2000', ['reason' => 'low_confidence', 'jev_confidence' => 0.27]),
            pair('flagged', 'approved', 'spam', '0.6100', ['reason' => 'review_threshold', 'jev_confidence' => 0.61]),
        ]);

        expect(summary($calibration))->toBe([
            ['band' => 'community/spam 0.6-0.7', 'overturned' => 1, 'upheld' => 0, 'missed' => 0, 'sample' => 1, 'advice' => null, 'threshold' => null],
            ['band' => 'community/low confidence 0.2-0.3', 'overturned' => 1, 'upheld' => 1, 'missed' => 0, 'sample' => 2, 'advice' => null, 'threshold' => null],
        ])
            ->and($calibration->bands[1]->isLowConfidence())->toBeTrue();
    });

    it('keeps policies apart, since each has its own thresholds', function (): void {
        $calibration = calibrate([
            pair('flagged', 'approved', 'harassment', '0.5500', policy: 'community'),
            pair('flagged', 'approved', 'harassment', '0.5500', policy: 'chat'),
        ]);

        expect(array_map(fn (CalibrationBand $band): string => $band->policy, $calibration->bands))->toBe(['chat', 'community']);
    });

    it('counts blocks and reclassifications among the checked', function (): void {
        $band = calibrate([
            pair('blocked', 'approved', 'spam', '0.9500'),
            pair('blocked', 'rejected', 'spam', '0.9500', humanCategory: 'harassment'),
            pair('flagged', 'rejected', 'spam', '0.9100', humanCategory: 'spam'),
        ])->bands[0];

        expect($band->blocked)->toBe(2)
            ->and($band->reclassified)->toBe(1)
            ->and($band->sample())->toBe(3);
    });

    it('skips rows it cannot read', function (stdClass $pair): void {
        expect(calibrate([$pair])->bands)->toBe([]);
    })->with([
        'unknown Jev status' => [pair('pending', 'approved', 'spam', '0.5')],
        'unknown review status' => [pair('flagged', 'human', 'spam', '0.5')],
        'no policy' => [pair('flagged', 'approved', 'spam', '0.5', policy: '')],
        'no confidence' => [pair('flagged', 'approved', 'spam', null)],
        'confidence not a number' => [pair('flagged', 'approved', 'spam', 'high')],
        'confidence above 1' => [pair('flagged', 'approved', 'spam', '1.2')],
        'no category' => [pair('flagged', 'approved', null, '0.5')],
        'low confidence without Jev confidence' => [pair('flagged', 'approved', 'fine', '0.2', ['reason' => 'low_confidence'])],
        'review that is not approve or reject' => [pair('flagged', 'pending', 'spam', '0.5')],
    ]);

    it('treats meta that is not JSON as no meta', function (): void {
        $pair = pair('flagged', 'approved', 'fine', '0.2');
        $pair->meta = '{not json';

        expect(calibrate([$pair])->bands[0]->isLowConfidence())->toBeFalse();
    });

    it('reads meta given as an array too', function (): void {
        $pair = pair('flagged', 'approved', 'fine', '0.2');
        $pair->meta = ['reason' => 'low_confidence', 'jev_confidence' => '0.35'];

        expect(calibrate([$pair])->bands[0]->lower)->toBe(0.3)
            ->and(calibrate([$pair])->bands[0]->isLowConfidence())->toBeTrue();
    });
});

describe('advice', function (): void {
    it('suggests raising review_above past a band overturned above the warning rate', function (): void {
        $calibration = calibrate([
            ...repeatPair(4, pair('flagged', 'approved', 'harassment', '0.4400')),
            ...repeatPair(6, pair('flagged', 'rejected', 'harassment', '0.4600')),
        ], rate: 0.3, sample: 10);

        expect(summary($calibration))->toBe([
            ['band' => 'community/harassment 0.4-0.5', 'overturned' => 4, 'upheld' => 6, 'missed' => 0, 'sample' => 10, 'advice' => 'raise_review_above', 'threshold' => 0.5],
        ])
            ->and($calibration->flagged())->toHaveCount(1);
    });

    it('stays quiet below the minimum sample or at the warning rate', function (int $overturned, int $upheld, float $rate, int $sample): void {
        $calibration = calibrate([
            ...repeatPair($overturned, pair('flagged', 'approved', 'spam', '0.55')),
            ...repeatPair($upheld, pair('flagged', 'rejected', 'spam', '0.55')),
        ], rate: $rate, sample: $sample);

        expect($calibration->bands[0]->advice)->toBeNull()
            ->and($calibration->flagged())->toBe([]);
    })->with([
        'one short of the sample' => [5, 4, 0.3, 10],
        'exactly at the rate' => [3, 7, 0.3, 10],
        'no overrides' => [0, 12, 0.0, 10],
    ]);

    it('suggests raising block_above when most of the band was blocked', function (): void {
        $band = calibrate([
            ...repeatPair(3, pair('blocked', 'approved', 'spam', '0.9200')),
            ...repeatPair(1, pair('flagged', 'rejected', 'spam', '0.9100')),
        ], sample: 4)->bands[0];

        expect($band->advice)->toBe(CalibrationAdvice::RaiseBlockAbove)
            ->and($band->threshold)->toBe(1.0);
    });

    it('suggests raising review_above when blocks are only half the band', function (): void {
        $band = calibrate([
            ...repeatPair(2, pair('blocked', 'approved', 'spam', '0.9200')),
            ...repeatPair(2, pair('flagged', 'approved', 'spam', '0.9100')),
        ], sample: 4)->bands[0];

        expect($band->advice)->toBe(CalibrationAdvice::RaiseReviewAbove);
    });

    it('suggests lowering uncertain_below to an overturned low-confidence band', function (): void {
        $band = calibrate(repeatPair(5, pair('flagged', 'approved', 'fine', '0.25', ['reason' => 'low_confidence', 'jev_confidence' => 0.25])), sample: 5)->bands[0];

        expect($band->advice)->toBe(CalibrationAdvice::LowerUncertainBelow)
            ->and($band->threshold)->toBe(0.2);
    });

    it('never counts missed results towards the sample or the rate', function (): void {
        $band = calibrate([
            ...repeatPair(10, pair('passed', 'rejected', 'spam', '0.45')),
            pair('flagged', 'rejected', 'spam', '0.45'),
        ], sample: 1)->bands[0];

        expect($band->missed)->toBe(10)
            ->and($band->sample())->toBe(1)
            ->and($band->overrideRate())->toBe(0.0)
            ->and($band->advice)->toBeNull();
    });
});

describe('Jev versions', function (): void {
    it('shows the newest version by default and lists every version seen, newest first', function (): void {
        $calibration = calibrate([
            pair('flagged', 'approved', 'spam', '0.55', model: 'jev-1.15.0'),
            pair('flagged', 'approved', 'spam', '0.55', model: 'jev-1.14.0'),
            pair('flagged', 'rejected', 'spam', '0.65', model: 'jev-1.14.0'),
        ]);

        expect($calibration->models)->toBe(['jev-1.15.0', 'jev-1.14.0'])
            ->and($calibration->model)->toBe('jev-1.15.0')
            ->and(summary($calibration))->toHaveCount(1);
    });

    it('shows an older version on request, never mixing versions', function (): void {
        $calibration = calibrate([
            pair('flagged', 'approved', 'spam', '0.55', model: 'jev-1.15.0'),
            pair('flagged', 'rejected', 'spam', '0.55', model: 'jev-1.14.0'),
        ], model: 'jev-1.14.0');

        expect($calibration->model)->toBe('jev-1.14.0')
            ->and($calibration->bands[0]->upheld)->toBe(1)
            ->and($calibration->bands[0]->overturned)->toBe(0);
    });

    it('has no bands for an unknown version or when nothing was reviewed', function (): void {
        expect(calibrate([pair('flagged', 'approved', 'spam', '0.55')], model: 'jev-0.1')->bands)->toBe([])
            ->and(calibrate([]))->toEqual(new Calibration([], null, []));
    });

    it('groups results with no version together', function (): void {
        $calibration = calibrate([pair('flagged', 'approved', 'spam', '0.55', model: '')]);

        expect($calibration->models)->toBe([''])
            ->and($calibration->bands)->toHaveCount(1);
    });
});
