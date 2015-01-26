<?php
/**
 * Development Utility functions.
 */

/**
 * print_x
 * Pretty prints print_r
 * @author Shane Xhin
 *
 */
function print_x($x)
{
    if (is_object($x)) {
        $obj = $x;
        unset($x);
        $x['methods'] = get_class_methods($obj);
        $x['properties'] = get_object_vars($obj);
    }
    echo '<pre>';
    print_r($x);
    echo '</pre>';
}

/**
 * Print Information
 * @return xdebug information
 *
 * @version 0.4
 */
function print_i()
{
    echo "<pre>";
    echo "Peak Memory Usage:  " . xdebug_peak_memory_usage() . "<br>";
    echo "Total execution Time: " . xdebug_time_index();
    echo "</pre>";
}
