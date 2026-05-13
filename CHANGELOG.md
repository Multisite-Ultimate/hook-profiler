# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Shutdown dump to a JSON file so profile data survives fatal errors (OOM, timeout).
  Opt-in via the `WP_HOOK_PROFILER_DUMP_PATH` constant or the
  `wp_hook_profiler_dump_path` filter. Pre-allocates a 1 MB memory reserve at
  request start and releases it inside the shutdown handler so the dump can be
  serialised even when the request died of OOM. Supports `{token}` placeholder
  in the dump path (replaced with a per-URL hash) so concurrent requests don't
  clobber each other's dumps.
- Memory guard (the one the README has been promising): when PHP memory usage
  reaches the configured fraction of `memory_limit` the engine stops wrapping
  new callbacks. Already-wrapped callbacks keep measuring, so plugin totals
  still grow accurately. Tunable via `wp_hook_profiler_memory_threshold`
  (default 0.80). Probed once every 100 hooks to keep overhead minimal.
- Callback cap: the per-callback aggregate table is now capped at 500 entries
  (tunable via `wp_hook_profiler_max_callbacks`). Calls above the cap still
  contribute to plugin totals; only the per-callback row is omitted.
- Per-plugin hook list cap (default 100, tunable via
  `wp_hook_profiler_max_hooks_per_plugin`) prevents O(hooks × plugins) memory
  growth on sites with many active plugins.
- New `warnings` block in `get_profile_data()` output exposes `memory_paused`,
  `callbacks_capped`, `plugin_hooks_capped`, plus the active limits, so
  consumers can flag truncated data.

### Changed
- Internal per-plugin hook list switched from a sequential array with
  `in_array()` dedup to an associative map for O(1) inserts. The public
  `get_profile_data()` output still returns a list of hook names for
  backwards compatibility — the conversion happens at read time.
- Request URI is now captured at plugin construction (early) rather than at
  shutdown, so it survives SAPI quirks (notably FrankenPHP worker mode reset
  of `$_SERVER`).

## [1.1.0] - 2026-04-10

### Added
- Multi-tab debug panel: plugins overview, slowest callbacks, hook details, and plugin loading analysis
- Advanced filtering and search across all panel views

### Fixed
- Prevent OOM memory exhaustion on sites with large numbers of hooks (#6)
- Resolve unknown plugin source detection for non-standard callback locations (#5)

### Changed
- PHPDoc blocks added to all classes and methods

## [1.0.1] - 2025-08-28

### Fixed
- Errors when activating on some configurations — timing code moved to mu-plugin instead of sunrise.php
- Rename main plugin file to `hook-profiler.php` for slug consistency

## [1.0.0] - 2025-03-25

### Added
- Initial release
