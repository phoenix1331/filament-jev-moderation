<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Features\SupportTesting\Testable;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Phoenix1331\FilamentJevModeration\Pages\Thresholds;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\AuthorHistory;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ListReviewQueue;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource\Pages\ViewDecisionHistory;
use Phoenix1331\FilamentJevModeration\Review\QueueReviewer;
use Phoenix1331\FilamentJevModeration\Tests\Fixtures\DecisionPolicy;
use Phoenix1331\FilamentJevModeration\Widgets\CalibrationTable;
use Phoenix1331\FilamentJevModeration\Widgets\DecisionVolumeChart;
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;
use Phoenix1331\JevModeration\Enums\ModerationSource;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

use function Pest\Livewire\livewire;

/*
 * Every page, widget, row action and bulk action the plugin ships, checked
 * end to end against each authorisation rule. AuthorisationTest covers the
 * rules themselves; this proves each surface enforces them.
 */

beforeEach(function (): void {
    $this->migrateModerationTables();

    // A fresh plugin with no authorize() call: the default rule applies.
    $this->app->forgetInstance(JevModerationPlugin::class);
    Filament\Facades\Filament::getCurrentOrDefaultPanel()?->plugin(JevModerationPlugin::make());

    $this->moderator = User::factory()->create();
    $this->actingAs($this->moderator);

    $this->author = User::factory()->create();
    $this->flag = decisionFor(ForumPost::factory()->for($this->author)->createQuietly())->flagged()->create();
});

// The table rollback at teardown would ask for confirmation in production.
afterEach(function (): void {
    runningIn('testing');
});

function runningIn(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);
}

/**
 * @return array<string, Closure(): Testable>
 */
function everyPage(): array
{
    return [
        'review queue' => fn (): Testable => livewire(ListReviewQueue::class),
        'decision history' => fn (): Testable => livewire(ViewDecisionHistory::class, ['record' => test()->flag->getKey()]),
        'author history' => fn (): Testable => Livewire\Livewire::withQueryParams(['type' => test()->author->getMorphClass()])
            ->test(AuthorHistory::class, ['author' => test()->author->getKey()]),
        'thresholds' => fn (): Testable => livewire(Thresholds::class),
    ];
}

/**
 * @return array<string, class-string>
 */
function everyWidget(): array
{
    return [
        'overview' => ModerationOverview::class,
        'volume chart' => DecisionVolumeChart::class,
        'calibration' => CalibrationTable::class,
    ];
}

function humanDecisions(): int
{
    return ModerationDecision::query()->where('source', ModerationSource::Human)->count();
}

describe('default rule outside local', function (): void {
    it('denies every page and widget', function (string $environment): void {
        runningIn($environment);

        foreach (everyPage() as $page => $open) {
            $open()->assertForbidden();
        }

        foreach (everyWidget() as $widget) {
            expect($widget::canView())->toBeFalse();
            livewire($widget)->assertForbidden();
        }

        expect(ReviewQueueResource::canAccess())->toBeFalse()
            ->and(Thresholds::canAccess())->toBeFalse()
            ->and(ReviewQueueResource::canView($this->flag))->toBeFalse();
    })->with(['production', 'staging', 'testing']);

    it('hides the moderation navigation, which local shows', function (string $environment, bool $shown): void {
        runningIn($environment);

        $items = collect(Filament\Facades\Filament::getCurrentOrDefaultPanel()?->getNavigation() ?? [])
            ->flatMap(fn ($group): iterable => $group->getItems())
            ->map(fn ($item): string => $item->getLabel());

        expect($items->contains('Review queue'))->toBe($shown)
            ->and($items->contains('Thresholds'))->toBe($shown);
    })->with([
        'production' => ['production', false],
        'local' => ['local', true],
    ]);

    it('denies every review action, even called directly', function (): void {
        runningIn('production');

        foreach (ReviewAction::cases() as $action) {
            expect(JevModerationPlugin::get()->canReview($action, $this->flag, $this->moderator))->toBeFalse();
        }

        $outcome = (new QueueReviewer(JevModerationPlugin::get()))
            ->review([$this->flag], ReviewAction::Approve, user: $this->moderator);

        expect($outcome->skipped)->toBe(1)
            ->and(humanDecisions())->toBe(0);
    });
});

describe('default rule in local', function (): void {
    it('allows every page and widget', function (): void {
        runningIn('local');

        foreach (everyPage() as $open) {
            $open()->assertOk();
        }

        foreach (everyWidget() as $widget) {
            livewire($widget)->assertOk();
        }
    });

    it('allows every review action', function (): void {
        runningIn('local');

        livewire(ListReviewQueue::class)
            ->assertTableActionVisible('approve', $this->flag)
            ->assertTableActionVisible('reject', $this->flag)
            ->assertTableActionVisible('reclassify', $this->flag)
            ->assertTableBulkActionVisible('approve_selected')
            ->assertTableBulkActionVisible('reject_selected')
            ->callTableAction('approve', $this->flag);

        expect(humanDecisions())->toBe(1);
    });
});

describe('authorize() must return exactly true', function (): void {
    it('denies every page and widget for a truthy value that is not true', function (mixed $result): void {
        runningIn('local');
        JevModerationPlugin::get()->authorize(fn (): mixed => $result);

        foreach (everyPage() as $open) {
            $open()->assertForbidden();
        }

        foreach (everyWidget() as $widget) {
            livewire($widget)->assertForbidden();
        }
    })->with([
        'string' => ['yes'],
        'integer' => [1],
        'array' => [[true]],
        'object' => [new stdClass],
    ]);

    it('allows every page and widget for true, even in production', function (): void {
        runningIn('production');
        JevModerationPlugin::get()->authorize(fn (): bool => true);

        foreach (everyPage() as $open) {
            $open()->assertOk();
        }

        foreach (everyWidget() as $widget) {
            livewire($widget)->assertOk();
        }
    });

    it('checks again on every Livewire request after access is withdrawn', function (): void {
        JevModerationPlugin::get()->authorize(true);

        $pages = array_map(fn (Closure $open): Testable => $open()->assertOk(), everyPage());
        $widgets = array_map(fn (string $widget): Testable => livewire($widget)->assertOk(), everyWidget());

        JevModerationPlugin::get()->authorize(false);

        foreach ([...$pages, ...$widgets] as $component) {
            $component->call('$refresh')->assertForbidden();
        }
    });
});

describe('per-action rule with access allowed', function (): void {
    beforeEach(function (): void {
        JevModerationPlugin::get()->authorize(true);
    });

    it('hides every review action while the pages stay open', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (): bool => false);

        livewire(ListReviewQueue::class)
            ->assertOk()
            ->assertTableActionHidden('approve', $this->flag)
            ->assertTableActionHidden('reject', $this->flag)
            ->assertTableActionHidden('reclassify', $this->flag)
            ->assertTableBulkActionHidden('approve_selected')
            ->assertTableBulkActionHidden('reject_selected');

        foreach (everyPage() as $open) {
            $open()->assertOk();
        }
    });

    // Each direct call runs once allowed (proving the call reaches the action) and once denied.
    it('records nothing when a hidden action is called directly over Livewire', function (string $action, array $data, bool $allowed): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (): bool => $allowed);

        livewire(ListReviewQueue::class)
            ->call('mountAction', $action, [], ['table' => true, 'recordKey' => (string) $this->flag->getKey()])
            ->set('mountedActions.0.data', $data)
            ->call('callMountedAction');

        expect(humanDecisions())->toBe($allowed ? 1 : 0);
    })->with([
        'approve' => ['approve', []],
        'reject' => ['reject', []],
        'reclassify' => ['reclassify', ['category' => 'hate']],
    ])->with(['allowed' => true, 'denied' => false]);

    it('records nothing when a hidden bulk action is called directly over Livewire', function (string $action, bool $allowed): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (): bool => $allowed);

        livewire(ListReviewQueue::class)
            ->set('selectedTableRecords', [(string) $this->flag->getKey()])
            ->call('mountAction', $action, [], ['table' => true, 'bulk' => true])
            ->call('callMountedAction');

        expect(humanDecisions())->toBe($allowed ? 1 : 0);
    })->with(['approve_selected', 'reject_selected'])->with(['allowed' => true, 'denied' => false]);

    it('denies one action and allows the others', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (ReviewAction $action): bool => $action === ReviewAction::Reject);

        livewire(ListReviewQueue::class)
            ->assertTableActionHidden('approve', $this->flag)
            ->assertTableActionVisible('reject', $this->flag)
            ->assertTableActionHidden('reclassify', $this->flag)
            ->assertTableBulkActionHidden('approve_selected')
            ->assertTableBulkActionVisible('reject_selected')
            ->callTableAction('reject', $this->flag);

        expect(ModerationDecision::query()->where('source', ModerationSource::Human)->sole()->status->value)->toBe('rejected');
    });

    it('keeps the view link, which only needs page access', function (): void {
        JevModerationPlugin::get()->authorizeActionsUsing(fn (): bool => false)->recordUrlUsing(fn (): string => 'https://forum.test/post');

        livewire(ListReviewQueue::class)->assertTableActionVisible('view_record', $this->flag);
    });
});

describe('a registered ModerationDecision policy (the Filament Shield path)', function (): void {
    beforeEach(function (): void {
        JevModerationPlugin::get()->authorize(true)->includeBlocked();
        Gate::policy(ModerationDecision::class, DecisionPolicy::class);
    });

    it('lets the policy decide each action for the signed-in user', function (): void {
        // DecisionPolicy: user 1 may approve; reject is missing, so the Gate denies it.
        expect($this->moderator->getKey())->toBe(1);

        livewire(ListReviewQueue::class)
            ->assertTableActionVisible('approve', $this->flag)
            ->assertTableActionHidden('reject', $this->flag)
            ->assertTableBulkActionVisible('approve_selected')
            ->assertTableBulkActionHidden('reject_selected')
            ->callTableAction('approve', $this->flag);

        expect(humanDecisions())->toBe(1);
    });

    it('denies a user the policy does not allow', function (): void {
        $this->actingAs($this->author);

        livewire(ListReviewQueue::class)
            ->assertTableActionHidden('approve', $this->flag)
            ->assertTableBulkActionHidden('approve_selected');
    });

    it('decides per record', function (): void {
        // DecisionPolicy: nobody may reclassify a blocked decision.
        $blocked = decisionFor(ForumPost::factory()->createQuietly())->blocked()->create();

        livewire(ListReviewQueue::class)
            ->assertTableActionVisible('reclassify', $this->flag)
            ->assertTableActionHidden('reclassify', $blocked);
    });

    it('never widens access: the pages still need authorize()', function (): void {
        JevModerationPlugin::get()->authorize(false);

        foreach (everyPage() as $open) {
            $open()->assertForbidden();
        }

        expect(JevModerationPlugin::get()->canReview(ReviewAction::Approve, $this->flag, $this->moderator))->toBeFalse();
    });
});
