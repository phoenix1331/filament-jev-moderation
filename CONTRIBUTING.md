# Contributing to Jev Moderation for Filament

Thanks for helping. Bug reports, fixes, translations and new features are all welcome.

Please follow the [code of conduct](CODE_OF_CONDUCT.md). Questions and ideas are best raised in [GitHub Discussions](https://github.com/phoenix1331/filament-jev-moderation/discussions); for anything large, start there so we can agree the approach before you spend time on it. Security issues go through [SECURITY.md](SECURITY.md), never a public issue.

## Setup

You need PHP 8.3 or newer with `mbstring`, `intl`, `pdo_sqlite` and (for coverage) `pcov`, Composer 2, Node (for the git hooks) and [`osv-scanner`](https://github.com/google/osv-scanner) on your `PATH` (`go install github.com/google/osv-scanner/cmd/osv-scanner@latest`).

```bash
git clone https://github.com/phoenix1331/filament-jev-moderation
cd filament-jev-moderation
composer install
npm install          # activates the Husky commit-msg and pre-commit hooks
composer build       # builds the workbench: Filament assets, SQLite, demo data
composer serve       # http://127.0.0.1:8000/_workbench logs you in and opens the panel
```

There is no Docker and no database server: tests and the workbench both run on SQLite through [Orchestra Testbench](https://packages.tools/testbench). Nothing calls the Jev API: the workbench uses the core's `fake` driver.

## Composer scripts

| Script | What it does |
|---|---|
| `composer lint` | Fix code style with Pint |
| `composer lint:test` | Check code style without changing files |
| `composer analyse` | Larastan at max level over `src/`, `tests/Fixtures/` and `workbench/` |
| `composer test` | The Pest suite |
| `composer test:coverage` | The suite with the 90% coverage gate on `src/` |
| `composer check` | `lint:test`, `analyse` and `test:coverage`; run it before every pull request |
| `composer build` | Rebuild the workbench (also needed after any `composer update`, which resets it) |
| `composer serve` | Build, then serve the workbench |
| `composer screenshots` | Capture every page and widget, light and dark, into `docs/screenshots/` |

## The workbench

`workbench/` is a small Filament app with the plugin installed: a panel, forum posts and game chat messages with `#[Moderate]`, and a seeder that writes 90 days of decision history through the core's factory. The story is deliberate: one repeat offender, moderators overturning low-confidence flags far more often than confident ones (so calibration has something to say), a Jev version change halfway through, a report, a rescan and a deleted post. Randomness is seeded, so every build is identical.

- `/_workbench` logs in as `demo@example.com`
- `/admin/widget-preview?widget=decision-volume-chart` shows one widget on its own

## How the code is organised

Read `CONTEXT.md` for the map. The rules that matter most:

- **Write only through the core.** Reviews call the moderated model's `approve()` / `reject()`, so the core's events, audit trail and policy checks apply. An architecture test fails the build if `src/` writes to the database any other way.
- **Use the core's public API only.** Its `Moderation` and `Policies` internals are off limits, which another architecture test enforces. If the API cannot answer a question, propose a change to [the core](https://github.com/phoenix1331/laravel-jev-moderation) rather than working around it.
- **Every page and widget is authorised.** Pages use `AuthorisesPageAccess`; widgets use `AuthorisesWidgetView`, which also refuses to mount or update for users who may not see them. Review actions go through `JevModerationPlugin::canReview()`. New parts must join `tests/Feature/AuthorisationMatrixTest.php`.
- **Widgets on the queue page are listed in `ReviewQueueResource::getWidgets()`.** Filament 4 registers a resource's widget components from that list only; a widget missing from it renders but fails on every update.
- **Plugin markup uses `fi-jev-moderation-*` classes** from `resources/dist/filament-jev-moderation.css`, not Tailwind utilities: Filament only ships the utilities it uses itself.
- **Status colours come from `Support\StatusColour`**, so a status looks the same on every page. The volume chart's palette is checked for colour vision deficiency in light and dark mode; keep that if you change it.

## Tests

Every change needs tests, and `composer check` must pass. Pest feature tests run inside Testbench with a Filament panel registered (`tests/Fixtures/TestPanelProvider.php`); call `$this->migrateModerationTables()` and use the core's `ModerationDecisionFactory` (the `decisionFor()` helper) for history. Pin the clock with `Carbon::setTestNow()` when a test depends on ages or date ranges.

Files in the `databases` group also run on PostgreSQL, MySQL and MariaDB in CI, so keep queries portable: no SQLite-only functions in `src/`.

CI tests Filament 4 and 5 on Laravel 12 and 13. To try Filament 4 locally, then go back:

```bash
composer require "filament/filament:4.*" "laravel/framework:12.*" --no-update && composer update -W
composer test
git checkout composer.json && composer update -W
```

## Translations

Every user-facing string lives in `resources/lang/en/`. `tests/Feature/TranslationsTest.php` fails if a label, heading or description is a plain string, if a view contains literal text, or if code uses a key missing from `en`.

To add a language, copy `resources/lang/en` to `resources/lang/<locale>`, translate the values and keep the keys and `:placeholders`. Apps can also publish the English files and edit them in place:

```bash
php artisan vendor:publish --tag=filament-jev-moderation-translations
```

## Screenshots

`composer screenshots` regenerates `docs/screenshots/` from the seeded workbench. It needs Google Chrome or Chromium (set `CHROME` to point at another binary), Node 22+ for the plugin directory images, and a free port (`SCREENSHOT_PORT`, default 8000). Run `composer build` first, and include updated screenshots, light and dark, in pull requests that change how something looks.

## Commits and pull requests

- Commit messages are lowercase [Conventional Commits](https://www.conventionalcommits.org) without scopes, for example `feat: add a source filter`. The `commit-msg` hook enforces this, and rejects `chore(deps): ...`
- The `pre-commit` hook runs Pint and `osv-scanner` against `composer.lock`
- Add a line under `## [Unreleased]` in `CHANGELOG.md` for anything users will notice, using the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) headings (`Added`, `Changed`, `Fixed`, `Removed`, `Deprecated`, `Security`)
- Keep each pull request to one change and fill in the pull request checklist
- Write in UK English

Releases are covered in [RELEASING.md](RELEASING.md).
