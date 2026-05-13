<?php

defined('ABSPATH') || exit;

/**
 * Wraps a WordPress hook callback to measure its execution time.
 *
 * An instance of this class replaces the original callback function in the
 * {@see WP_Hook} callbacks array. When WordPress fires the hook, PHP invokes
 * {@see WP_Hook_Profiler_Callback_Wrapper::__invoke()}, which records timing
 * data in the engine before and after delegating to the original function.
 *
 * Metadata (callback name, plugin info, aggregate key) is resolved once in the
 * constructor and cached on the instance to avoid repeated lookups on the hot
 * path when the same callback fires many times.
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler_Callback_Wrapper {
    
    /** @var callable The original callback being wrapped. */
    private $original_function;

    /** @var string The name of the hook this wrapper is attached to. */
    private $hook_name;

    /** @var int The priority at which this callback is registered. */
    private $priority;

    /** @var int The number of arguments the callback accepts. */
    private $accepted_args;

    /** @var WP_Hook_Profiler_Engine The profiling engine used to record timing data. */
    private WP_Hook_Profiler_Engine $engine;

    /** @var string Cached human-readable callback name. */
    private $callback_name;

    /**
     * Cached plugin identification info for this callback.
     *
     * @var array{plugin: string, plugin_name: string, plugin_file: string|null, file: string|null}
     */
    private $plugin_info;

    /** @var string Cached aggregate key: "{callback_name}|{hook_name}|{priority}". */
    private $callback_key;

    /**
     * Constructor.
     *
     * Resolves and caches callback metadata (name, plugin info, aggregate key)
     * once at registration time so that per-invocation overhead is minimal.
     *
     * @param callable                  $original_function The original WordPress hook callback.
     * @param string                    $hook_name         The hook name (action or filter tag).
     * @param int                       $priority          The callback's registered priority.
     * @param int                       $accepted_args     Number of arguments the callback accepts.
     * @param WP_Hook_Profiler_Engine   $engine            The profiling engine instance.
     */
    public function __construct($original_function, $hook_name, $priority, $accepted_args, $engine) {
        $this->original_function = $original_function;
        $this->hook_name = $hook_name;
        $this->priority = $priority;
        $this->accepted_args = $accepted_args;
        $this->engine = $engine;

        // Resolve and cache metadata once; key includes priority so the same
        // callback registered at two different priorities gets separate rows.
        $this->callback_name = $engine->get_callback_name($original_function);
        $this->plugin_info   = $engine->get_plugin_info_safe($original_function);
        $this->callback_key  = $this->callback_name . '|' . $hook_name . '|' . $priority;
    }
    
    /**
     * Invoke the wrapped callback and record its execution time.
     *
     * Delegates all arguments to the original function unchanged and returns
     * its return value unmodified, preserving filter chain behaviour.
     *
     * Timing data is accumulated in the engine's callback_aggregates and
     * timing_data maps. Plugin totals are updated on every invocation.
     *
     * @param mixed ...$args Arguments forwarded from the WordPress hook dispatcher.
     * @return mixed The return value of the original callback.
     */
    public function __invoke(...$args ) {

        $callback_key = $this->callback_key;
        $engine       = $this->engine;

        // Callback cap: if we already have this key, just reuse it. Otherwise
        // allocate a new row only while under the cap. Above the cap we still
        // measure the call's time and credit it to plugin totals — only the
        // per-callback row is suppressed.
        $track_callback = isset($engine->callback_aggregates[$callback_key]);
        if (!$track_callback) {
            if (count($engine->callback_aggregates) < $engine->max_callbacks) {
                $engine->callback_aggregates[$callback_key] = [
                    'hook' => $this->hook_name,
                    'callback' => $this->callback_name,
                    'plugin' => $this->plugin_info['plugin'],
                    'plugin_name' => $this->plugin_info['plugin_name'],
                    'source_file' => $this->plugin_info['file'],
                    'total_time' => 0,
                    'call_count' => 0,
                    'average_time' => 0,
                    'memory_delta_total' => 0,
                    'memory_delta_peak'  => 0,
                    'memory_delta_net'   => 0,
                    'priority' => $this->priority,
                    'accepted_args' => $this->accepted_args
                ];
                $track_callback = true;
            } else {
                $engine->callbacks_capped = true;
            }
        }

        // Sample memory before. memory_get_usage(true) returns the OS-allocated
        // chunk total (not emalloc) — coarser than (false) but captures real
        // allocator pressure, and is what triggers OOM.
        $track_memory = $engine->track_memory;
        $mem_before   = $track_memory ? memory_get_usage(true) : 0;

        $start = hrtime(true);

		$original_function = $this->original_function;
		$result = $original_function(...$args);
        $end = hrtime(true);
        $eta = $end - $start;
        $eta /= 1e+6; // nanoseconds to milliseconds

        $mem_after = $track_memory ? memory_get_usage(true) : 0;
        $mem_delta = $mem_after - $mem_before; // can be negative if GC freed

        if (is_finite($eta) && $eta >= 0) {
            if ($track_callback) {
                $row = &$engine->callback_aggregates[$callback_key];
                $row['total_time']   += $eta;
                $row['call_count']++;
                $row['average_time']  = $row['total_time'] / $row['call_count'];
                if ($track_memory) {
                    $row['memory_delta_net']   += $mem_delta;
                    $row['memory_delta_total'] += abs($mem_delta);
                    if ($mem_delta > $row['memory_delta_peak']) {
                        $row['memory_delta_peak'] = $mem_delta;
                    }
                }
                unset($row);
            }
            $engine->total_execution_time += $eta;
            if ($track_memory) {
                $engine->total_memory_delta += abs($mem_delta);
            }

            // Update plugin totals (guarded: only accumulate finite, non-negative values)
            $plugin_key = $this->plugin_info['plugin'];
            if (!isset($engine->timing_data[$plugin_key])) {
                $engine->timing_data[$plugin_key] = [
                    'total_time' => 0,
                    'hook_count' => 0,
                    'callback_count' => 0,
                    // Associative map: hook_name => true, for O(1) dedup.
                    // Converted back to a list at read time in get_profile_data().
                    'hooks' => [],
                    'memory_delta_total' => 0,
                    'memory_delta_net'   => 0,
                    'plugin_name' => $this->plugin_info['plugin_name'],
                    'plugin_file' => $this->plugin_info['plugin_file']
                ];
            }

            $plugin_row = &$engine->timing_data[$plugin_key];
            $plugin_row['total_time'] += $eta;
            $plugin_row['callback_count']++;
            if ($track_memory) {
                $plugin_row['memory_delta_net']   += $mem_delta;
                $plugin_row['memory_delta_total'] += abs($mem_delta);
            }

            // Per-plugin hook cap: stop adding new hook names once over cap.
            if (!isset($plugin_row['hooks'][$this->hook_name])) {
                if ($plugin_row['hook_count'] < $engine->max_hooks_per_plugin) {
                    $plugin_row['hooks'][$this->hook_name] = true;
                    $plugin_row['hook_count']++;
                } else {
                    $engine->plugin_hooks_capped = true;
                }
            }
            unset($plugin_row);
        }

        return $result;
    }
}
