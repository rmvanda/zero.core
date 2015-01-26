<?php
/**
 * Application class
 *
 * @version 3.0.1
 */
class Application
{

    private $aspect;
    private $endpoint;

    private $subdomain = array();

    public function __construct()
    {
        //@f:off
           $this-> defineConstants() 
                -> parseRequest() 
                -> fetchUtilities()
                -> finalizeRoute()
                -> run();
            //@f:on

    }

    public function __call($name, $args)
    {
        if (Request::isAccessible()) {
            new Page(Request::$accessible);
        } elseif (in_array(($name = ucfirst($name)), get_declared_classes())) {
            trigger_error("You can't do that", E_USER_ERROR);
            exit();
        } else {
            $aspect = new $name();
            $aspect -> {$args[0]}(Request::$uri);
        }
    }

    public function modprobe(array $modprobe)
    {
        foreach ($modprobe as $module) {
            define(strtoupper($module), true);
        }
        return $this;
    }

    public function parseRequest()
    {
        new Request();
        return $this;
    }

    public function run()
    {
        if (STANDARD || true) {
            $this -> {Request::$aspect}(Request::$endpoint);
        } else {
            $aspect = new Request::$aspect;
            $aspect -> {Request::$endpoint}();
        }

    }

    private function friendlyURLConverter($url)
    {
        return lcfirst(str_replace(" ", "", ucwords(str_replace("-", ' ', $url))));
    }

    public function registerAutoloaders(callable $autoload = null)
    {
        if (file_exists($file = ROOT_PATH . "vendor/autoload.php")) {
            require $file;
        }
        spl_autoload_register("self::errorHandler");
        return $this;
    }

    public function fetchUtilities()
    {
        if (DEV) {
            require_once UTILITIES_PATH . "dev/Utilities.php";
        }
        require_once UTILITIES_PATH . "utils/Extensions.php";
        return $this;
    }

    public function defineConstants($key = null)
    {
        if (!defined("ROOT_PATH")) {
            define("URL", "http://" . $_SERVER['HTTP_HOST'] . "/");
            define("ROOT_PATH", trim($_SERVER['DOCUMENT_ROOT'], "www"));
        }
        foreach (scandir(ROOT_PATH."app/_configs/") as $ini) {
            if ($ini != "." && $ini != "..") {
                foreach (parse_ini_file(ROOT_PATH."app/_configs/".$ini, false, INI_SCANNER_RAW) as $constant => $value) {
                    define($constant, ($ini == "paths.ini" ? ROOT_PATH : "") . $value);
                }
            }
        }
        return $this;
    }

    public function finalizeRoute()
    {
        if (Request::$sub) {
            if (Request::$sub !== 'admin') {
                // Redirect that bitch.
                return $this;
            } elseif (Request::$isElevated) {
                return new Zero\Admin();
            }
        } else {
            return $this;
        }
    }

    public function errorHandler($class)
    {
        if (DEV) {die($class);
            Console::log() -> cannotLoad($class);
        }
        Error::_404($class);
    }

}
