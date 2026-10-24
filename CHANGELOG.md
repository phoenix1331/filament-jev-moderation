# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Review queue (`Moderation > Review queue`): every record whose latest decision is a flag (and a block with `includeBlocked()`), across every moderated model, with its type, current content (including content hidden from the site), author, category, confidence, source and time waiting. Most confident first, then longest waiting; reports show their reason and the category Jev gave before them. The navigation badge shows how many are waiting
- Approve and reject in the review queue, for one record or a selection across model types, with an optional note (up to 2,000 characters). Reviews are recorded through the core's `approve()` / `reject()`, so `ReviewResolved` fires as usual. A record that changed since the page loaded (already reviewed, reported again or rescanned), was deleted, or that the moderator may not review is skipped, and the notification says how many; a record the core refuses (for example, its policy was removed from config) is reported with the reason without stopping the rest
- Reclassify in the review queue: remove content under a corrected category when Jev picked the wrong one, recorded through the core's `reject()` with that category. Offers the harmful choices of the record's policy other than the category it was queued under; hidden when the record's policy was removed from config
- Review queue filters: model type, category (a report matches on the category Jev gave before it; options are the choices of the shown models' policies), confidence range in percent (inclusive, reports drop out), source, and date queued
- Requires `phoenix1331/laravel-jev-moderation` 0.2
- `authorize()` decides who may use the moderation pages and widgets: the callback must return exactly `true`, and without one access is allowed in the `local` environment only. Pages return 403 on mount and every request; widgets too, even when mounted directly
- Moderation actions (approve, reject, reclassify) are checked on top of `authorize()`: through `authorizeActionsUsing()` when given, else through a registered `ModerationDecision` policy (Filament Shield or your own; abilities `approve`, `reject`, `reclassify`), else allowed to anyone with access
- `JevModerationPlugin` with `make()`, `get()` and `current()`, and options for the navigation group (default "Moderation", translatable), icon and sort; `models([...])` to limit the plugin to some moderated models (default: every type with decisions); `includeBlocked()` to queue automatic blocks as well as flags; `modelLabels([...])` to name model types; `authorRelation()` and `authorRelations([...])` for author history (default: the `user` relation); `overrideWarningRate()` and `minimumSample()` for calibration. Closures are accepted throughout, and invalid values fail when the panel boots

[Unreleased]: https://github.com/phoenix1331/filament-jev-moderation/commits/main
