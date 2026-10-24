<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Phoenix1331\FilamentJevModeration\Data\CategoryProbability;
use Phoenix1331\FilamentJevModeration\Data\TimelineEntry;
use Phoenix1331\FilamentJevModeration\Support\DisplayName;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * Every decision for one record, newest first: Jev results, reports,
 * human reviews, rescans and failed checks. Two queries plus one per
 * reviewer type; reviewers whose class no longer exists, or who were
 * deleted, are shown by key.
 *
 * @internal
 */
final readonly class DecisionTimeline
{
    /**
     * @return list<TimelineEntry>
     */
    public function for(ModerationDecision $opened): array
    {
        $decisions = ModerationDecision::query()
            ->where('moderatable_type', $opened->moderatable_type)
            ->where('moderatable_id', $opened->moderatable_id)
            ->orderByDesc('id')
            ->get();

        $people = $this->people($decisions);
        $entries = [];

        foreach ($decisions as $decision) {
            $entries[] = $this->entry($decision, $people, $decision->id === $opened->id);
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $people  "type:key" to name
     */
    private function entry(ModerationDecision $decision, array $people, bool $current): TimelineEntry
    {
        $meta = $decision->meta ?? [];
        $reason = $meta['reason'] ?? null;
        $retryable = $meta['retryable'] ?? null;

        return new TimelineEntry(
            id: $decision->id,
            status: $decision->status,
            source: $decision->source,
            at: $decision->created_at,
            person: $decision->reviewed_by_type === null ? null : ($people[$decision->reviewed_by_type.':'.$decision->reviewed_by_id] ?? '#'.$decision->reviewed_by_id),
            note: $decision->note,
            model: $decision->model,
            category: $decision->category,
            confidence: $decision->confidence,
            policy: $decision->policy,
            policyVersion: $decision->policy_version,
            reason: is_string($reason) ? $reason : null,
            retryable: is_bool($retryable) ? $retryable : null,
            jevConfidence: self::number($meta['jev_confidence'] ?? null),
            probabilities: self::probabilities($meta['probabilities'] ?? null, $decision->category),
            content: $decision->content,
            current: $current,
        );
    }

    /**
     * Names of every reviewer and reporter, one query per type.
     *
     * @param  iterable<ModerationDecision>  $decisions
     * @return array<string, string> "type:key" to name
     */
    private function people(iterable $decisions): array
    {
        $keys = [];

        foreach ($decisions as $decision) {
            if ($decision->reviewed_by_type !== null && $decision->reviewed_by_id !== null) {
                $keys[$decision->reviewed_by_type][] = $decision->reviewed_by_id;
            }
        }

        $names = [];

        foreach ($keys as $type => $ids) {
            $class = Relation::getMorphedModel($type) ?? $type;

            if (! is_a($class, Model::class, true)) {
                continue;
            }

            foreach ($class::query()->whereKey(array_unique($ids))->get() as $person) {
                $names[$type.':'.DisplayName::key($person)] = DisplayName::of($person);
            }
        }

        return $names;
    }

    /**
     * @return list<CategoryProbability>
     */
    private static function probabilities(mixed $raw, ?string $deciding): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $bars = [];

        foreach ($raw as $category => $value) {
            $probability = self::number($value);

            if (is_string($category) && $probability !== null) {
                $bars[] = new CategoryProbability($category, $probability, $category === $deciding);
            }
        }

        usort($bars, static fn (CategoryProbability $a, CategoryProbability $b): int => $b->probability <=> $a->probability);

        return $bars;
    }

    /**
     * A probability from 0 to 1, or null.
     */
    private static function number(mixed $value): ?float
    {
        if (! is_int($value) && ! is_float($value)) {
            return null;
        }

        return max(0.0, min(1.0, (float) $value));
    }
}
