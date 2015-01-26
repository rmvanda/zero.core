<?php
//This prevents errors 
if (!function_exists('print_x')) {
    function print_x()
    {
        //pass;
    };
    function print_i()
    {
        //pass;
    };
}
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
function sudoWgetImg($img, $imgPath)
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
