<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Phoenix1331\JevModeration\Models\ModerationDecision;

/**
 * A host app's ModerationDecision policy, as Filament Shield or a developer
 * would write it: user 1 may approve any decision, nobody may reclassify a
 * blocked one, and `reject` is left out so the Gate denies it. The Gate
 * passes no decision when asked about the action as a whole.
 */
class DecisionPolicy
{
    public function approve(Authenticatable $user, ?ModerationDecision $decision = null): bool
    {
        return $user->getAuthIdentifier() === 1;
    }

    public function reclassify(Authenticatable $user, ?ModerationDecision $decision = null): bool
    {
        return $decision?->status->value !== 'blocked';
    }
}
