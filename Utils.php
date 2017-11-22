<?php

/**
 * Static Utility Methods
 *
 * @author lmi
 *        
 */
class Utils
{

    public static function is_session_started()
    {
        if (php_sapi_name() !== 'cli') {
            if (version_compare(phpversion(), '5.4.0', '>=')) {
                return session_status() === PHP_SESSION_ACTIVE ? true : false;
            } else {
                if (! empty(session_id())) {
                    if (! defined('SID')) {
                        define('SID', session_id());
                    }
                    return true;
                }
                return false;
            }
        }
        return false;
    }

    public static function start_session()
    {
        if (self::is_session_started() === false) {
            return session_start();
        }
        return true;
    }

    public static function get_header_field($fieldName)
    {
        $headers = self::get_all_headers();
        return isset($headers[$fieldName]) ? $headers[$fieldName] : null;
    }

    public static function gump_validate_input($aspect, $endpoint)
    {
        $valid = false;
        $gfields = json_decode(file_get_contents(ROOT_PATH . 'app/_configs/gump.json'), true);
        $fields = isset($gfields[$aspect][$endpoint]) ? $gfields[$aspect][$endpoint] : false;
        
        if ($fields) {
            $validators = array_filter(array_map(function ($f) {
                return $f['v'];
            }, $fields));
            
            $filters = array_filter(array_map(function ($f) {
                return $f['f'];
            }, $fields));
            
            $inputs = array_intersect_key($_POST, $fields);
            
            if ($inputs) {
                $gump = new GUMP();
                try {
                    if ($validators) {
                        $gump->validation_rules($validators);
                    }
                    if ($filters) {
                        $gump->filter_rules($filters);
                    }
                    $valid = $gump->run($inputs);
                    if ($valid === false) {
                        $valid = $gump;
                    }
                } catch (Exception $e) {
                    var_dump($e->getMessage());
                    $valid = $gump;
                    return $valid;
                }
            }
        }
        return $valid;
    }

    private static function get_all_headers()
    {
        $headers = '';
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    /**
     * Recursively implodes an array with optional key inclusion
     *
     * Example of $include_keys output: key, value, key, value, key, value
     *
     * @access public
     * @param array $array
     *            multi-dimensional array to recursively implode
     * @param string $glue
     *            value that glues elements together
     * @param bool $include_keys
     *            include keys before their values
     * @param bool $trim_all
     *            trim ALL whitespace from string
     * @return string imploded array
     */
    public static function recursive_implode(array $array, $glue = ',', $include_keys = false, $trim_all = false)
    {
        $glued_string = '';
        
        // Recursively iterates array and adds key/value to glued string
        array_walk_recursive($array, function ($value, $key) use($glue, $include_keys, &$glued_string) {
            $include_keys and $glued_string .= $key . $glue;
            $glued_string .= $value . $glue;
        });
        
        // Removes last $glue from string
        strlen($glue) > 0 and $glued_string = substr($glued_string, 0, - strlen($glue));
        
        // Trim ALL whitespace
        $trim_all and $glued_string = preg_replace("/(\s)/ixsm", '', $glued_string);
        
        return (string) $glued_string;
    }

    public static function utf8_converter($array)
    {
        array_walk_recursive($array, function (&$item, $key) {
            if (! mb_detect_encoding($item, 'utf-8', true)) {
                $item = utf8_encode($item);
            }
        });
        
        return $array;
    }
}

?>