# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Requires `phoenix1331/laravel-jev-moderation` 0.2
- `authorize()` decides who may use the moderation pages and widgets: the callback must return exactly `true`, and without one access is allowed in the `local` environment only. Pages return 403 on mount and every request; widgets too, even when mounted directly
- Moderation actions (approve, reject, reclassify) are checked on top of `authorize()`: through `authorizeActionsUsing()` when given, else through a registered `ModerationDecision` policy (Filament Shield or your own; abilities `approve`, `reject`, `reclassify`), else allowed to anyone with access
- `JevModerationPlugin` with `make()`, `get()` and `current()`, and options for the navigation group (default "Moderation", translatable), icon and sort; `models([...])` to limit the plugin to some moderated models (default: every type with decisions); `includeBlocked()` to queue automatic blocks as well as flags; `modelLabels([...])` to name model types; `authorRelation()` and `authorRelations([...])` for author history (default: the `user` relation); `overrideWarningRate()` and `minimumSample()` for calibration. Closures are accepted throughout, and invalid values fail when the panel boots

[Unreleased]: https://github.com/phoenix1331/filament-jev-moderation/commits/main
