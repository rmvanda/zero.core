<?php

/**
 * Redirect -
 * Simple alias for header("Location: /*.../*");
 * @param $location - either a local location, assuming "/" - or the URL of the
 * website.
 * @depends URL
 *
 * @author James Pope
 * @version 0.6
 *
 */
function redirect($to)
{
    header("Location: " . URL . trim($to, '/'));
}

/**
 * function formatDate
 *
 * @return Human Readable date - in Y-M-D unless the timestamp is from the same
 * day, in which case, it returns H:M:S
 *
 * @var $timestamp = Unix Timestamp
 *
 * @author James Pope
 * @version 0.8
 *
 */
function formatDate($timestamp)
{
    return (date("Y-m-d", $timestamp) == date("Y-m-d", time()) ? date("H:i:s", $timestamp) : date("Y-m-d", $timestamp));
}

/** */
function curlImg($img, $imgPath)
{
    $ch = curl_init($img);
    $fp = fopen($imgPath, 'wb');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_exec($ch);
    curl_close($ch);
    fclose($fp);
}

function file_curl_contents($url, $timeout = true)
{
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
    if ($timeout) {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    }
    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}

function loads($filename, $path = null)
{
    $stdout = exec("find " . ROOT_PATH . $path . " -type f -name " . $filename);
    //echo "Attempting to side load $filename from path : " . ROOT_PATH . "$path
    // which returns: $stdout<br>";
    return (file_exists($stdout) ?
    require $stdout : false);
}

if (defined("DEV")) {
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

} else {
    // This prevents Errors
    function print_x()
    {
        //pass;
    };
    function print_i()
    {
        //pass;
    };
}
