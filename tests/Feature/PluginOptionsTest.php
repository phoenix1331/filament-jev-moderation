<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\HtmlString;
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;
use Workbench\App\Models\ChatMessage;
use Workbench\App\Models\ForumPost;
use Workbench\App\Models\User;

afterEach(function (): void {
    Relation::morphMap([], merge: false);
});

describe('current()', function (): void {
    it('is the plugin on a panel that uses it', function (): void {
        expect(JevModerationPlugin::current())->toBe(JevModerationPlugin::get());
    });

    it('is null on a panel without it', function (): void {
        Filament::setCurrentPanel(Panel::make()->id('host'));

        expect(JevModerationPlugin::current())->toBeNull();
    });
});

describe('navigation', function (): void {
    it('groups pages under the translated default', function (): void {
        expect(JevModerationPlugin::make()->getNavigationGroup())->toBe('Moderation');
    });

    it('takes a group, a closure, an enum or null for ungrouped', function (mixed $group, mixed $expected): void {
        expect(JevModerationPlugin::make()->navigationGroup($group)->getNavigationGroup())->toBe($expected);
    })->with([
        'string' => ['Trust and safety', 'Trust and safety'],
        'closure' => [fn (): string => 'From a closure', 'From a closure'],
        'enum' => [Heroicon::Flag, Heroicon::Flag],
        'null' => [null, null],
    ]);

    it('rejects a group closure returning anything else', function (): void {
        JevModerationPlugin::make()->navigationGroup(fn (): int => 1)->getNavigationGroup();
    })->throws(InvalidArgumentException::class, 'navigation group must resolve to a string, an enum or null, int given');

    it('has no icon of its own until one is set', function (): void {
        expect(JevModerationPlugin::make()->getNavigationIcon())->toBeNull();
    });

    it('takes an icon as a string, an enum, Htmlable or a closure', function (mixed $icon): void {
        $resolved = JevModerationPlugin::make()->navigationIcon($icon)->getNavigationIcon();

        expect($resolved)->toBe($icon instanceof Closure ? $icon() : $icon);
    })->with([
        'string' => ['heroicon-o-shield-check'],
        'enum' => [Heroicon::ShieldCheck],
        'htmlable' => [new HtmlString('<svg></svg>')],
        'closure' => [fn (): string => 'heroicon-o-flag'],
    ]);

    it('rejects an icon closure returning anything else', function (): void {
        JevModerationPlugin::make()->navigationIcon(fn (): array => [])->getNavigationIcon();
    })->throws(InvalidArgumentException::class, 'navigation icon must resolve to a string, an enum or Htmlable, array given');

    it('takes a sort as an integer, a closure or null', function (): void {
        $plugin = JevModerationPlugin::make();

        expect($plugin->getNavigationSort())->toBeNull()
            ->and($plugin->navigationSort(20)->getNavigationSort())->toBe(20)
            ->and($plugin->navigationSort(fn (): int => 30)->getNavigationSort())->toBe(30);
    });

    it('rejects a sort closure returning anything else', function (): void {
        JevModerationPlugin::make()->navigationSort(fn (): string => 'first')->getNavigationSort();
    })->throws(InvalidArgumentException::class, 'navigation sort must resolve to an integer or null, string given');
});

describe('models()', function (): void {
    it('shows every model type by default', function (): void {
        expect(JevModerationPlugin::make()->getModels())->toBeNull();
    });

    it('keeps the given moderated models once each', function (): void {
        $plugin = JevModerationPlugin::make()->models([ForumPost::class, ChatMessage::class, ForumPost::class]);

        expect($plugin->getModels())->toBe([ForumPost::class, ChatMessage::class]);
    });

    it('evaluates a closure and validates what it returns', function (): void {
        expect(JevModerationPlugin::make()->models(fn (): array => [ChatMessage::class])->getModels())->toBe([ChatMessage::class])
            ->and(JevModerationPlugin::make()->models(fn (): null => null)->getModels())->toBeNull();
    });

    it('rejects models without the Moderatable trait when set', function (mixed $model, string $given): void {
        JevModerationPlugin::make()->models([$model]);
    })->with([
        'model without the trait' => [User::class, '['.User::class.']'],
        'not a model' => [stdClass::class, '[stdClass]'],
        'not a class name' => [42, 'int'],
    ])->throws(InvalidArgumentException::class, 'must be Eloquent models using the Moderatable trait');

    it('rejects a closure returning bad models or no list', function (): void {
        expect(fn () => JevModerationPlugin::make()->models(fn (): array => [User::class])->getModels())
            ->toThrow(InvalidArgumentException::class, 'Moderatable trait, ['.User::class.'] given')
            ->and(fn () => JevModerationPlugin::make()->models(fn (): string => ForumPost::class)->getModels())
            ->toThrow(InvalidArgumentException::class, 'must resolve to an array of model classes or null, string given');
    });
});

describe('includeBlocked()', function (): void {
    it('queues flags only by default', function (): void {
        expect(JevModerationPlugin::make()->isBlockedIncluded())->toBeFalse();
    });

    it('takes a boolean or a closure', function (): void {
        expect(JevModerationPlugin::make()->includeBlocked()->isBlockedIncluded())->toBeTrue()
            ->and(JevModerationPlugin::make()->includeBlocked(false)->isBlockedIncluded())->toBeFalse()
            ->and(JevModerationPlugin::make()->includeBlocked(fn (): bool => true)->isBlockedIncluded())->toBeTrue();
    });

    it('rejects a closure returning anything else', function (): void {
        JevModerationPlugin::make()->includeBlocked(fn (): int => 1)->isBlockedIncluded();
    })->throws(InvalidArgumentException::class, 'includeBlocked() must resolve to a boolean, int given');
});

describe('calibration options', function (): void {
    it('defaults to a 30% warning rate and 10 reviews per band', function (): void {
        $plugin = JevModerationPlugin::make();

        expect($plugin->getOverrideWarningRate())->toBe(0.3)
            ->and($plugin->getMinimumSample())->toBe(10);
    });

    it('takes a rate from 0 to 1, whole numbers included', function (): void {
        expect(JevModerationPlugin::make()->overrideWarningRate(0.25)->getOverrideWarningRate())->toBe(0.25)
            ->and(JevModerationPlugin::make()->overrideWarningRate(1)->getOverrideWarningRate())->toBe(1.0)
            ->and(JevModerationPlugin::make()->overrideWarningRate(fn (): float => 0.5)->getOverrideWarningRate())->toBe(0.5);
    });

    it('rejects a rate outside 0 to 1 when set', function (float $rate): void {
        JevModerationPlugin::make()->overrideWarningRate($rate);
    })->with([-0.1, 1.5])->throws(InvalidArgumentException::class, 'override warning rate must be between 0 and 1');

    it('rejects a rate closure returning a bad value', function (): void {
        expect(fn () => JevModerationPlugin::make()->overrideWarningRate(fn (): float => 2.0)->getOverrideWarningRate())
            ->toThrow(InvalidArgumentException::class, 'between 0 and 1, 2 given')
            ->and(fn () => JevModerationPlugin::make()->overrideWarningRate(fn (): string => 'high')->getOverrideWarningRate())
            ->toThrow(InvalidArgumentException::class, 'must resolve to a number, string given');
    });

    it('takes a minimum sample of at least 1', function (): void {
        expect(JevModerationPlugin::make()->minimumSample(25)->getMinimumSample())->toBe(25)
            ->and(JevModerationPlugin::make()->minimumSample(fn (): int => 5)->getMinimumSample())->toBe(5);
    });

    it('rejects a minimum sample below 1', function (): void {
        expect(fn () => JevModerationPlugin::make()->minimumSample(0))
            ->toThrow(InvalidArgumentException::class, 'at least 1, 0 given')
            ->and(fn () => JevModerationPlugin::make()->minimumSample(fn (): int => -1)->getMinimumSample())
            ->toThrow(InvalidArgumentException::class, 'at least 1, -1 given')
            ->and(fn () => JevModerationPlugin::make()->minimumSample(fn (): float => 1.5)->getMinimumSample())
            ->toThrow(InvalidArgumentException::class, 'must resolve to an integer, float given');
    });
});

describe('model labels', function (): void {
    it('names a type after its class in sentence case', function (): void {
        $plugin = JevModerationPlugin::make();

        expect($plugin->getModelLabel(ForumPost::class))->toBe('Forum post')
            ->and($plugin->getModelLabel(ChatMessage::class))->toBe('Chat message');
    });

    it('uses a given label or closure, which receives the class', function (): void {
        $plugin = JevModerationPlugin::make()->modelLabels([
            ForumPost::class => 'Forum reply',
            ChatMessage::class => fn (string $model): string => class_basename($model).' in game',
        ]);

        expect($plugin->getModelLabel(ForumPost::class))->toBe('Forum reply')
            ->and($plugin->getModelLabel(ChatMessage::class))->toBe('ChatMessage in game');
    });

    it('resolves morph aliases as stored in moderatable_type', function (): void {
        Relation::morphMap(['post' => ForumPost::class]);

        expect(JevModerationPlugin::make()->modelLabels([ForumPost::class => 'Post'])->getModelLabel('post'))->toBe('Post');
    });

    it('names an unknown type after its last segment', function (): void {
        expect(JevModerationPlugin::make()->getModelLabel('App\\Models\\RemovedModel'))->toBe('Removed model');
    });

    it('rejects labels for models without the trait, or labels that are not text', function (): void {
        expect(fn () => JevModerationPlugin::make()->modelLabels([User::class => 'User']))
            ->toThrow(InvalidArgumentException::class, 'Moderatable trait')
            ->and(fn () => JevModerationPlugin::make()->modelLabels([ForumPost::class => 3]))
            ->toThrow(InvalidArgumentException::class, 'must be strings or closures, int given for ['.ForumPost::class.']')
            ->and(fn () => JevModerationPlugin::make()->modelLabels([ForumPost::class => fn (): int => 3])->getModelLabel(ForumPost::class))
            ->toThrow(InvalidArgumentException::class, 'label for ['.ForumPost::class.'] must resolve to a string, int given');
    });
});

describe('author relations', function (): void {
    it('uses the user relation by default', function (): void {
        $relation = JevModerationPlugin::make()->getAuthorRelation(ForumPost::class);

        expect($relation?->getForeignKeyName())->toBe('user_id')
            ->and($relation?->getRelated())->toBeInstanceOf(User::class);
    });

    it('has no author for a model without the default relation', function (): void {
        expect(JevModerationPlugin::make()->getAuthorRelation(ChatMessage::class))->toBeNull();
    });

    it('uses a per-model relation', function (): void {
        $relation = JevModerationPlugin::make()
            ->authorRelations([ChatMessage::class => 'sender'])
            ->getAuthorRelation(ChatMessage::class);

        expect($relation?->getForeignKeyName())->toBe('sender_id');
    });

    it('changes the default relation for every model', function (): void {
        $plugin = JevModerationPlugin::make()->authorRelation('sender');

        expect($plugin->getAuthorRelation(ChatMessage::class)?->getForeignKeyName())->toBe('sender_id')
            ->and($plugin->getAuthorRelation(ForumPost::class))->toBeNull();
    });

    it('resolves morph aliases and ignores unknown types', function (): void {
        Relation::morphMap(['message' => ChatMessage::class]);

        $plugin = JevModerationPlugin::make()->authorRelations([ChatMessage::class => 'sender']);

        expect($plugin->getAuthorRelation('message')?->getForeignKeyName())->toBe('sender_id')
            ->and($plugin->getAuthorRelation('App\\Models\\RemovedModel'))->toBeNull();
    });

    it('rejects a relation that is missing or not BelongsTo when set', function (mixed $relation, string $given): void {
        expect(fn () => JevModerationPlugin::make()->authorRelations([ChatMessage::class => $relation]))
            ->toThrow(InvalidArgumentException::class, 'must name a BelongsTo relation on it, '.$given.' given');
    })->with([
        'missing method' => ['author', '[author]'],
        'not BelongsTo' => ['moderationDecisions', '[moderationDecisions]'],
        'not a name' => [1, 'int'],
    ]);

    it('rejects author relations for models without the trait', function (): void {
        JevModerationPlugin::make()->authorRelations([User::class => 'user']);
    })->throws(InvalidArgumentException::class, 'Moderatable trait');
});
