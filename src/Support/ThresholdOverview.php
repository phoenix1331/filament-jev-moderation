<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Support;

use InvalidArgumentException;
use Phoenix1331\FilamentJevModeration\Data\Calibration;
use Phoenix1331\FilamentJevModeration\Data\CalibrationBand;
use Phoenix1331\FilamentJevModeration\Data\PolicyThresholds;
use Phoenix1331\FilamentJevModeration\Enums\CalibrationAdvice;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Queries\ReviewQueueQuery;
use Phoenix1331\JevModeration\Facades\JevModeration;
use Phoenix1331\JevModeration\JevModerationManager;

/**
 * Every configured policy, plus any the calibration still has evidence
 * for, with thresholds read through the core's public API. A policy that
 * cannot be read is listed with the core's reason instead of failing the
 * page.
 *
 * @internal
 */
final readonly class ThresholdOverview
{
    public function __construct(private JevModerationPlugin $plugin) {}

    /**
     * @return list<PolicyThresholds>
     */
    public function for(Calibration $calibration): array
    {
        $names = JevModeration::policies();

        foreach ($calibration->bands as $band) {
            $names[] = $band->policy;
        }

        $models = $this->modelsByPolicy();
        $policies = [];

        foreach (array_values(array_unique($names)) as $name) {
            $bands = array_values(array_filter($calibration->bands, static fn (CalibrationBand $band): bool => $band->policy === $name));
            $policies[] = $this->policy($name, $models[$name] ?? [], $bands);
        }

        return $policies;
    }

    /**
     * @param  array<string, string>  $models
     * @param  list<CalibrationBand>  $bands
     */
    private function policy(string $name, array $models, array $bands): PolicyThresholds
    {
        try {
            // The manager behind the facade: its policy() throws for a removed or invalid policy.
            $policy = app(JevModerationManager::class)->policy($name);
        } catch (InvalidArgumentException $exception) {
            return new PolicyThresholds(name: $name, models: $models, bands: $bands, error: $exception->getMessage());
        }

        $rules = [];

        foreach (array_keys($policy->choices) as $choice) {
            if ($choice !== $policy->safeChoice) {
                $rule = $policy->rules[$choice] ?? null;
                $rules[] = ['category' => $choice, 'reviewAbove' => $rule?->reviewAbove, 'blockAbove' => $rule?->blockAbove];
            }
        }

        $commands = [];

        foreach (CalibrationAdvice::cases() as $advice) {
            foreach (array_keys($models) as $type) {
                $commands[$advice->value][] = sprintf('php artisan jev-moderation:rescan "%s" --status=%s', $type, $advice->rescanStatus());
            }
        }

        return new PolicyThresholds($name, $policy->version, $policy->uncertainBelow, $rules, $models, $bands, $commands);
    }

    /**
     * The shown model types, grouped by the policy each uses.
     *
     * @return array<string, array<string, string>> policy to morph type to label
     */
    private function modelsByPolicy(): array
    {
        $policies = [];

        foreach ((new ReviewQueueQuery($this->plugin))->modelTypes() as $type => $class) {
            $model = new $class;
            $policy = method_exists($model, 'moderationPolicy') ? $model->moderationPolicy() : null;

            if (is_string($policy)) {
                $policies[$policy][$type] = $this->plugin->getModelLabel($type);
            }
        }

        return $policies;
    }
}
