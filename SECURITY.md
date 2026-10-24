# Security Policy

## Supported Versions

| Version | Supported |
|---------|-----------|
| 0.x     | Latest minor only |

While Jev Moderation for Filament is pre-1.0, fixes are released on the latest minor version only.

## Reporting a Vulnerability

Please do not open a public GitHub issue for security vulnerabilities.

Report security issues privately through GitHub: [report a vulnerability](https://github.com/phoenix1331/filament-jev-moderation/security/advisories/new) (Security tab, "Report a vulnerability"). If you cannot use GitHub, email **darren@phoenix1331.co.uk**. Include:

- A description of the vulnerability and its potential impact
- Steps to reproduce or a proof-of-concept
- The package version affected, plus your Laravel and PHP versions

You will receive an acknowledgement within 48 hours. If the issue is confirmed, a fix will be released as soon as possible and you will be credited in the changelog unless you prefer to remain anonymous.

## Scope

Jev Moderation for Filament is a review interface for [Jev Moderation for Laravel](https://github.com/phoenix1331/laravel-jev-moderation). It shows moderators what the core has decided and records their reviews through the core. It has no tables, no config file, makes no network requests and writes nothing to your logs. The core's own [SECURITY.md](https://github.com/phoenix1331/laravel-jev-moderation/blob/main/SECURITY.md) covers what is sent to TypeSafe and what each decision stores.

### What it reads

| Source | Read | Shown on |
|---|---|---|
| `moderation_decisions` | Every column for the model types the plugin shows: status, source, category, confidence, policy and version, Jev version, `meta` (Jev's probabilities and reason), `note`, `reviewed_by` (the moderator, or the reporting user on a report) and `content` when the core stores a copy (`store_content`) | Review queue, decision history, author history, widgets, thresholds page |
| Moderated records | `moderatableContent()`, loaded past the core's visibility scope, so content hidden from the site (blocked, or flagged in `pre` mode) is shown in full. Deleted records are left out | Review queue, decision history, author history |
| Authors | The author relation set with `authorRelation()` / `authorRelations()`, shown by Filament name or `name` attribute, else `#` and the key. Never the email address | Review queue, decision history, author history |
| Config | Each policy's thresholds and version through the core's public API. Never the API key | Thresholds page |

### What it writes

Only reviews, and only through the core: approve, reject and reclassify call the moderated model's `approve()` / `reject()`, which add a decision with the signed-in moderator and an optional note of up to 2,000 characters. The record is locked during the review, and one that changed since the page loaded is skipped. An architecture test fails the build if `src/` writes to the database any other way.

### What it sends

Nothing. The plugin makes no HTTP requests and calls no logger, and architecture tests enforce both. The `jev-moderation:rescan` commands on the thresholds page are shown for you to run; the plugin never runs them. Its only asset is one stylesheet served through Filament.

### Authorisation

| Check | Default |
|---|---|
| Pages and widgets (`authorize()`) | Allowed in the `local` environment only. A callback must return exactly `true`; any other value denies. Pages return 403 on every request and widgets on mount and every Livewire update, not only in navigation |
| Review actions | `authorizeActionsUsing()` when set, else a registered `ModerationDecision` policy (abilities `approve`, `reject`, `reclassify`; Filament Shield works), else anyone allowed by `authorize()`. Checked per record, bulk actions included |
| Author history | Access is checked before the author is looked up, so the page does not reveal which authors exist |
| "View" link to the record | Only to a host resource page the moderator may view (or else edit) |

### Potential concerns

**Moderators see the worst content and personal data** - That is the job, but it means anyone `authorize()` allows reads harmful content, content the site hides, and any personal data in it. Limit `authorize()` to trained moderators, not every panel user.

**`local` is trusted** - Without `authorize()`, access is allowed whenever `APP_ENV` is `local`. A public server left on `local` exposes the queue to every panel user. Always call `authorize()` in production code, not only in `local`.

**Without a policy, access means review** - If you register neither `authorizeActionsUsing()` nor a `ModerationDecision` policy, everyone who can see the queue can approve, reject and reclassify.

**Author history is a profile** - It lists every decision about one person's content across every moderated type. Treat it, notes and reporter identities as personal data in your access and retention policies.

**Notes are free text** - Moderators' notes are stored by the core and shown to every moderator on the history page. They are escaped on output (no view prints raw HTML, which a test checks), but they are not filtered for what people type.

**Dependency chain** - Runtime dependencies are `filament/filament`, `phoenix1331/laravel-jev-moderation` and `illuminate/*`. Keep `composer.lock` up to date and monitor it with `composer audit` or `osv-scanner`.

## Disclosure Policy

Once a fix is released, the vulnerability will be documented in `CHANGELOG.md` under the relevant version. We follow coordinated disclosure and will not publish details until a fix is available.
