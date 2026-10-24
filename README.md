# Jev Moderation for Filament

A Filament review queue for [Jev Moderation](https://github.com/phoenix1331/laravel-jev-moderation): approve, reject and reclassify flagged content, trace every decision, and see whether your thresholds match what your moderators actually decide.

> **The Jev Moderation family:** [Jev Moderation for Laravel](https://github.com/phoenix1331/laravel-jev-moderation) classifies content with Jev and records every decision · **Jev Moderation for Filament** is where people review it (this package)

[![Tests](https://github.com/phoenix1331/filament-jev-moderation/actions/workflows/tests.yml/badge.svg)](https://github.com/phoenix1331/filament-jev-moderation/actions/workflows/tests.yml)
[![Static analysis](https://github.com/phoenix1331/filament-jev-moderation/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/phoenix1331/filament-jev-moderation/actions/workflows/static-analysis.yml)
[![Release](https://img.shields.io/github/v/release/phoenix1331/filament-jev-moderation.svg)](https://github.com/phoenix1331/filament-jev-moderation/releases)
[![Licence](https://img.shields.io/github/license/phoenix1331/filament-jev-moderation.svg)](LICENSE)

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/queue-top-dark.png">
  <img width="100%" src="https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/queue-top-light.png" alt="The review queue in a Filament panel: stat tiles for items waiting, blocks today, median time to review and override rate, above a table of flagged forum posts and chat messages with approve, reject and reclassify buttons, author, category, confidence and time waiting">
</picture>

Full queue page: [light](https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/queue-light.png) | [dark](https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/queue-dark.png). Every page and widget is in [`docs/screenshots`](https://github.com/phoenix1331/filament-jev-moderation/tree/main/docs/screenshots).

**Site:** <https://phoenix1331.github.io/filament-jev-moderation>

## Why it exists

Jev Moderation for Laravel sends content to Jev, TypeSafe's typed decision model, and gets back a probability for every category. Your thresholds then decide: pass it, flag it for a person, or block it. But thresholds are a guess until people check Jev's work, and this plugin is where they do.

- **One queue across every moderated model.** Forum posts, chat messages, profiles: anything using the core's `Moderatable` trait, most confident first, with content the site hides shown in full to the people who need to see it.
- **Every decision traceable.** Each record's history shows every Jev result with its probabilities, every report, review and rescan, and who did what. Each author's history shows their record across every model.
- **Thresholds checked against your own moderators.** Reviews are paired with the Jev results they judged and grouped by Jev's confidence. A band where moderators overturn most flags is a threshold set too low, and the plugin says which one, by how much, and which command re-checks the affected content. It never changes your config itself.
- **Writes only through the core.** Approve, reject and reclassify call the core's `approve()` and `reject()`, so its events, audit trail and policy checks apply. An architecture test fails the build on any other database write.

## Installation

Set up [Jev Moderation for Laravel](https://github.com/phoenix1331/laravel-jev-moderation#quick-start) first: the plugin reads its decisions and records reviews through it. Then:

```bash
composer require phoenix1331/filament-jev-moderation
php artisan filament:assets
```

Register the plugin on a panel and say who may use it:

```php
use Phoenix1331\FilamentJevModeration\JevModerationPlugin;

public function panel(Panel $panel): Panel
{
    return $panel
        // ...
        ->plugin(
            JevModerationPlugin::make()
                ->authorize(fn (User $user): bool => $user->can('moderate')),
        );
}
```

A **Moderation** group appears with the review queue and the thresholds page. Without `authorize()` they open in the `local` environment only.

## Usage

### The review queue

Every record whose latest decision is a flag, across every moderated model, most confident first and then longest waiting. Each row shows the content as it is now, its author, the category and where the flag came from, the confidence and how long it has waited. A user report shows its reason and the category Jev gave before it.

| Action | Does |
|---|---|
| Approve | Overturns the flag and shows the content |
| Reject | Upholds it and removes the content |
| Reclassify | Removes it under a corrected category when Jev picked the wrong one, so calibration counts it fairly |

Each works on one record or a selection across model types, with an optional note. A record that changed since the page loaded (reviewed by someone else, reported again or rescanned) is skipped, and the notification says how many. Filter by model type, category, confidence range, source and date queued.

Clicking a row opens its decision history; clicking an author opens theirs. Each record also links to its page in your own Filament resource, when there is one the moderator may view or edit.

| Decision history | Author history |
|---|---|
| <img width="420" src="https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/decision-history-light.png" alt="A chat message's history: a moderator's rejection with a note, a rescan that flagged it with every category's probability as a bar, and the original pass"> | <img width="420" src="https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/author-history-light.png" alt="An author's history: decision counts by status for the last 7 and 30 days, then every decision about their content"> |

### Calibration

Each moderator decision is paired with Jev's latest earlier result for the same record and placed in a 0.1-wide band by Jev's confidence. Per policy, category and band you see how many were checked, overturned and upheld, and what Jev missed (content it passed that a moderator removed after a report).

A band is highlighted when moderators overturned more than 30% of it and at least 10 were checked. Mostly overturned flags suggest raising `review_above` past the band, mostly overturned blocks raising `block_above`, and overturned low-confidence flags lowering `uncertain_below`. Results are for the newest Jev version, with a selector for older ones.

<img width="100%" src="https://raw.githubusercontent.com/phoenix1331/filament-jev-moderation/main/docs/screenshots/widget-calibration-table-light.png" alt="The calibration widget: moderators overturned 9 of 13 off-topic flags Jev made at 0.8 to 0.9 confidence, with the suggestion to raise review_above to 0.90">

The **Thresholds** page shows each policy's thresholds from config beside every band, and for each suggestion the command to run after you change config. It names the status the change affects, so content your moderators already approved or rejected is never re-checked:

```bash
php artisan jev-moderation:rescan "App\Models\ForumPost" --status=flagged
```

### Widgets

| Widget | Shows |
|---|---|
| `ModerationOverview` | Records waiting and the oldest wait, blocks since midnight, median time to review and the override rate over 7 days (counted as `jev-moderation:stats` counts it). Refreshes every minute |
| `DecisionVolumeChart` | Decisions per day stacked by status over 7, 30 or 90 days, in colours checked for colour vision deficiency |
| `CalibrationTable` | Every band per policy and category; under the queue, only the highlighted ones |

All three sit on the queue page. Each follows the plugin's authorisation wherever it is placed, so you can add the overview to your own dashboard:

```php
use Phoenix1331\FilamentJevModeration\Widgets\ModerationOverview;

->widgets([ModerationOverview::class])
```

## Configuration

The plugin has no config file; everything is a fluent option. Every option accepts a closure, and an invalid value fails when the panel boots.

| Option | Default | Does |
|---|---|---|
| `authorize()` | `local` only | Who may use the pages and widgets; must return exactly `true` |
| `authorizeActionsUsing()` | policy, else allowed | Who may approve, reject and reclassify (see below) |
| `models([...])` | every type with decisions | Limit the plugin to some moderated models |
| `includeBlocked()` | off | Queue automatic blocks as well as flags, so a person sees every removal |
| `modelLabels([...])` | class name in sentence case | Name model types in columns, filters and headings |
| `authorRelation()` | `user` | The `BelongsTo` relation naming each record's author |
| `authorRelations([...])` | none | Per-model exceptions, such as a chat message's `sender` |
| `recordUrlUsing()` | your resource for the model | Where a record's "View" link goes, or null to hide it |
| `overrideWarningRate()` | `0.3` | Override rate above which a band is highlighted |
| `minimumSample()` | `10` | Reviews a band needs before it can be highlighted |
| `navigationGroup()`, `navigationIcon()`, `navigationSort()` | "Moderation", each page's own icon, none | Where the pages sit; a null group lists them ungrouped |

```php
JevModerationPlugin::make()
    ->authorize(fn (User $user): bool => $user->can('moderate'))
    ->models([ForumPost::class, ChatMessage::class])
    ->includeBlocked()
    ->modelLabels([ForumPost::class => 'Forum thread'])
    ->authorRelations([ChatMessage::class => 'sender'])
    ->recordUrlUsing(fn (ForumPost $record): string => route('forum.posts.show', $record))
    ->overrideWarningRate(0.25)
    ->minimumSample(20)
    ->navigationGroup('Trust and safety')
```

### Authorisation

Two layers, both failing closed. `authorize()` decides who sees the pages and widgets: pages return 403 on every request and widgets on every update, not only in navigation. Review actions are checked on top, per record, bulk actions included, by the first of these that is set:

1. `authorizeActionsUsing()`, a callback taking `$action`, `$decision` and `$user` and returning exactly `true`
2. A `ModerationDecision` policy, checked through the Gate with the abilities `approve`, `reject` and `reclassify`, so [Filament Shield](https://github.com/bezhanSalleh/filament-shield) and hand-written policies work
3. Otherwise, anyone `authorize()` allows may review

```php
use Phoenix1331\FilamentJevModeration\Enums\ReviewAction;

JevModerationPlugin::make()
    ->authorize(fn (User $user): bool => $user->can('moderate'))
    ->authorizeActionsUsing(fn (ReviewAction $action, User $user): bool =>
        $action === ReviewAction::Approve || $user->can('remove-content'))
```

### Labels and excerpts

Every string is translatable. Publish the English files to edit them, or copy them to another locale:

```bash
php artisan vendor:publish --tag=filament-jev-moderation-translations
```

Categories come from your policies' choice keys in sentence case, so `personal_data` reads "Personal data". The queue excerpt is the content Jev checked, the model's `moderatableContent()` (by default its `#[Moderate]` fields), joined with a dot and cut at 140 characters, so put the field that identifies a record first. The history page shows every field in full.

## Compatibility

| Package | Versions |
|---|---|
| PHP | 8.3 or newer |
| Laravel | 12 or 13 |
| Filament | 4 or 5 |
| `phoenix1331/laravel-jev-moderation` | 0.2 |

CI runs the suite on every Filament major against Laravel 12 and 13, with prefer-lowest and prefer-stable dependencies, and the SQL-heavy tests on SQLite, PostgreSQL, MySQL and MariaDB.

## Testing

```bash
composer check
```

Runs Pint, Larastan at max level and the Pest suite with a 90% coverage gate. Nothing calls the Jev API. `composer serve` opens a workbench panel with 90 days of seeded history at `http://127.0.0.1:8000/_workbench`. See [CONTRIBUTING.md](CONTRIBUTING.md) for the rest.

## Security and data

The plugin reads the core's decisions and the moderated records, writes only reviews through the core, and sends nothing anywhere: no HTTP requests and no logging, both enforced by tests. Moderators see harmful content and any personal data in it, so keep `authorize()` to trained staff. [SECURITY.md](SECURITY.md) has the full scope and how to report a vulnerability.

## Links

- [Changelog](CHANGELOG.md)
- [Contributing](CONTRIBUTING.md)
- [Security policy](SECURITY.md)
- [Jev Moderation for Laravel](https://github.com/phoenix1331/laravel-jev-moderation)

## Licence

MIT. See [LICENSE](LICENSE).
