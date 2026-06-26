<?php

/**
 * JdbRealpathCache – TTL-based cache for realpath() calls.
 *
 * Reduces filesystem stat calls on shared hosting where realpath() can be slow.
 * Compatible with PHP 5.5+.
 */
class JdbRealpathCache
{
    /** @var array [path => ['path' => string|false, 'time' => int]] */
    private static $cache = array();

    /** @var int Default TTL in seconds (0 = no cache) */
    private static $defaultTtl = 3600;

    /**
     * Returns the realpath of a path, using a TTL cache.
     *
     * If the cached entry is still within its TTL window it is returned directly,
     * avoiding a filesystem stat call. A fresh realpath() call is made otherwise
     * and the result (including false for non-existent paths) is stored in the cache.
     *
     * @param string   $path  Filesystem path to resolve
     * @param int|null $ttl   TTL in seconds, overriding the default for this call.
     *                        Values ≤ 0 bypass the cache and call realpath() directly.
     *                        Pass null to use the default TTL set via setDefaultTtl().
     * @return string|false  Absolute canonical path, or false if the path does not exist
     */
    public static function get($path, $ttl = null)
    {
        $ttl = ($ttl === null) ? self::$defaultTtl : (int)$ttl;
        if ($ttl <= 0) {
            return realpath($path);
        }

        if (isset(self::$cache[$path]) && (time() - self::$cache[$path]['time']) < $ttl) {
            return self::$cache[$path]['path'];
        }

        $real = realpath($path);
        self::$cache[$path] = array('path' => $real, 'time' => time());
        return $real;
    }

    /**
     * Clears all cached realpath entries.
     *
     * Subsequent get() calls will perform a fresh realpath() lookup regardless of TTL.
     */
    public static function clear()
    {
        self::$cache = array();
    }

    /**
     * Sets the default TTL used by all subsequent get() calls that do not supply their own TTL.
     *
     * Negative values are silently clamped to 0, which disables caching by default.
     *
     * @param int $seconds  Cache lifetime in seconds; values < 0 are treated as 0
     */
    public static function setDefaultTtl($seconds)
    {
        self::$defaultTtl = max(0, (int)$seconds);
    }
}
