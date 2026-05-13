<?php

defined('ABSPATH') || exit;

/**
 * Core profiling engine for WP Hook Profiler.
 *
 * Hooks into the WordPress 'all' pseudo-hook to intercept every action and
 * filter fired during a page request. For each hook it wraps the registered
 * callbacks in {@see WP_Hook_Profiler_Callback_Wrapper} instances that measure
 * individual execution times and aggregate the results by plugin.
 *
 * A hook-depth guard ({@see WP_Hook_Profiler_Engine::$max_hook_depth}) prevents
 * stack overflows caused by deeply recursive hook chains.
 *
 * @since 1.0.0
 */
class WP_Hook_Profiler_Engine {
    
    /**
     * Per-plugin timing aggregates, keyed by plugin slug.
     *
     * Each entry contains: total_time, hook_count, callback_count, hooks[],
     * plugin_name, plugin_file.
     *
     * @var array<string, array<string, mixed>>
     */
    public $timing_data = [];

    /**
     * Per-callback timing aggregates, keyed by "{callback_name}|{hook_name}|{priority}".
     *
     * Each entry contains: hook, callback, plugin, plugin_name, source_file,
     * total_time, call_count, average_time, priority, accepted_args.
     *
     * @var array<string, array<string, mixed>>
     */
    public $callback_aggregates = [];

    /** @var WP_Hook_Profiler_Plugin_Detector|null Plugin detector instance. */
    private $plugin_detector = null;

    /** @var bool Whether profiling is currently active. */
    private $profiling_active = false;

    /** @var int Total number of unique hooks profiled so far. */
    private $hook_count = 0;

    /**
     * Cumulative execution time of all profiled callbacks in milliseconds.
     *
     * Declared public so {@see WP_Hook_Profiler_Callback_Wrapper} can update it
     * directly without an additional method call on the hot path.
     *
     * @var float
     */
    public $total_execution_time = 0;

    /** @var bool Recursion guard to prevent re-entrant profiling. */
    private $recursion_guard = false;

    /** @var int Current hook nesting depth. */
    private $hook_depth = 0;

    /** @var int Maximum allowed hook nesting depth before profiling is skipped. */
    private $max_hook_depth = 500;

    /**
     * Maximum number of unique callback+hook+priority entries to track.
     *
     * Once the cap is reached, profiling continues but new callbacks are not
     * added to {@see self::$callback_aggregates}. Plugin-level totals still
     * accumulate via the wrappers that were already installed.
     *
     * Filter: {@code wp_hook_profiler_max_callbacks}
     *
     * @var int
     */
    public $max_callbacks = 500;

    /**
     * Maximum number of distinct hook names recorded per plugin in
     * {@see self::$timing_data[$plugin]['hooks']}.
     *
     * Without this cap, the hook list grows linearly with the number of
     * distinct hooks fired and can cost O(hooks × plugins) memory on sites
     * with many active plugins.
     *
     * Filter: {@code wp_hook_profiler_max_hooks_per_plugin}
     *
     * @var int
     */
    public $max_hooks_per_plugin = 100;

    /**
     * Memory usage ratio (current/limit) at which profiling pauses.
     *
     * When PHP memory usage reaches this fraction of {@code memory_limit},
     * the engine stops wrapping new callbacks. Existing wrappers continue
     * measuring (those are cheap). The pause state is one-way: once set it
     * is not cleared, so a brief allocation spike that pushes us over the
     * threshold permanently pauses new instrumentation for the request.
     *
     * Filter: {@code wp_hook_profiler_memory_threshold}
     *
     * @var float
     */
    public $memory_threshold = 0.80;

    /**
     * Cached PHP memory_limit in bytes, parsed once at start_profiling().
     * Zero means unlimited (memory_limit = -1) and disables the guard.
     *
     * @var int
     */
    private $memory_limit_bytes = 0;

    /**
     * Whether the memory guard has paused new-callback instrumentation.
     *
     * @var bool
     */
    public $memory_paused = false;

    /**
     * Whether to measure per-callback memory deltas via memory_get_usage().
     *
     * Tracking memory adds two memory_get_usage(true) calls per hook callback
     * (one before, one after). On busy pages with ~200k callback invocations
     * this is ~400k extra C-level function calls; cheap (no syscall, returns
     * a cached value) but not free. Disable in production if absolute hot-
     * path overhead matters.
     *
     * Filter: {@code wp_hook_profiler_track_memory}
     *
     * @var bool
     */
    public $track_memory = true;

    /**
     * Cumulative absolute memory delta across all profiled callbacks in bytes.
     *
     * Sum of |after-before| for every wrapped invocation. NOT the same as
     * peak memory — useful as a rough total-allocation indicator.
     *
     * @var int
     */
    public $total_memory_delta = 0;

    /**
     * Whether the callback cap has been hit.
     *
     * @var bool
     */
    public $callbacks_capped = false;

    /**
     * Whether at least one plugin hit the per-plugin hook list cap.
     *
     * @var bool
     */
    public $plugin_hooks_capped = false;

    /**
     * Hook-fire counter used to throttle memory probes.
     *
     * {@see memory_get_usage()} is cheap but not free; calling it once per
     * hook (≥40k times on busy admin pages) adds measurable overhead. We
     * probe once per {@code MEMORY_PROBE_INTERVAL} hooks instead.
     *
     * @var int
     */
    private $memory_probe_counter = 0;

    /**
     * How often (in hooks fired) the memory guard is probed.
     *
     * @var int
     */
    private const MEMORY_PROBE_INTERVAL = 100;

    /**
     * Constructor.
     *
     * Loads the plugin detector and callback wrapper dependencies.
     */
    public function __construct() {
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-plugin-detector.php';
        require_once WP_HOOK_PROFILER_DIR . 'inc/class-callback-wrapper.php';
        $this->plugin_detector = new WP_Hook_Profiler_Plugin_Detector();
    }
    
    /**
     * Begin profiling by attaching to the WordPress 'all' pseudo-hook.
     *
     * Calling this method more than once is safe — subsequent calls are no-ops.
     *
     * @return void
     */
    public function start_profiling() {
        if ($this->profiling_active) {
            return;
        }

        // Resolve user-tunable limits via filters. Defensive casts so a bad
        // filter return value can't take down the engine.
        if (function_exists('apply_filters')) {
            $this->max_callbacks        = max(1, (int) apply_filters('wp_hook_profiler_max_callbacks', $this->max_callbacks));
            $this->max_hooks_per_plugin = max(1, (int) apply_filters('wp_hook_profiler_max_hooks_per_plugin', $this->max_hooks_per_plugin));
            $threshold = (float) apply_filters('wp_hook_profiler_memory_threshold', $this->memory_threshold);
            if ($threshold > 0 && $threshold < 1) {
                $this->memory_threshold = $threshold;
            }
            $this->track_memory = (bool) apply_filters('wp_hook_profiler_track_memory', $this->track_memory);
        }

        // Cache memory_limit as bytes once. -1 (unlimited) disables the guard.
        $this->memory_limit_bytes = self::parse_php_size((string) ini_get('memory_limit'));

        $this->profiling_active = true;

        add_action('all', [$this, 'on_hook_start'], -999999);
    }

    /**
     * Parse a PHP shorthand size string (e.g. "512M") to bytes.
     *
     * @param string $value The PHP ini value.
     * @return int Bytes, or 0 if unlimited or unparseable.
     */
    private static function parse_php_size($value) {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        $unit = strtolower(substr($value, -1));
        $num  = (int) $value;
        switch ($unit) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
            default:  return $num;
        }
    }

    /**
     * Probe current memory usage and set the pause flag if we've crossed
     * the threshold. Probes only every {@code MEMORY_PROBE_INTERVAL} hooks
     * to keep overhead minimal.
     *
     * @return bool True if the engine should now pause new-callback wrapping.
     */
    private function check_memory_pressure() {
        if ($this->memory_paused) {
            return true;
        }
        if ($this->memory_limit_bytes <= 0) {
            // Unlimited memory or unparseable limit — guard disabled.
            return false;
        }

        $this->memory_probe_counter++;
        if (($this->memory_probe_counter % self::MEMORY_PROBE_INTERVAL) !== 0) {
            return false;
        }

        $usage = memory_get_usage(true);
        if ($usage >= $this->memory_limit_bytes * $this->memory_threshold) {
            $this->memory_paused = true;
            return true;
        }
        return false;
    }
    
    /**
     * Callback fired for every WordPress hook via the 'all' pseudo-hook.
     *
     * Guards against recursion and excessive nesting depth before delegating
     * to {@see WP_Hook_Profiler_Engine::profile_hook_callbacks()}.
     *
     * @param string $hook_name The name of the hook being fired.
     * @return void
     */
    public function on_hook_start($hook_name) {
        if (!$this->profiling_active || $this->is_profiler_hook($hook_name)) {
            return;
        }

        if ($this->recursion_guard) {
            return;
        }

        $this->hook_depth++;

        if ($this->hook_depth > $this->max_hook_depth) {
            $this->hook_depth--;
            return;
        }

        // Memory guard: once we cross the threshold stop wrapping new
        // callbacks. Already-wrapped callbacks continue measuring (cheap),
        // so plugin totals still grow but we don't add new allocations.
        if (!$this->check_memory_pressure()) {
            $this->profile_hook_callbacks($hook_name);
        }

        $this->hook_count++;
        $this->hook_depth--;
    }
    
    /**
     * Determine whether a hook name belongs to the profiler itself.
     *
     * Profiler-internal hooks are excluded from profiling to prevent infinite
     * recursion.
     *
     * @param string $hook_name The hook name to test.
     * @return bool True if the hook should be skipped.
     */
    private function is_profiler_hook($hook_name) {
        $profiler_hooks = [
            'wp_ajax_wp_hook_profiler_data',
            'wp_ajax_nopriv_wp_hook_profiler_data',
            'all'
        ];
        
        return in_array($hook_name, $profiler_hooks, true);
    }
    
    /**
     * Wrap all untracked callbacks on a given hook with timing wrappers.
     *
     * Iterates over the {@see WP_Hook} callbacks array for the given hook and
     * replaces any callback that is not already a
     * {@see WP_Hook_Profiler_Callback_Wrapper} with a new wrapper instance.
     *
     * @param string $hook_name The hook whose callbacks should be wrapped.
     * @return void
     */
    private function profile_hook_callbacks($hook_name) {
        if ($this->recursion_guard) {
            return;
        }

		global $wp_filter;

        if (!isset($wp_filter[$hook_name])) {
			return;
		}

        $hook_object = $wp_filter[ $hook_name ];

        if ($hook_object instanceof WP_Hook) {
			foreach ($hook_object->callbacks as $priority => &$priority_callbacks) {
				foreach ($priority_callbacks as $idx => &$callback_data) {
					if (! $callback_data['function'] instanceof WP_Hook_Profiler_Callback_Wrapper) {
						$callback_data['function'] = new WP_Hook_Profiler_Callback_Wrapper(
							$callback_data['function'],
							$hook_name,
							$priority,
							$callback_data['accepted_args'],
							$this
						);
					}
				}
			}
		}
    }
    
    
    /**
     * Safely identify the plugin that registered a given callback.
     *
     * Wraps {@see WP_Hook_Profiler_Plugin_Detector::identify_callback_source()} with
     * a recursion guard and exception handler so that plugin detection errors
     * never interrupt the hook being profiled.
     *
     * @param callable $callback The callback whose source should be identified.
     * @return array{plugin: string, plugin_name: string, plugin_file: string|null, file: string|null}
     */
    public function get_plugin_info_safe($callback) {

        $this->recursion_guard = true;
        
        try {
            $plugin_info = $this->plugin_detector->identify_callback_source($callback);
        } catch (Exception $e) {
            $plugin_info = [
                'plugin' => 'error',
                'plugin_name' => 'Error: ' . $e->getMessage(),
                'plugin_file' => null,
                'file' => null
            ];
        }
        
        $this->recursion_guard = false;
        return $plugin_info;
    }
    
    /**
     * Return a human-readable name for a callback.
     *
     * Handles strings (function names), arrays (static/instance method pairs),
     * Closure objects, and invokable objects.
     *
     * @param callable $callback The callback to name.
     * @return string A descriptive name such as "ClassName::methodName" or "Closure".
     */
    public function get_callback_name($callback) {
        if (is_string($callback)) {
            return $callback;
        }
        
        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            return $class . '::' . $callback[1];
        }
        
        if (is_object($callback)) {
            if ($callback instanceof Closure) {
                return 'Closure';
            }
            return get_class($callback) . '->__invoke()';
        }
        
        return 'Unknown Callback';
    }
    
    /**
     * Return a ReflectionFunctionAbstract for the given callback.
     *
     * Delegates to {@see WP_Hook_Profiler_Plugin_Detector::get_callback_reflection()}.
     *
     * @param callable $callback The callback to reflect.
     * @return \ReflectionFunctionAbstract|null Reflection object, or null on failure.
     */
    public function get_callback_reflection($callback) {
        return $this->plugin_detector->get_callback_reflection($callback);
    }
    
    /**
     * Return a snapshot of all profiling data collected so far.
     *
     * Plugins are sorted by total execution time (descending). Callbacks are
     * sorted by total execution time (descending).
     *
     * @return array{
     *   plugins: array<string, array<string, mixed>>,
     *   callbacks: list<array<string, mixed>>,
     *   plugin_loading: array<string, mixed>,
     *   total_hooks: int,
     *   total_execution_time: float
     * }
     */
    public function get_profile_data() {
        uasort($this->timing_data, function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        // Convert per-plugin 'hooks' associative map back to a list for
        // consumer compatibility (debug panel JS, JSON dump consumers).
        foreach ($this->timing_data as $plugin_key => &$plugin_row) {
            if (isset($plugin_row['hooks']) && is_array($plugin_row['hooks'])) {
                $first_key = array_key_first($plugin_row['hooks']);
                if ($first_key !== null && !is_int($first_key)) {
                    $plugin_row['hooks'] = array_keys($plugin_row['hooks']);
                }
            }
        }
        unset($plugin_row);

        $callback_data = array_values($this->callback_aggregates);
        usort($callback_data, function($a, $b) {
            return $b['total_time'] <=> $a['total_time'];
        });

        // Get plugin loading timing data
        $plugin_loading_data = [];
        if (function_exists('wp_hook_profiler_get_timing_data')) {
            $plugin_loading_data = wp_hook_profiler_get_timing_data();
        }
        
        return [
            'plugins' => $this->timing_data,
            'callbacks' => $callback_data,
            'plugin_loading' => $plugin_loading_data,
            'total_hooks' => $this->hook_count,
            'total_execution_time' => $this->total_execution_time,
            'total_memory_delta' => $this->total_memory_delta,
            'warnings' => [
                'memory_paused'        => $this->memory_paused,
                'memory_threshold'     => $this->memory_threshold,
                'callbacks_capped'     => $this->callbacks_capped,
                'plugin_hooks_capped'  => $this->plugin_hooks_capped,
                'track_memory'         => $this->track_memory,
                'max_callbacks'        => $this->max_callbacks,
                'max_hooks_per_plugin' => $this->max_hooks_per_plugin,
            ],
        ];
    }
    
    /**
     * Return the total number of unique hooks profiled during this request.
     *
     * @return int
     */
    public function get_hook_count() {
        return $this->hook_count;
    }
    
    /**
     * Return the cumulative execution time of all profiled callbacks in milliseconds.
     *
     * @return float
     */
    public function get_total_execution_time() {
        return $this->total_execution_time;
    }
}
