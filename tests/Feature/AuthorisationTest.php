<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\AuthorisedPage;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\AuthorisedWidget;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\DecisionPolicy;
use Phoenix1331\JevModeration\Enums\ModerationStatus;
use Phoenix1331\JevModeration\Models\ModerationDecision;

use function Pest\Livewire\livewire;

function useEnvironment(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);
}

function moderator(int $id = 1, string $email = 'mod@example.com'): GenericUser
{
    return new GenericUser(['id' => $id, 'email' => $email]);
}

function decision(ModerationStatus $status = ModerationStatus::Flagged): ModerationDecision
{
    return new ModerationDecision(['status' => $status]);
}

describe('default rule', function (): void {
    it('denies access outside the local environment', function (string $environment): void {
        useEnvironment($environment);

        expect(JevModerationPlugin::get()->isAuthorized())->toBeFalse()
            ->and(AuthorisedPage::canAccess())->toBeFalse()
            ->and(AuthorisedWidget::canView())->toBeFalse();
    })->with(['production', 'staging', 'testing']);

    it('allows access in the local environment', function (): void {
        useEnvironment('local');

        expect(JevModerationPlugin::get()->isAuthorized())->toBeTrue()
            ->and(AuthorisedPage::canAccess())->toBeTrue()
            ->and(AuthorisedWidget::canView())->toBeTrue();
    });

    it('returns 403 when mounting a page or a widget', function (): void {
        $this->actingAs(moderator());

        livewire(AuthorisedPage::class)->assertForbidden();
        livewire(AuthorisedWidget::class)->assertForbidden();
    });

    it('renders both once allowed', function (): void {
        JevModerationPlugin::get()->authorize(true);
        $this->actingAs(moderator());

        livewire(AuthorisedPage::class)->assertOk();
        livewire(AuthorisedWidget::class)->assertOk()->call('$refresh')->assertOk();
    });

    it('returns 403 on a later request once access is withdrawn', function (): void {
        JevModerationPlugin::get()->authorize(true);
        $this->actingAs(moderator());
        $widget = livewire(AuthorisedWidget::class)->assertOk();

        JevModerationPlugin::get()->authorize(false);

        $widget->call('$refresh')->assertForbidden();
    });

    it('applies the default on a host panel without the plugin', function (): void {
        Filament::setCurrentPanel(Panel::make()->id('host'));

        expect(AuthorisedWidget::canView())->toBeFalse();

        useEnvironment('local');

        expect(AuthorisedWidget::canView())->toBeTrue();
    });
});

describe('authorize()', function (): void {
    it('passes the signed-in user to the callback', function (): void {
        JevModerationPlugin::get()->authorize(
            fn (?Authenticatable $user): bool => $user instanceof GenericUser && $user->email === 'mod@example.com',
        );

        $this->actingAs(moderator());
        expect(AuthorisedPage::canAccess())->toBeTrue();

        $this->actingAs(moderator(2, 'guest@example.com'));
        expect(AuthorisedPage::canAccess())->toBeFalse();
    });

    it('injects the user by name, null when signed out', function (): void {
        JevModerationPlugin::get()->authorize(fn ($user): bool => $user !== null);

        expect(AuthorisedWidget::canView())->toBeFalse();

        $this->actingAs(moderator());
        expect(AuthorisedWidget::canView())->toBeTrue();
    });

    it('checks an explicitly given user', function (): void {
        JevModerationPlugin::get()->authorize(fn (Authenticatable $user): bool => $user->getAuthIdentifier() === 7);

        expect(JevModerationPlugin::get()->isAuthorized(moderator(7)))->toBeTrue()
            ->and(JevModerationPlugin::get()->isAuthorized(moderator(8)))->toBeFalse();
    });

    it('overrides the default rule in both directions', function (): void {
        useEnvironment('local');
        JevModerationPlugin::get()->authorize(false);
        expect(AuthorisedPage::canAccess())->toBeFalse();

        useEnvironment('production');
        JevModerationPlugin::get()->authorize(true);
        expect(AuthorisedPage::canAccess())->toBeTrue();
    });

    it('fails closed unless the callback returns exactly true', function (mixed $result): void {
        JevModerationPlugin::get()->authorize(fn (): mixed => $result);

        expect(AuthorisedPage::canAccess())->toBeFalse();
    })->with(['one' => [1], 'string' => ['yes'], 'array' => [[true]], 'null' => [null]]);
});

describe('review actions', function (): void {
    it('needs plugin access first, whatever else allows it', function (): void {
        JevModerationPlugin::get()->authorize(false)->authorizeActionsUsing(fn (): bool => true);

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Approve, decision(), moderator()))->toBeFalse();
    });

    it('allows every action to anyone with access when nothing else is set', function (ReviewAction $action): void {
        JevModerationPlugin::get()->authorize(true);

        expect(JevModerationPlugin::get()->canReview($action, decision(), moderator()))->toBeTrue();
    })->with(ReviewAction::cases());

    it('asks the callback with the action, decision and user', function (): void {
        $seen = [];
        $flagged = decision();

        JevModerationPlugin::get()->authorize(true)->authorizeActionsUsing(
            function (ReviewAction $action, ?ModerationDecision $decision, ?Authenticatable $user) use (&$seen): bool {
                $seen = [$action, $decision, $user?->getAuthIdentifier()];

                return $action !== ReviewAction::Reclassify;
            },
        );

        $this->actingAs(moderator(3));

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Approve, $flagged))->toBeTrue()
            ->and($seen)->toBe([ReviewAction::Approve, $flagged, 3])
            ->and(JevModerationPlugin::get()->canReview(ReviewAction::Reclassify, $flagged))->toBeFalse();
    });

    it('injects by type and by name, with no decision for the action as a whole', function (): void {
        JevModerationPlugin::get()->authorize(true)->authorizeActionsUsing(
            fn (ReviewAction $action, $decision): bool => $action === ReviewAction::Reject && $decision === null,
        );

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Reject))->toBeTrue()
            ->and(JevModerationPlugin::get()->canReview(ReviewAction::Reject, decision()))->toBeFalse();
    });

    it('fails closed unless the callback returns exactly true', function (mixed $result): void {
        JevModerationPlugin::get()->authorize(true)->authorizeActionsUsing(fn (): mixed => $result);

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Approve, decision(), moderator()))->toBeFalse();
    })->with([1, 'yes', null]);

    it('defers to a registered ModerationDecision policy, as Filament Shield would', function (): void {
        Gate::policy(ModerationDecision::class, DecisionPolicy::class);
        JevModerationPlugin::get()->authorize(true);
        $plugin = JevModerationPlugin::get();

        expect($plugin->canReview(ReviewAction::Approve, decision(), moderator(1)))->toBeTrue()
            ->and($plugin->canReview(ReviewAction::Approve, decision(), moderator(2)))->toBeFalse()
            ->and($plugin->canReview(ReviewAction::Reclassify, decision(), moderator(2)))->toBeTrue()
            ->and($plugin->canReview(ReviewAction::Reclassify, decision(ModerationStatus::Blocked), moderator(2)))->toBeFalse()
            ->and($plugin->canReview(ReviewAction::Reclassify, null, moderator(2)))->toBeTrue();
    });

    it('denies an ability the policy does not define', function (): void {
        Gate::policy(ModerationDecision::class, DecisionPolicy::class);
        JevModerationPlugin::get()->authorize(true);

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Reject, decision(), moderator(1)))->toBeFalse();
    });

    it('denies a signed-out user when a policy is registered', function (): void {
        Gate::policy(ModerationDecision::class, DecisionPolicy::class);
        JevModerationPlugin::get()->authorize(true);

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Approve, decision()))->toBeFalse();
    });

    it('prefers the callback over a policy', function (): void {
        Gate::policy(ModerationDecision::class, DecisionPolicy::class);
        JevModerationPlugin::get()->authorize(true)->authorizeActionsUsing(fn (): bool => true);

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Reject, decision(), moderator(2)))->toBeTrue();
    });
});
