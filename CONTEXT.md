# Context

**Jev Moderation for Filament** (`phoenix1331/filament-jev-moderation`) is a Filament panel plugin that puts a moderation UI on top of `phoenix1331/laravel-jev-moderation`: a review queue for flagged content across every moderated model, the full decision history for a record, per-author history, and a calibration view showing how often moderators overturn Jev at each confidence level. It reads the core's `moderation_decisions` table, holds no moderation logic and stores nothing of its own; every write goes through the core's public API.

This is a Composer **package**, not a Laravel app. There is no `app/`, no Docker and no database server; it is tested inside Orchestra Testbench and previewed through the Testbench workbench, both on SQLite.

## Family

| Package | Role |
|---|---|
| `phoenix1331/laravel-jev-moderation` (`../laravel-jev-moderation`, Packagist 0.2.0) | Headless core: `Moderatable` trait, `#[Moderate]`, Jev and fake drivers, policies and thresholds, append-only `moderation_decisions`, review API, `rescan` and `stats` commands |
| `phoenix1331/filament-jev-moderation` | **This package.** Filament resource, pages and widgets over the core's decisions |
| `phoenix1331/filament-headroom` (`../filament-headroom`) | Pattern source: same headless-core-then-plugin split, plugin options, authorisation concerns, workbench, screenshots |

## Core facts the plugin relies on

Detail lives in the core's `CONTEXT.md`; these shape every query here.

| Fact | Consequence here |
|---|---|
| Append-only: every check, report, review and rescan adds a row; the latest row per record is its current status | The queue reads the latest row per `moderatable_type`/`moderatable_id`; older rows are history |
| `ModerationDecision` is public for reads; its builder throws on `update`/`delete` | The resource has no create, edit or delete pages |
| Writes: `$model->approve($moderator, $note)`, `reject(...)`, `flagForReview($reason, $reporter)` on the `Moderatable` trait | Actions call these with the panel user; nothing in `src/` writes decisions directly |
| `$decision->moderatable` applies the visibility scope, so blocked content resolves to null | Load records with `newQueryWithoutScopes()` |
| Result rows: `category` is the deciding category, `confidence` its probability; Jev's own choice, confidence and all probabilities in `meta`; answering Jev version in `model` | Calibration compares like with like and can group by Jev version |
| Human rows (`approved`, `rejected`, source `human`) carry no category or confidence; `reviewed_by` is the moderator, or the reporter on `report` rows | Calibration pairs each human row with the Jev or rescan row before it |
| Sources: `jev`, `human`, `report`, `rescan`; statuses: `pending`, `failed`, `passed`, `flagged`, `blocked`, `approved`, `rejected` | Filters and timeline labels use the core enums |
| Policies via `JevModeration::policies()` / `policy($name)` (public `Data\Policy`, `CategoryRule`) | Thresholds viewer reads config through these, never the `@internal` registry |

## Architecture at a glance

Built so far: service provider, stylesheet, plugin class, its options, authorisation, the review queue query and the review queue resource (list only). The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/FilamentJevModerationServiceProvider.php` | Auto-discovered provider: translations and views under `filament-jev-moderation`, publish tag `filament-jev-moderation-translations`, stylesheet via `FilamentAsset` |
| `src/JevModerationPlugin.php` | Filament plugin (ID `jev-moderation`): `make()`, `get()`, `current()` (null on a panel without it), static `allows()`; `authorize()` / `isAuthorized()`, `authorizeActionsUsing()` / `canReview(ReviewAction, ?decision, ?user)`; options `navigationGroup()` (default `navigation.group`, `hasNavigationGroup` so null means ungrouped), `navigationIcon()`, `navigationSort()`, `models()` (null = every type with decisions), `includeBlocked()`, `modelLabels()` (default: class basename in sentence case), `authorRelation()` / `authorRelations()` (`getAuthorRelation($type)` returns a `BelongsTo` or null), `overrideWarningRate()` (0.3), `minimumSample()` (10). Setters validate plain values; getters evaluate closures and narrow the result, throwing `InvalidArgumentException` naming the type given; morph aliases resolve through `Relation::getMorphedModel()` |
| `resources/dist/filament-jev-moderation.css` | `fi-jev-moderation-*` styles on Filament's colour variables, one sheet for 4 and 5 |
| `resources/lang/en/` | Every user-facing string |
| `src/Resources/ReviewQueueResource.php` | Resource over `ModerationDecision` at `moderation/queue`, list page `Pages/ListReviewQueue` only (create, edit, delete always false; `canViewAny()` = `JevModerationPlugin::allows()`, so a Shield `viewAny` policy does not gate it). Query: `ReviewQueueQuery`, `moderatable` eager loaded with `constrain()` (no visibility scope) and `morphWith()` author relations, `jev_category` subselect (latest earlier `jev`/`rescan` category, for reports). Default sort: null confidence last, confidence desc, `created_at`, `id`. Badge: queue count, null at zero. Row and bulk actions planned |
| `src/Concerns/HasModerationNavigation.php` | Navigation group, icon and sort from the plugin (sort = plugin base + own sort); the page's own values on a panel without the plugin |
| `src/Pages/` (planned) | Decision detail, author history, thresholds viewer |
| `src/Widgets/` (planned) | Overview stats, volume chart, calibration |
| `src/Queries/ReviewQueueQuery.php` (`@internal`) | The queue: `builder()` (latest row per record via `NOT EXISTS`, `statuses()`, live records only), `modelTypes()` (stored `moderatable_type` to class: `models()` via `getMorphClass()`, else distinct stored types whose class exists and uses `Moderatable`) |
| `src/Calibration/` (planned, `@internal`) | Calibration maths |
| `src/Concerns/AuthorisesPageAccess.php`, `AuthorisesWidgetView.php` | `canAccess()` / `canView()` from `JevModerationPlugin::allows()`; the widget concern also aborts 403 on mount and every hydrate |
| `src/Enums/ReviewAction.php` | `approve`, `reject`, `reclassify`: the actions and the Gate abilities checked on a `ModerationDecision` policy |
| `workbench/` | Testbench app: `AdminPanelProvider` with the plugin; `ForumPost` (`community`, author `user`) and `ChatMessage` (`chat`, author `sender`) with `#[Moderate]`, their migrations and factories (`about($category)` picks matching synthetic content); `DatabaseSeeder` writes 90 days of history through the core's `ModerationDecisionFactory`; `Support/DemoRandom` seeded randomness; `WorkbenchServiceProvider` sets the fake driver, `sync` and `post` mode |
| `tests/` | Pest + Testbench + `pest-plugin-livewire`; `tests/Fixtures/TestPanelProvider.php` is the default `admin` panel with fixture `AuthorisedPage` and `AuthorisedWidget`; `tests/Fixtures/DecisionPolicy.php` stands in for a Shield policy; `TestCase::migrateModerationTables()` migrates users, the core table and workbench tables with rollback; files in the `databases` group also run on PostgreSQL, MySQL and MariaDB; `tests/Arch/` enforces strict types and no debug calls |
| `.github/scripts/` | `check-archive.sh` (dist contents), `check-actions-pinned.sh` (SHA pins) |

## Decisions

| Decision | Why |
|---|---|
| UI only; reads `moderation_decisions`, writes through the core API | Moderation logic, events and the audit trail stay in one place; the plugin cannot drift from the core's rules |
| Review queue = flagged (optionally blocked) rows with no newer row for the same record (`NOT EXISTS`), restricted to live records via `whereHasMorph(..., withoutModeration())` | Starts from the `status, created_at` index; comparisons stay in SQL so stringified fetches cannot break them; the core's visibility scope would otherwise hide every queued record in `pre` mode (verified) |
| Queue excerpt = the record's current `moderatableContent()` joined with ` · `, cut to 140 characters; author name = Filament `HasName`, else `name`, else `#key` | Content is shown as it is now, which matches the decision except after `saveQuietly()` edits; authors need not be panel users |
| Reports sort after every scored row and show the category of the Jev result before them | A report has no confidence or category of its own; `CASE` puts nulls last on every database, where plain `desc` differs (PostgreSQL sorts nulls first) |
| Moderated records are loaded with `MorphTo::constrain([Type => withoutModeration()])`, never plain `with('moderatable')` | Removes only the core's visibility scope and keeps host scopes such as `SoftDeletes` |
| Approve and reject check the record's latest decision id first and skip with a notice if it moved | The core does not check current status; two moderators would otherwise append two reviews |
| Reclassify = core `reject($moderator, $note, $category)` (core 0.2.0, released) | One outcome (removal) with a corrected category; the core validates the category against the record's policy |
| Author history through each model's author relation (`BelongsTo`, default `user`, overridable per model), not core author columns | Matches the core's `moderationAuthor()` default, works on existing decisions, no schema change; revisit core columns if per-type subqueries get slow |
| Calibration pairs each human row with the latest earlier `jev`/`rescan` result; latest human row per Jev row counts; Jev passed then rejected = missed (separate); low-confidence flags banded by `meta.jev_confidence`; bands `[lower, upper)` in 0.1 steps; grouped by Jev `model` version, latest by default | Like-for-like with thresholds (core decision 1), version per core decision 3; only content over a threshold is reviewed, so suggestions are one-sided |
| Overview override rate uses the core `stats` definition exactly | The panel and CLI must agree |
| Seed history with the core's `ModerationDecisionFactory`, not `FakeDriver` | Exact confidences and back-dated timestamps; `classifyAs()` stores the category probability, not the given confidence |
| `src/` never uses `Workbench\App`, `Workbench\Database` or the tests namespace (arch test) | The plugin works with any moderated model; the workbench models exist only to demo and test two different shapes. A bare `Workbench` prefix does not match in Pest's `toUse()`, so the real namespaces are named |
| Author relations must be `BelongsTo`, checked in the setter | Author history queries the foreign key; a mistake fails at panel boot, not on the page |
| Access fails closed: `authorize()` must return exactly `true`; unset allows `local` only (as Horizon and Pulse) | Moderation shows other people's content, sometimes removed content; it must never be public by accident (brief: deny in production unless configured) |
| Review actions: plugin access, then `authorizeActionsUsing()` if set, else a registered `ModerationDecision` policy through the Gate (missing method denies, signed-out denies), else allowed | Works with Filament Shield or a hand-written policy without depending on Shield; apps with neither need no setup. The Gate gets the class name for an action as a whole, so policy methods take an optional decision |
| Test fixture widget extends `StatsOverviewWidget` | Larastan types `Widget::$view` as `view-string` and cannot see a test-only view namespace; the stats widget ships its own view |
| Slug `filament-jev-moderation`, plugin ID `jev-moderation` | The core owns `jev-moderation` for config, publish tags and commands |
| Support Filament 4 and 5, not 3 | User's choice. 4 and 5 share `Filament\Schemas` and `Filament\Actions`, so there is no `Compat/` layer and no `FilamentVersion` helper. Filament 3 could only run on Laravel 12 (security fixes only, ending early 2027) and would need its own schema, infolist and action classes. Action and form construction stays in one place so 3 could be added later |
| Testbench workbench, not the brief's `test-app/` | House pattern (as filament-headroom); `/1331-test-app` remains for a real-app check |
| Screenshots in `docs/screenshots/`, not `art/` | GitHub Pages serves `/docs` only, so the site and README share one copy |
| No VHS recordings | The plugin has no commands; install is a single `composer require` |
| Filament components first; own CSS only where Filament has no component (probability bars, calibration bands) | Brief: no compiled CSS. The hand-written sheet ships via `filament:assets`, no Tailwind build |
| Out of scope for v1: editing thresholds, real-time queue updates, moderator assignment | Brief |
| No Docker, Makefile or database server | Package development only: host PHP and Composer, Testbench on SQLite |
| `composer.lock` gitignored | Libraries resolve against the consumer's lock file; CI tests prefer-lowest and prefer-stable instead |
| Target 100/100 Plumb package health (shown on the Filament plugin directory) | Security 55%, maintenance 30%, ecosystem 15% of the score; checks listed at plumbphp.dev/checks |
| Every action pinned to a full commit SHA with a `# vX.Y.Z` comment, enforced by `.github/scripts/check-actions-pinned.sh` in CI | A tag can be moved to other code, a SHA cannot; Plumb's heaviest security check |
| Dependabot for `github-actions` only, weekly, grouped, cooldown 3 to 14 days, commit prefix `ci` with no scope (overrides the house "no Dependabot" rule) | Plumb needs an update tool covering every ecosystem in use and a cooldown of at least 3 days. Composer and npm count only when their lock files are committed, and both are gitignored. `ci: bump ...` titles pass the commit rule, which `chore(deps):` would not. Merge its PRs within 30 days (14 for security) to keep the responsiveness check passing |
| `package-lock.json` gitignored | No JS ecosystem for Dependabot to cover; Husky is the only npm dependency |
| Workflows default to `permissions: contents: read` | Least privilege; only `release.yml` writes |

## Dependencies and constraints

| Package | Constraint | Why |
|---|---|---|
| `php` | `^8.3` | `phoenix1331/laravel-jev-moderation` floor (Filament 4 and 5 allow 8.2) |
| `filament/filament` | `^4.0\|^5.0` | Both majors allow Laravel 12 and 13 at their latest releases (4.15.3, 5.10.3); 4.0.0 and 5.0.0 stop at Laravel 12, so prefer-lowest on Laravel 13 resolves a later release |
| `phoenix1331/laravel-jev-moderation` | `^0.2` | The core: decisions table, `Moderatable` trait and its review API. 0.2.0 adds `reject($moderator, $note, $category)` (Reclassify) and `stats` pairing each review with Jev's decision (upheld, overturned, missed, dismissed, unchecked), which the overview widget mirrors |
| `illuminate/*` | `^12.0\|^13.0` | Core package range |
| `orchestra/testbench` (dev) | `^10.6\|^11.3` | Laravel 12 and 13 |
| `pestphp/pest` (dev) | `^4.3\|^5.3` | Pest 5 needs PHP 8.4 and Laravel 13, so v4 covers the PHP 8.3 cells; 4.3 is the livewire plugin's floor |
| `pestphp/pest-plugin-livewire` (dev) | `^4.1\|^5.0` | Livewire 3 (Filament 4) and Livewire 4 (Filament 5); 4.0.0 required Livewire `dev-main` |
| `larastan/larastan` (dev) | `^3.13` | `level: max`, no baseline |

Verified locally: Filament 5.10.3 on Laravel 13.38 (stable), 4.13.3 on Laravel 12.69 (prefer-lowest) and 4.15.3 on Laravel 13.38, each with Pest and Larastan clean. PHP 8.3 and 8.5 cells are covered by CI only.

## Workbench

**composer**: build | serve
**login**: `/_workbench` logs in as `demo@example.com` (`testbench.yaml` `workbench.user`) and lands on `/admin`

- `testbench.yaml` lists the package provider, `WorkbenchServiceProvider` (host-app config) and `AdminPanelProvider`; `migrations:` loads the core's published migration from `vendor/` plus `workbench/database/migrations`; seeders run from `seeders:` only
- Seeded story: two moderators (Demo User, Priya Shah), eight authors with `Jordan Lee` writing most harmful content; per item a weighted scenario (passed; passed then reported and removed or dismissed; threshold flag reviewed with overturns falling steeply above the threshold; blocked with occasional appeals; low-confidence flags; failed then rescanned; rescanned after a policy change); Jev `jev-1.13.0` before day 45, `jev-1.14.0` after; most of the last three days unreviewed so the queue is never empty; one flagged post deleted to prove the queue skips it
- `DemoRandom` (`Random\Randomizer` on a seeded `Mt19937`), not `mt_rand()`: Pest's security preset bans `mt_rand`, and an instance keeps no global state. Same seed, same history (`tests/Feature/WorkbenchSeederTest.php`)
- Content is created with `createQuietly()`, so no check runs while seeding; content saved in the demo is checked inline by the fake driver
- Larastan analyses `workbench/` too: tests reuse its models

## CI

| Workflow | Jobs |
|---|---|
| `.github/workflows/tests.yml` | Pint `--test` \| Filament 4/5 x Laravel 12/13 x PHP 8.3-8.5 combinations x prefer-lowest/prefer-stable \| `databases` group on PostgreSQL 17, MySQL 8.4 and MariaDB 11 (Filament 4 + Laravel 12, Filament 5 + Laravel 13) \| coverage (pcov, `--min=90`) \| dist archive check \| pinned actions check |
| `.github/workflows/static-analysis.yml` | Larastan per Filament major |
| `.github/workflows/release.yml` | On `v*` tags: release notes from the matching `CHANGELOG.md` section |
| `.github/dependabot.yml` | Weekly grouped GitHub Actions updates, `ci:` titles |

## Local setup

- PHP 8.4 in WSL with `pcov` and `intl` (`sudo apt install php8.4-pcov php8.4-intl`)
- `osv-scanner` on the PATH for the pre-commit hook
- `npm install` once to activate the Husky hooks
- `composer install`, then `composer check` before pushing
- **composer**: lint | lint:test | analyse | test | test:coverage | check | build | serve
