<?php

namespace Zero\Core;

class Console {

    // Log levels in ascending severity order; index = numeric level
    private const LEVELS = [
        "DEBUG",
        "INFO",
        "NOTICE",
        "WARN",
        "ERROR",
        "CRITICAL",
        "ALERT",
        "EMERGENCY",
        "BAN"
    ];

    /**
     * Store all console messages during the request for DevToolbar
     */
    private static array $messages = [];

    /**
     * Cached log threshold (resolved once from constants, then reused)
     */
    private static ?int $threshold = null;

    /**
     * Resolve and cache the configured log threshold.
     * Checks ZERO_LOG_LEVEL_INT (numeric index) first, then ZERO_LOG_LEVEL (string name).
     * Defaults to 0 (DEBUG) so everything is logged until explicitly configured.
     */
    private static function getThreshold(): int {
        if (self::$threshold === null) {
            if (defined('ZERO_LOG_LEVEL_INT')) {
                self::$threshold = (int) ZERO_LOG_LEVEL_INT;
            } elseif (defined('ZERO_LOG_LEVEL')) {
                $idx = array_search(ZERO_LOG_LEVEL, self::LEVELS);
                self::$threshold = $idx !== false ? (int) $idx : 0;
            } else {
                self::$threshold = 0; // DEBUG — log everything by default
            }
        }
        return self::$threshold;
    }

    /**
     * Core log method.
     *
     * @param mixed            $message  String, array, or object to log
     * @param string|int|null  $loglvl   Level name ("WARN") or index (3). Defaults to DEBUG.
     * @param string|null      $logfile  Override log file path
     */
    public static function log($message, string|int|null $loglvl = null, string|null $logfile = null): void {

        // Resolve level to a numeric index and display string
        if ($loglvl === null) {
            $loglvl = 0;
            $loglvlstring = self::LEVELS[0];
        } elseif (!is_numeric($loglvl)) {
            $idx = array_search(strtoupper((string) $loglvl), self::LEVELS);
            if ($idx === false) {
                // Unknown level name — fall back to DEBUG and surface it in the string
                $loglvlstring = "UNKNOWN({$loglvl})";
                $loglvl = 0;
            } else {
                $loglvl = (int) $idx;
                $loglvlstring = self::LEVELS[$loglvl];
            }
        } else {
            $loglvl = (int) $loglvl;
            $loglvlstring = self::LEVELS[$loglvl] ?? "LEVEL{$loglvl}";
        }

        // Serialize non-string messages
        if (is_array($message) || is_object($message)) {
            $message = print_r($message, true);
        }

        // Walk the backtrace to find the first frame outside Console.php
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $frame = $trace[0];
        foreach ($trace as $f) {
            if (!isset($f['file']) || basename($f['file']) !== 'Console.php') {
                $frame = $f;
                break;
            }
        }

        // Store for DevToolbar (always, regardless of log threshold)
        self::$messages[] = [
            'timestamp' => time(),
            'level'     => $loglvlstring,
            'message'   => $message,
            'caller'    => ($frame['file'] ?? '') . ':' . ($frame['line'] ?? ''),
        ];

        if ($loglvl < self::getThreshold()) {
            return;
        }

        $caller = '[' . basename($frame['file'] ?? 'unknown') . ':' . ($frame['line'] ?? '?') . '] ';

        $date  = '[' . gmdate('Y-m-d H:i:s') . '] ';
        $ip    = '[' . ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '] ';
        $level = "[{$loglvlstring}]: ";

        if (!$logfile) {
            $logfile = '/var/log/php-fpm/zero.log';
        }

        file_put_contents($logfile, $date . $ip . $level . $caller . $message . "\n", FILE_APPEND);
    }

    /**
     * Magic static dispatch: Console::debug(), ::info(), ::warn(), ::error(), ::ban(), etc.
     * Optional second argument overrides the log file path.
     */
    public static function __callStatic(string $loglvl, array $msg): void {
        $message      = $msg[0];
        $loglvlUpper  = strtoupper($loglvl);
        $logfile      = null;

        if (isset($msg[1])) {
            $logfile = $msg[1];
        } elseif ($loglvlUpper === 'BAN') {
            $logfile = '/var/log/php-fpm/zero-tolerance.log';
        }

        self::log($message, $loglvlUpper, $logfile);
    }

    /**
     * Get all messages logged during this request
     * Used by DevToolbar plugin
     */
    public static function getMessages(): array {
        return self::$messages;
    }

    /**
     * Clear all stored messages
     * Useful for testing or resetting state
     */
    public static function clearMessages(): void {
        self::$messages = [];
    }

}
