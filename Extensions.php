<?php

// Global functions for convenience. 


/**
 * Redirect -
 * Simple alias for header("Location: /*.../*");
 * @param $location - either a local location, assuming "/" - or the URL of
 * the website.
 * @depends URL
 *
 * @author James Pope
 * @version 0.7
 *
 */
function redirect($to)
{
    //if (strpos($to, "http://") === false) {
    //    $url = ;
    //}
    //header("Location: " . $url . trim($to, '/'));
    header("Location: " . $to );//. trim($to, '/'));
}

/**
 * function formatDate
 *
 * @return Human Readable date - in Y-M-D unless the timestamp is from the
 * same
 * day, in which case, it returns H:M:S
 *
 * @var $timestamp = Unix Timestamp
 *
 * @author James Pope
 * @version 1.0
 *
 */
function formatDate($timestamp)
{
    return (date("Y-m-d", $timestamp) == date("Y-m-d", time()) ?
            date("H:i:s", $timestamp) : 
            date("Y-m-d", $timestamp)
            );
}

function getAge($bday){
    return date_create($bday)->diff(date_create('today'))->y;  
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

//TODO - time to do something different, here... 
function find($file, $path = null)
{
    //echo "\n\nGot a request to find $file, so going to find it...\n\n"; 
    //echo "\n\n"; 
    if (class_exists("\\Zero\\Core\\$file", false)){
     //   echo "but wait, that exists, so why did spl_autoload get called...";     
    }
    $stdout = exec(
        $stdin = "find " .
                  ROOT_PATH . 
                  $path . 
                  ' -not -iwholename "*admin*" -not -iwholename "*helpers*" -type f -name ' . 
                  $file . 
                  ".php");
//    echo "\n\nstdin: $stdin\n\nstdout:$stdout<br>"; 

    return (file_exists($stdout) ? $stdout : false);
}

function sufind($file, $path = null)
{
    $stdout = exec("find " . ROOT_PATH . $path . '  -iwholename "*admin*" -type f -name ' . $file . ".php");
//    echo "SuFind Found: $stdout, while looking for $file<br>"; 
    return (file_exists($stdout) ? $stdout : false);
}

function loads($filename, $path = null)
{
    $stdout = find($filename, $path);
    //Console::log() -> loading($filename, $stdout);
    //if(file_exists($stdout)){echo "and there it is...\n";}else{echo "But that failed for some fucking reason\n";}
    return (file_exists($stdout) ?

            require $stdout : false);
}

function suloads($filename, $path = null)
{
    $stdout = sufind($filename, $path);
    //exec("find " . ROOT_PATH . $path . '  -iwholename "*admin*"
    // -type f -name ' . $filename . ".php");
    Console::log() -> suloading($filename, $stdout);
    return (file_exists($stdout) ?
            require $stdout : false);
}

function isAjax()
{
    //@f:off
    return 
        (
         @$_SERVER['HTTP_X_REQUESTED_WITH'] && 	
         strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
        ) 
        ? true : false;
}//@f:on

function isAssoc(array $arr)
{
    if (array() === $arr) return false;
    return array_keys($arr) !== range(0, count($arr) - 1);
}

/**
 * Development Utility functions.
 */
if (true) { // TODO

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

    function print_i()
    {
        echo "<pre>";
        echo "Peak Memory Usage:  " . xdebug_peak_memory_usage() . "<br>";
        echo "Total execution Time: " . xdebug_time_index();
        echo "</pre>";
    }

} else {
    // This prevents Errors if someone leaves one of these, somewhere. 
    function print_x()
    {
        //pass;
    };
    function print_i()
    {
        //pass;
    };
}
