<?php
/**
 * @brief Unified error handler for the entire JDB engine.
 *
 * Provides two complementary error-tracking mechanisms:
 *
 *   1. **Call-stack frames** (self::$stack): a push-only list of frames added
 *      by every layer that participates in a failing operation.
 *      Index 0 is the deepest root cause; the last entry is the public API
 *      entry point. Reset with resetStack() at the start of each operation.
 *
 *   2. **Per-component last-error** (self::$errors): a map keyed by component
 *      name that stores the most recent error for quick retrieval by consumers
 *      (e.g. JdbManager::getLastError()).
 *
 * Per-component logging behaviour is governed by a configuration array
 * registered with configure(). The keys read by this class are:
 *   - 'log_errors'      (bool)        Enable file logging.
 *   - 'error_log_path'  (string|null) Destination log file path.
 *   - 'detailed_errors' (bool)        If false, the original message is
 *                                     replaced with a generic string before
 *                                     storage and logging.
 *
 * @see JdbConfig::configure()
 * @see JdbManager
 * @see JdbAggregate
 */
class JdbErrorHandler {
    /**
     * @var array[] Push-only stack of error frames.
     *
     * Each frame is an associative array with keys:
     *   - 'class'  (string) Class that pushed the frame.
     *   - 'method' (string) Method name (without parentheses).
     *   - 'msg'    (string) Short description of the failure point.
     *
     * Index 0 = deepest root cause. Last index = public API entry point.
     * Reset with resetStack() at the beginning of each public operation.
     */
    private static $stack = array();

    /**
     * @var array Per-component last-error registry.
     *
     * Keyed by component name (string). Each value is an associative array:
     *   - 'method'  (string) Method in which the error occurred.
     *   - 'message' (string) Stored message (may be the generic sanitised
     *                        string if 'detailed_errors' is false).
     *   - 'time'    (int)    Unix timestamp of the error (from time()).
     */
    private static $errors = array();

    /**
     * @var array Per-component logging configuration, keyed by component name.
     *
     * Populated by configure(). Only three keys are consumed by this class:
     * 'log_errors', 'error_log_path', and 'detailed_errors'. Additional keys
     * present in the array (forwarded from JdbConfig) are stored but ignored.
     */
    private static $configs = array();

    /**
     * @brief Clears all frames from the error stack.
     *
     * Must be called at the beginning of every public operation so that frames
     * from a previous call do not bleed into the current one.
     * Does NOT affect per-component errors (self::$errors) or configuration.
     *
     * @return void
     */
    public static function resetStack()
    {
        self::$stack = array();
    }

    /**
     * @brief Appends an error frame to the stack.
     *
     * Each participating layer in a call chain should push its own frame so
     * that formatStack() can reconstruct the full error path.
     * Frames are stored in call order: the first push is the root cause,
     * the last push is the outermost API entry point.
     *
     * @param string $class  Class name of the caller (e.g. 'JdbBinaryIndex').
     * @param string $method Method name without parentheses (e.g. 'idxReadSlot').
     * @param string $msg    Short human-readable description of the failure point.
     * @return void
     */
    public static function push($class, $method, $msg)
    {
        self::$stack[] = array(
            'class'  => $class,
            'method' => $method,
            'msg'    => $msg,
        );
    }

    /**
     * @brief Returns whether the error stack contains at least one frame.
     *
     * Useful for a quick failure check after a sequence of operations without
     * having to inspect the stack contents.
     *
     * @return bool true if one or more frames are present; false if the stack is empty.
     */
    public static function hasStackError()
    {
        return !empty(self::$stack);
    }

    /**
     * @brief Returns the raw stack array in insertion order.
     *
     * Index 0 is the deepest root cause (first push); the last index is the
     * outermost API entry point (last push). Use formatStack() for a
     * human-readable representation in the opposite (top-down) order.
     *
     * @return array[] Array of frames, each with keys 'class', 'method', 'msg'.
     *                 Empty array if no frames have been pushed since the last reset.
     */
    public static function getStack()
    {
        return self::$stack;
    }

    /**
     * @brief Formats the error stack as a human-readable multi-line string.
     *
     * Output order is reversed with respect to getStack(): the first line is
     * the outermost API entry point (#0) and the last line is the root cause.
     * Each line follows the format:
     *   #N ClassName::methodName() — message
     *
     * @return string Newline-separated stack trace, or an empty string if the
     *                stack is empty.
     */
    public static function formatStack()
    {
        if (empty(self::$stack)) {
            return '';
        }
        $frames = array_reverse(self::$stack);
        $lines  = array();
        foreach ($frames as $i => $f) {
            $lines[] = '#' . $i . ' ' . $f['class'] . '::' . $f['method'] . '()'
                     . ' — ' . $f['msg'];
        }
        return implode("\n", $lines);
    }

    /**
     * @brief Registers (or replaces) the logging configuration for a component.
     *
     * Typically called by JdbConfig::configure() during application bootstrap,
     * once for 'JdbManager' and once for 'JdbAggregate'.
     * The configuration is stored verbatim; only three keys are actually consumed
     * by set():
     *   - 'log_errors'      (bool)        Enable file logging for this component.
     *   - 'error_log_path'  (string|null) Absolute path to the log file.
     *   - 'detailed_errors' (bool)        If false, the original message passed to
     *                                     set() is replaced with a generic string
     *                                     before being stored or logged.
     * Any additional keys in $config are stored silently and never read.
     *
     * @param string $component Unique component identifier (e.g. 'JdbManager').
     * @param array  $config    Configuration array (see keys above).
     * @return void
     */
    public static function configure($component, array $config) {
        self::$configs[$component] = $config;
    }

    /**
     * @brief Records an error for a component and optionally writes it to the log file.
     *
     * Behaviour summary:
     *   1. **Message sanitisation**: if the component's 'detailed_errors' setting is
     *      false (or not configured), $message is silently replaced with the generic
     *      string 'An internal error occurred' before any storage or logging occurs.
     *      The original message is never persisted in that case.
     *   2. **Per-component storage**: the (possibly sanitised) message, method name,
     *      and current Unix timestamp are stored in self::$errors[$component],
     *      overwriting any previously recorded error for that component.
     *   3. **Stack push**: if $pushStack is true (default), push() is called with the
     *      component name, method, and sanitised message.
     *   4. **File logging**: if 'log_errors' is true and 'error_log_path' is set and
     *      the path is writable, a timestamped line is appended via error_log().
     *      If the path is not writable, a warning is sent to the PHP system logger
     *      (mode 0 — stderr in CLI, system log in web SAPI) without throwing.
     *
     * @param string $component  Unique component identifier (e.g. 'JdbManager').
     * @param string $method     Name of the method in which the error occurred.
     * @param string $message    Original error message; may be sanitised before storage
     *                           (see 'detailed_errors' above).
     * @param bool   $pushStack  If true (default), also push a frame onto the stack.
     * @return void
     */
    public static function set($component, $method, $message, $pushStack = true) {
        $cfg = isset(self::$configs[$component]) ? self::$configs[$component] : array();
        $detailed = isset($cfg['detailed_errors']) && $cfg['detailed_errors'];
        if (!$detailed) {
            $message = 'An internal error occurred';
        }

        self::$errors[$component] = array(
            'method'  => $method,
            'message' => $message, 
            'time'    => time(),
        );
        if ($pushStack) {
            self::push($component, $method, $message);
        }
        if (isset($cfg['log_errors']) && isset($cfg['error_log_path']) && $cfg['log_errors'] && $cfg['error_log_path']) {
            $log = sprintf(
                "[%s] %s::%s - %s\n",
                date('Y-m-d H:i:s'),
                $component,
                $method,
                $message
            );
            $logPath    = $cfg['error_log_path'];
            $fileExists = file_exists($logPath);
            $canWrite   = $fileExists ? is_writable($logPath) : is_writable(dirname($logPath));
            if ($canWrite) {
                error_log($log, 3, $logPath);
            } else {
                // Fallback to PHP system logger (error_log mode 0: stderr in CLI, system log in web SAPI)
                error_log('[JdbErrorHandler] Log path not writable: ' . $logPath);
            }
        }
    }

    /**
     * @brief Returns the most recent error recorded for a component.
     *
     * @param  string     $component Unique component identifier (e.g. 'JdbManager').
     * @return array|null Associative array on success, or null if no error has been
     *                    recorded for the component since the last clear() / resetAll().
     *                    Array keys:
     *                      - 'method'  (string) Method in which the error occurred.
     *                      - 'message' (string) Stored message (sanitised if
     *                                           'detailed_errors' was false at the
     *                                           time set() was called).
     *                      - 'time'    (int)    Unix timestamp of the error.
     */
    public static function getLast($component) {
        return isset(self::$errors[$component]) ? self::$errors[$component] : null;
    }

    /**
     * @brief Clears the stored error for a specific component, or all components.
     *
     * Does NOT affect the stack (self::$stack) or configuration (self::$configs).
     * Use resetStack() to clear the stack, or resetAll() to clear both stack and errors.
     *
     * @param  string|null $component Component identifier to clear, or null to clear
     *                                all per-component errors at once.
     * @return void
     */
    public static function clear($component = null) {
        if ($component === null) {
            self::$errors = array();
        } else {
            unset(self::$errors[$component]);
        }
    }

    /**
     * @brief Returns whether a stored error exists for the given component.
     *
     * Equivalent to getLast($component) !== null, but without allocating the
     * return array.
     *
     * @param  string $component Unique component identifier (e.g. 'JdbAggregate').
     * @return bool   true if an error is recorded for the component; false otherwise.
     */
    public static function hasComponentError($component) {
        return isset(self::$errors[$component]);
    }

    /**
     * @brief Resets the error stack and all per-component stored errors.
     *
     * Intended for test isolation: call in setUp() or tearDown() to guarantee
     * a clean state between test cases.
     *
     * @note Per-component configuration (self::$configs) is intentionally NOT
     *       cleared, because it is registered once at application bootstrap via
     *       JdbConfig::configure(). To also clear configuration, call
     *       JdbConfig::resetAll() followed by a fresh configure() call.
     *
     * @return void
     */
    public static function resetAll()
    {
        self::$stack = array();
        self::$errors = array();
        // Note: configuration (self::$configs) is intentionally NOT cleared,
        // because it is set once at application bootstrap.
    }
}
