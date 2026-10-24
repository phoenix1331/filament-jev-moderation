<?php

declare(strict_types=1);

namespace Phoenix1331\FilamentJevModeration;

use BackedEnum;
use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Resources\Resource;
use Filament\Support\Concerns\EvaluatesClosures;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;
use Phoenix1331\FilamentJevModeration\Resources\ReviewQueueResource;
use Phoenix1331\JevModeration\Concerns\Moderatable;
use Phoenix1331\JevModeration\Models\ModerationDecision;
use UnitEnum;

class JevModerationPlugin implements Plugin
{
    use EvaluatesClosures;

    public const string ID = 'jev-moderation';

    /** Calibration highlights a band when moderators overturn more than this share. */
    public const float DEFAULT_OVERRIDE_WARNING_RATE = 0.3;

    /** Reviews a calibration band needs before it suggests anything. */
    public const int DEFAULT_MINIMUM_SAMPLE = 10;

    /** The author relation the core's moderationAuthor() reads by default. */
    public const string DEFAULT_AUTHOR_RELATION = 'user';

    protected bool|Closure|null $authorizeUsing = null;

    protected ?Closure $authorizeActionsUsing = null;

    protected string|UnitEnum|Closure|null $navigationGroup = null;

    protected bool $hasNavigationGroup = false;

    protected string|BackedEnum|Htmlable|Closure|null $navigationIcon = null;

    protected int|Closure|null $navigationSort = null;

    /** @var list<class-string<Model>>|Closure|null */
    protected array|Closure|null $models = null;

    protected bool|Closure $includeBlocked = false;

    protected float|Closure $overrideWarningRate = self::DEFAULT_OVERRIDE_WARNING_RATE;

    protected int|Closure $minimumSample = self::DEFAULT_MINIMUM_SAMPLE;

    /** @var array<class-string<Model>, string|Closure> */
    protected array $modelLabels = [];

    protected string $authorRelation = self::DEFAULT_AUTHOR_RELATION;

    /** @var array<class-string<Model>, string> */
    protected array $authorRelations = [];

    protected ?Closure $recordUrlUsing = null;

    public static function make(): static
    {
        return self::ensureInstance(app(static::class));
    }

    public static function get(): static
    {
        return self::ensureInstance(filament(self::ID));
    }

    /**
     * The plugin on the current panel, or null when that panel does not use
     * it, for example a widget placed on a host app's own dashboard.
     */
    public static function current(): ?static
    {
        if (Filament::getCurrentOrDefaultPanel()?->hasPlugin(self::ID) !== true) {
            return null;
        }

        return static::get();
    }

    /**
     * Whether the signed-in user may see moderation pages and widgets on the
     * current panel. Without the plugin there is no callback, so the default
     * rule applies.
     */
    public static function allows(): bool
    {
        return static::current()?->isAuthorized() ?? self::defaultAuthorization();
    }

    public function getId(): string
    {
        return self::ID;
    }

    /**
     * Who may use the moderation pages and widgets. The closure can take
     * `$user` (the panel's signed-in user, or null) and must return true to
     * allow access; any other value denies. Without this, access is allowed
     * only in the local environment.
     */
    public function authorize(bool|Closure $condition): static
    {
        $this->authorizeUsing = $condition;

        return $this;
    }

    public function isAuthorized(?Authenticatable $user = null): bool
    {
        if ($this->authorizeUsing === null) {
            return self::defaultAuthorization();
        }

        $user ??= Filament::auth()->user();

        return $this->evaluate(
            $this->authorizeUsing,
            namedInjections: ['user' => $user],
            typedInjections: $user === null ? [] : [Authenticatable::class => $user, $user::class => $user],
        ) === true;
    }

    /**
     * Who may approve, reject or reclassify, on top of authorize(). The
     * closure can take `$action` (ReviewAction), `$decision` (the queued
     * ModerationDecision, or null for the action as a whole) and `$user`,
     * and must return true to allow it.
     *
     * Without this, a registered ModerationDecision policy decides through
     * the Gate (abilities `approve`, `reject`, `reclassify`; a policy without
     * the method denies), so Filament Shield and hand-written policies work.
     * With neither, anyone allowed by authorize() may review.
     */
    public function authorizeActionsUsing(?Closure $callback): static
    {
        $this->authorizeActionsUsing = $callback;

        return $this;
    }

    public function canReview(ReviewAction $action, ?ModerationDecision $decision = null, ?Authenticatable $user = null): bool
    {
        $user ??= Filament::auth()->user();

        if (! $this->isAuthorized($user)) {
            return false;
        }

        if ($this->authorizeActionsUsing !== null) {
            return $this->evaluate(
                $this->authorizeActionsUsing,
                namedInjections: ['action' => $action, 'decision' => $decision, 'user' => $user],
                typedInjections: [
                    ReviewAction::class => $action,
                    ...($decision === null ? [] : [ModerationDecision::class => $decision]),
                    ...($user === null ? [] : [Authenticatable::class => $user, $user::class => $user]),
                ],
            ) === true;
        }

        if (Gate::getPolicyFor(ModerationDecision::class) === null) {
            return true;
        }

        return $user !== null && Gate::forUser($user)->allows($action->value, $decision ?? ModerationDecision::class);
    }

    /**
     * The navigation group for every moderation page. Defaults to
     * "Moderation" (translated); null lists the pages ungrouped.
     */
    public function navigationGroup(string|UnitEnum|Closure|null $group): static
    {
        $this->navigationGroup = $group;
        $this->hasNavigationGroup = true;

        return $this;
    }

    public function getNavigationGroup(): string|UnitEnum|null
    {
        if (! $this->hasNavigationGroup) {
            return __('filament-jev-moderation::navigation.group');
        }

        $group = $this->evaluate($this->navigationGroup);

        if ($group === null || is_string($group) || $group instanceof UnitEnum) {
            return $group;
        }

        throw new InvalidArgumentException('The Jev Moderation navigation group must resolve to a string, an enum or null, '.get_debug_type($group).' given.');
    }

    /**
     * One icon for every moderation page. Pages keep their own icons when unset.
     */
    public function navigationIcon(string|BackedEnum|Htmlable|Closure|null $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    public function getNavigationIcon(): string|BackedEnum|Htmlable|null
    {
        $icon = $this->evaluate($this->navigationIcon);

        if ($icon === null || is_string($icon) || $icon instanceof BackedEnum || $icon instanceof Htmlable) {
            return $icon;
        }

        throw new InvalidArgumentException('The Jev Moderation navigation icon must resolve to a string, an enum or Htmlable, '.get_debug_type($icon).' given.');
    }

    /**
     * Where the block of moderation pages sits in the navigation. Each
     * page's own sort becomes its position within the block.
     */
    public function navigationSort(int|Closure|null $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }

    public function getNavigationSort(): ?int
    {
        $sort = $this->evaluate($this->navigationSort);

        if ($sort === null || is_int($sort)) {
            return $sort;
        }

        throw new InvalidArgumentException('The Jev Moderation navigation sort must resolve to an integer or null, '.get_debug_type($sort).' given.');
    }

    /**
     * The moderated models the plugin shows. Without this, every model type
     * that has decisions is shown. Each must use the core's Moderatable trait.
     *
     * @param  array<mixed>|Closure|null  $models
     */
    public function models(array|Closure|null $models): static
    {
        $this->models = is_array($models) ? self::moderatableClasses($models) : $models;

        return $this;
    }

    /**
     * @return list<class-string<Model>>|null null when every type is shown
     */
    public function getModels(): ?array
    {
        $models = $this->evaluate($this->models);

        if ($models === null) {
            return null;
        }

        if (is_array($models)) {
            return self::moderatableClasses($models);
        }

        throw new InvalidArgumentException('Jev Moderation models must resolve to an array of model classes or null, '.get_debug_type($models).' given.');
    }

    /**
     * Also queue content Jev blocked automatically, for moderators who
     * review removals as well as flags.
     */
    public function includeBlocked(bool|Closure $condition = true): static
    {
        $this->includeBlocked = $condition;

        return $this;
    }

    public function isBlockedIncluded(): bool
    {
        $included = $this->evaluate($this->includeBlocked);

        if (is_bool($included)) {
            return $included;
        }

        throw new InvalidArgumentException('Jev Moderation includeBlocked() must resolve to a boolean, '.get_debug_type($included).' given.');
    }

    /**
     * The share of reviews overturning Jev (0 to 1) above which a
     * calibration band is highlighted. Defaults to 0.3.
     */
    public function overrideWarningRate(float|int|Closure $rate): static
    {
        $this->overrideWarningRate = $rate instanceof Closure ? $rate : self::rate($rate);

        return $this;
    }

    public function getOverrideWarningRate(): float
    {
        $rate = $this->evaluate($this->overrideWarningRate);

        if (is_float($rate) || is_int($rate)) {
            return self::rate($rate);
        }

        throw new InvalidArgumentException('The Jev Moderation override warning rate must resolve to a number, '.get_debug_type($rate).' given.');
    }

    /**
     * Reviews a calibration band needs before it is highlighted or suggests
     * a threshold change. Defaults to 10.
     */
    public function minimumSample(int|Closure $reviews): static
    {
        $this->minimumSample = $reviews instanceof Closure ? $reviews : self::sample($reviews);

        return $this;
    }

    public function getMinimumSample(): int
    {
        $reviews = $this->evaluate($this->minimumSample);

        if (is_int($reviews)) {
            return self::sample($reviews);
        }

        throw new InvalidArgumentException('The Jev Moderation minimum sample must resolve to an integer, '.get_debug_type($reviews).' given.');
    }

    /**
     * Names for model types in columns, filters and headings, keyed by
     * model class. A closure can take `$model` (the class). Types without a
     * label are named after their class ("ForumPost" becomes "Forum post").
     *
     * @param  array<mixed>  $labels
     */
    public function modelLabels(array $labels): static
    {
        $resolved = [];

        foreach ($labels as $class => $label) {
            if (! is_string($label) && ! $label instanceof Closure) {
                throw new InvalidArgumentException('Jev Moderation model labels must be strings or closures, '.get_debug_type($label).' given for ['.$class.'].');
            }

            $resolved[self::moderatableClass($class)] = $label;
        }

        $this->modelLabels = $resolved;

        return $this;
    }

    /**
     * The label for a model class or morph alias, as stored in `moderatable_type`.
     */
    public function getModelLabel(string $type): string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! array_key_exists($class, $this->modelLabels)) {
            return Str::ucfirst(Str::lower(Str::headline(class_basename($class))));
        }

        $label = $this->evaluate($this->modelLabels[$class], ['model' => $class]);

        if (is_string($label)) {
            return $label;
        }

        throw new InvalidArgumentException('The Jev Moderation label for ['.$class.'] must resolve to a string, '.get_debug_type($label).' given.');
    }

    /**
     * The BelongsTo relation that names a model's author, for author
     * history. Defaults to `user`, as the core's moderationAuthor() does.
     * Models without it show no author.
     */
    public function authorRelation(string $relation): static
    {
        $this->authorRelation = $relation;

        return $this;
    }

    /**
     * Author relations for models that do not use the default, keyed by
     * model class: `[ChatMessage::class => 'sender']`. Each must be a
     * BelongsTo relation; mistakes fail when the panel boots.
     *
     * @param  array<mixed>  $relations
     */
    public function authorRelations(array $relations): static
    {
        $resolved = [];

        foreach ($relations as $class => $relation) {
            $class = self::moderatableClass($class);

            if (! is_string($relation) || self::belongsTo(new $class, $relation) === null) {
                throw new InvalidArgumentException('The Jev Moderation author relation for ['.$class.'] must name a BelongsTo relation on it, '.(is_string($relation) ? "[{$relation}]" : get_debug_type($relation)).' given.');
            }

            $resolved[$class] = $relation;
        }

        $this->authorRelations = $resolved;

        return $this;
    }

    /**
     * The author relation of a model class or morph alias: the configured
     * one, else the default when the model has it as a BelongsTo, else null.
     *
     * @return BelongsTo<Model, Model>|null
     */
    public function getAuthorRelation(string $type): ?BelongsTo
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        return self::belongsTo(new $class, $this->authorRelations[$class] ?? $this->authorRelation);
    }

    /**
     * Where a queued record's "View" link goes. The closure can take
     * `$record` (the moderated model, loaded past the core's visibility
     * scope) and `$decision` (the queued ModerationDecision), and returns a
     * URL, or null to hide the link. It replaces the default: the current
     * panel's resource for the model, at its view page when the user may
     * view the record, else its edit page when they may edit it.
     *
     * A host resource that keeps the core's visibility scope cannot open
     * content the core hides (every queued record in `pre` mode); point the
     * link elsewhere here, or drop the scope in that resource for moderators.
     */
    public function recordUrlUsing(?Closure $callback): static
    {
        $this->recordUrlUsing = $callback;

        return $this;
    }

    public function getRecordUrl(ModerationDecision $decision): ?string
    {
        $record = $decision->getRelationValue('moderatable');

        if (! $record instanceof Model) {
            return null;
        }

        if ($this->recordUrlUsing === null) {
            return self::resourceUrl($record);
        }

        $url = $this->evaluate(
            $this->recordUrlUsing,
            namedInjections: ['record' => $record, 'decision' => $decision],
            typedInjections: [Model::class => $record, $record::class => $record, ModerationDecision::class => $decision],
        );

        if ($url === null || is_string($url)) {
            return $url === '' ? null : $url;
        }

        throw new InvalidArgumentException('The Jev Moderation record URL must resolve to a string or null, '.get_debug_type($url).' given.');
    }

    public function register(Panel $panel): void
    {
        $panel->resources([ReviewQueueResource::class]);
    }

    public function boot(Panel $panel): void
    {
        // Runs on every request the panel serves; keep it cheap.
    }

    /**
     * Deny outside local, as Horizon and Pulse do, so moderation tools and
     * the content they show are never accidentally public.
     */
    /**
     * The record's page in the current panel's resource for its model: view
     * when there is one the user may open, else edit.
     */
    private static function resourceUrl(Model $record): ?string
    {
        $resource = Filament::getCurrentOrDefaultPanel()?->getModelResource($record);

        if (! is_string($resource) || ! is_a($resource, Resource::class, true)) {
            return null;
        }

        if ($resource::hasPage('view') && $resource::canView($record)) {
            return $resource::getUrl('view', ['record' => $record]);
        }

        if ($resource::hasPage('edit') && $resource::canEdit($record)) {
            return $resource::getUrl('edit', ['record' => $record]);
        }

        return null;
    }

    private static function defaultAuthorization(): bool
    {
        return app()->environment('local');
    }

    /**
     * @return BelongsTo<Model, Model>|null
     */
    private static function belongsTo(Model $model, string $relation): ?BelongsTo
    {
        if (! method_exists($model, $relation)) {
            return null;
        }

        $instance = $model->{$relation}();

        return $instance instanceof BelongsTo ? $instance : null;
    }

    /**
     * @param  array<mixed>  $classes
     * @return list<class-string<Model>>
     */
    private static function moderatableClasses(array $classes): array
    {
        return array_values(array_unique(array_map(self::moderatableClass(...), $classes)));
    }

    /**
     * @return class-string<Model>
     */
    private static function moderatableClass(mixed $class): string
    {
        if (is_string($class) && is_a($class, Model::class, true) && in_array(Moderatable::class, class_uses_recursive($class), true)) {
            return $class;
        }

        throw new InvalidArgumentException('Jev Moderation models must be Eloquent models using the Moderatable trait, '.(is_string($class) ? "[{$class}]" : get_debug_type($class)).' given.');
    }

    private static function rate(float|int $rate): float
    {
        if ($rate < 0 || $rate > 1) {
            throw new InvalidArgumentException("The Jev Moderation override warning rate must be between 0 and 1, {$rate} given.");
        }

        return (float) $rate;
    }

    private static function sample(int $reviews): int
    {
        if ($reviews < 1) {
            throw new InvalidArgumentException("The Jev Moderation minimum sample must be at least 1, {$reviews} given.");
        }

        return $reviews;
    }

    private static function ensureInstance(mixed $plugin): static
    {
        if (! $plugin instanceof static) {
            throw new LogicException('Plugin ['.self::ID.'] is not an instance of '.static::class.'.');
        }

        return $plugin;
    }
}
