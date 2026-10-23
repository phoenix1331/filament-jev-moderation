# Context

**Jev Moderation for Filament** (`phoenix1331/filament-jev-moderation`) is a Filament panel plugin that puts a moderation UI on top of `phoenix1331/laravel-jev-moderation`: a review queue for flagged content across every moderated model, the full decision history for a record, per-author history, and a calibration view showing how often moderators overturn Jev at each confidence level. It reads the core's `moderation_decisions` table, holds no moderation logic and stores nothing of its own; every write goes through the core's public API.

This is a Composer **package**, not a Laravel app. There is no `app/`, no Docker and no database server; it is tested inside Orchestra Testbench and previewed through the Testbench workbench, both on SQLite.

## Family

| Package | Role |
|---|---|
| `phoenix1331/laravel-jev-moderation` (`../laravel-jev-moderation`, Packagist 0.1.0) | Headless core: `Moderatable` trait, `#[Moderate]`, Jev and fake drivers, policies and thresholds, append-only `moderation_decisions`, review API, `rescan` and `stats` commands |
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

Built so far: service provider, plugin class and stylesheet from the skeleton. The rest is the target layout from the brief.

| Path | Role |
|---|---|
| `src/FilamentJevModerationServiceProvider.php` | Auto-discovered provider: translations and views under `filament-jev-moderation`, publish tag `filament-jev-moderation-translations`, stylesheet via `FilamentAsset` |
| `src/JevModerationPlugin.php` | Filament plugin (ID `jev-moderation`): `make()`, `get()`, fluent options, registers the resource, pages and widgets |
| `resources/dist/filament-jev-moderation.css` | `fi-jev-moderation-*` styles on Filament's colour variables, one sheet for 4 and 5 |
| `resources/lang/en/` | Every user-facing string |
| `src/Resources/` (planned) | Review queue resource over `ModerationDecision`: list page, row and bulk actions |
| `src/Pages/` (planned) | Decision detail, author history, thresholds viewer |
| `src/Widgets/` (planned) | Overview stats, volume chart, calibration |
| `src/Queries/`, `src/Calibration/` (planned, `@internal`) | Latest-decision query, calibration maths |
| `src/Concerns/` (planned) | Page and widget authorisation, navigation from plugin options |
| `workbench/` | Testbench app: `AdminPanelProvider` with the plugin, demo user; moderated fixture models and seeded decisions to come (`composer serve`) |
| `tests/` | Pest + Testbench + `pest-plugin-livewire`; `tests/Fixtures/TestPanelProvider.php` is the default `admin` panel; `tests/Arch/` enforces strict types and no debug calls |
| `.github/scripts/` | `check-archive.sh` (dist contents), `check-actions-pinned.sh` (SHA pins) |

## Decisions

| Decision | Why |
|---|---|
| UI only; reads `moderation_decisions`, writes through the core API | Moderation logic, events and the audit trail stay in one place; the plugin cannot drift from the core's rules |
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
| `phoenix1331/laravel-jev-moderation` | `^0.1` | The core: decisions table, `Moderatable` trait and its review API |
| `illuminate/*` | `^12.0\|^13.0` | Core package range |
| `orchestra/testbench` (dev) | `^10.6\|^11.3` | Laravel 12 and 13 |
| `pestphp/pest` (dev) | `^4.3\|^5.3` | Pest 5 needs PHP 8.4 and Laravel 13, so v4 covers the PHP 8.3 cells; 4.3 is the livewire plugin's floor |
| `pestphp/pest-plugin-livewire` (dev) | `^4.1\|^5.0` | Livewire 3 (Filament 4) and Livewire 4 (Filament 5); 4.0.0 required Livewire `dev-main` |
| `larastan/larastan` (dev) | `^3.13` | `level: max`, no baseline |

Verified locally: Filament 5.10.3 on Laravel 13.38 (stable), 4.13.3 on Laravel 12.69 (prefer-lowest) and 4.15.3 on Laravel 13.38, each with Pest and Larastan clean. PHP 8.3 and 8.5 cells are covered by CI only.

## Workbench

**composer**: build | serve
**login**: `/_workbench` logs in as `demo@example.com` (`testbench.yaml` `workbench.user`) and lands on `/admin`

- `testbench.yaml` lists the package provider, `WorkbenchServiceProvider` (host-app config) and `AdminPanelProvider`; seeders run from `seeders:` only
- Seeder randomness fixed with `mt_srand(1331)` so screenshots only change with the UI

## CI

| Workflow | Jobs |
|---|---|
| `.github/workflows/tests.yml` | Pint `--test` \| Filament 4/5 x Laravel 12/13 x PHP 8.3-8.5 combinations x prefer-lowest/prefer-stable \| coverage (pcov, `--min=90`) \| dist archive check \| pinned actions check |
| `.github/workflows/static-analysis.yml` | Larastan per Filament major |
| `.github/workflows/release.yml` | On `v*` tags: release notes from the matching `CHANGELOG.md` section |
| `.github/dependabot.yml` | Weekly grouped GitHub Actions updates, `ci:` titles |

## Local setup

- PHP 8.4 in WSL with `pcov` and `intl` (`sudo apt install php8.4-pcov php8.4-intl`)
- `osv-scanner` on the PATH for the pre-commit hook
- `npm install` once to activate the Husky hooks
- `composer install`, then `composer check` before pushing
- **composer**: lint | lint:test | analyse | test | test:coverage | check | build | serve
