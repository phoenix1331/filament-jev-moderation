# Context

**Jev Moderation for Filament** (`phoenix1331/filament-jev-moderation`) - A Filament review queue for Jev Moderation: approve, reject and reclassify flagged content, trace every decision, and see whether your thresholds match what your moderators actually decide.

This is a Composer **package**, not a Laravel app. There is no `app/`, no Docker and no database server; it is tested inside Orchestra Testbench on in-memory SQLite.

## Architecture at a glance

| Path | Role |
|---|---|
| `src/FilamentJevModerationServiceProvider.php` | Auto-discovered provider: translations, views, stylesheet |
| `src/JevModerationPlugin.php` | Filament plugin (ID `jev-moderation`): `make()`, `get()`, fluent options |
| `resources/dist/filament-jev-moderation.css` | `fi-jev-moderation-*` styles on Filament's colour variables, one sheet for 4 and 5 |
| `workbench/` | Testbench workbench: Filament panel using the plugin, seeded demo user (`composer serve`) |
| `tests/` | Pest + Testbench; `tests/Arch/` enforces strict types and no debug calls |

## Decisions

| Decision | Why |
|---|---|
| No Docker, Makefile or database server | Package development only: host PHP and Composer, Testbench on SQLite |
| `composer.lock` gitignored | Libraries resolve against the consumer's lock file; CI tests prefer-lowest and prefer-stable instead |
| Support Filament 4 and 5, not 3 | User's choice. 4 and 5 share `Filament\Schemas` and `Filament\Actions`, so there is no `Compat/` layer and no `FilamentVersion` helper; Filament 3 would need its own schema, infolist and action classes |
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
