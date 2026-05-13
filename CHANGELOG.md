# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Shutdown dump to a JSON file so profile data survives fatal errors (OOM, timeout).
  Opt-in via the `WP_HOOK_PROFILER_DUMP_PATH` constant or the
  `wp_hook_profiler_dump_path` filter.

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
