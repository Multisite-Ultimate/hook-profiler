=== Hook Profiler ===
Contributors: daveshine
Tags: performance, profiling, hooks, debugging, developer
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Measures execution time of every action and filter callback to identify performance bottlenecks by plugin, theme, or core.

== Description ==

Hook Profiler wraps every WordPress action and filter callback and records its execution time using `hrtime()`. Results are displayed in an overlay debug panel accessible from the admin bar — no page reload required.

**Key features:**

* Plugin overview — total execution time and callback count per plugin
* Slowest callbacks — ranked list of the slowest individual callbacks across all hooks
* Hook details — per-hook breakdown with filtering by plugin
* Plugin loading analysis — early-boot timing via an optional mu-plugin shim
* Advanced search and sorting across all views
* Memory guard — pauses profiling at 80 % of `memory_limit` to prevent OOM crashes
* Callback cap — limits tracked entries to prevent unbounded memory growth
* Multisite compatible (`Network: true`)

Hook Profiler is intended for **development and staging environments**. Deactivate it on production sites once you have identified your bottlenecks.

== Installation ==

1. Upload the `hook-profiler` directory to `wp-content/plugins/`.
2. Activate the plugin in the WordPress admin (or network admin for multisite).
3. Visit any page while logged in as an administrator.
4. Click the **Hooks** item in the admin bar to open the debug panel.

== Frequently Asked Questions ==

= Will this slow down my site? =

The profiler adds a small constant overhead to every hook invocation. Use it in development, not on live traffic.

= How do I raise the memory or callback limits? =

```php
// Pause profiling at 70 % of memory_limit instead of 80 %
add_filter( 'wp_hook_profiler_memory_threshold', fn() => 0.70 );

// Track up to 1 000 unique callback+hook pairs instead of 500
add_filter( 'wp_hook_profiler_max_callbacks', fn() => 1000 );

// Allow up to 200 hook entries per plugin instead of 100
add_filter( 'wp_hook_profiler_max_hooks_per_plugin', fn() => 200 );
```

= Does it work on multisite? =

Yes. The plugin header declares `Network: true` and the mu-plugin shim is installed network-wide.

== Screenshots ==

1. Admin bar indicator showing total hooks and cumulative execution time.
2. Plugins overview tab — sortable table of per-plugin execution time.
3. Slowest callbacks tab — ranked list of the slowest individual callbacks.
4. Hook details tab — per-hook breakdown with plugin filter.

== Changelog ==

= 1.2.0 - 2026-05-12 =
* New: Dump full profile data to JSON file on shutdown for offline analysis
* New: Memory guard pauses profiling at 80 % of `memory_limit` with `wp_hook_profiler_memory_threshold` filter
* New: Callback cap (default 500) via `wp_hook_profiler_max_callbacks` filter
* New: Per-plugin hook cap (default 100) via `wp_hook_profiler_max_hooks_per_plugin` filter
* New: Per-callback memory deltas recorded alongside execution time
* New: `fatal_error` field in dump JSON with `type_label`, recorded only for true fatal errors
* Chore: Add `composer.json` for Composer VCS installation

= 1.1.0 - 2026-04-10 =
* New: Multi-tab debug panel — plugins overview, slowest callbacks, hook details, and plugin loading analysis
* New: Advanced filtering and search across all panel views
* Fix: Prevent OOM memory exhaustion on sites with large numbers of hooks (#6)
* Fix: Resolve unknown plugin source detection for non-standard callback locations (#5)
* Improved: PHPDoc blocks added to all classes and methods

= 1.0.1 - 2025-08-28 =
* Fix: Errors when activating on some configurations — move timing code to mu-plugin instead of sunrise.php
* Fix: Rename main plugin file to `hook-profiler.php` for slug consistency

= 1.0.0 - 2025-03-25 =
* Initial release

== Upgrade Notice ==

= 1.2.0 =
Adds JSON profile dump on shutdown, memory guard, callback / per-plugin hook caps, and per-callback memory deltas. Recommended for all users.

= 1.1.0 =
Major UI update with multi-tab panel and OOM protection. Recommended for all users.
