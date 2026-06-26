<?php

require_once __DIR__ . '/../src/jdb.php';

/**
 * Global helper used in JsonDatabase::update().
 * Returns $default if $key doesn't exists in the array
 */
if (!function_exists('get_val')) {
    function get_val($array, $key, $default = null)
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }
}
