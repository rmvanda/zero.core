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
           $this -> defineConstants() 
                -> registerAutoloaders()
                -> parseRequest() 
                -> fetchUtilities()
                -> finalizeRoute()
                -> run( Request::$aspect, Request::$endpoint, Request::$args );
        //@f:on

    }

    //@deprecated
    public function __call($name, $args)
    {
        return;
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

    public function run($aspect, $endpoint, $args)
    {
        // if (Request::isAccessible()) {
        //  new Page(Request::$accessible);
        //} else
        if (in_array(($aspect = ucfirst($aspect)), get_declared_classes())) {
            //  trigger_error("You can't do that", E_USER_ERROR);
            Error::_403();
        } else {
            $aspect = new $aspect();
            $aspect -> $endpoint($args);
        }
        /*
         if (STANDARD || true) {
         $this -> {Request::$aspect}(Request::$endpoint);
         } else {
         $aspect = new Request::$aspect;
         $aspect -> {Request::$endpoint}();
         }
         * */
        print_i();
    }

    private function friendlyURLConverter($url)
    {
        return lcfirst(str_replace(" ", "", ucwords(str_replace("-", ' ', $url))));
    }

    public function load($filename, $path = null)
    {
        $stdout = exec("find " . ROOT_PATH . $path . " -type f -name " . $filename);
        echo "Attempting to side load $filename from path : " . ROOT_PATH . "$path which returns: $stdout<br>";
        return (file_exists($stdout) ?
        require $stdout : false);
    }

    public function registerAutoloaders($autoloader = null)
    {
        // for Composer + PSR compatability
        if (file_exists($file = ROOT_PATH . "vendor/autoload.php")) {
            require $file;
        }
        // if you want to add external autoloaders
        if ($autoloader) {
            if (is_callable($autoloader)) {
                spl_autoload_register($autoloader);
            } elseif (is_array($autoloader)) {
                foreach ($autoloader as $al) {
                    spl_autoload_register($al);
                }
            }
        }
        /**
         * Maximally Underwritten Fast As Shit Autoloader Array
         * MUFASA - !
         * @version 0.8.2
         *
         * This is the first truly stable version of MUFASA.
         * This stability was made possible via dividing the autoloader into 4
         * parts:
         *
         *
         * It will probably change into a class in the long run, but this is
         * quite
         * suitable for the time being.
         *
         * AS long as a simple set of standards are adhered to, then this works
         * flawlessly.
         * Otherwise, it can be dangerous.
         *
         * Be advised!
         *
         */

        /*
         * Loads base classes.
         */
        spl_autoload_register(function($class)
        {
            echo "loading $class from :";
            if (strpos($class, "\\")) {
                $namespace = explode("\\", $class);
                $class = array_pop($namespace);
            }
            $stdout = exec("find " . ROOT_PATH . " -type f -name " . $class . ".php");
            echo $stdout . "<br>";
            return (file_exists($stdout) ?
            require $stdout : false);
        });
        /*
         * Loads app classes.
         *
         spl_autoload_register(function($class)
         {
         return (file_exists($stdout = exec("find ../app/ -type f -name " .
         $class . ".php")) ?
         require $stdout : false);
         });
         *
         * Loads plugins/modules
         *
         * spl_autoload_register(function($class)
         {
         return (file_exists($file = MODULE_PATH . $class . "/" . $class .
         ".php") ?
         require $file : false);
         });
         *
         * loads things from the 'lib' folder
         *
         spl_autoload_register(function($class)
         {
         return (file_exists($stdout = exec("find ../srv/libs/ -type f -name " .
         $class . ".php")) ?
         require $stdout : false);
         });

         /*
         * loads things from the 'dev' folder
         *
         if (DEV) {
         require ROOT_PATH . "srv/dev/Console.php";
         spl_autoload_register(function($class)
         {
         if (strpos($class, "\\")) {
         $namespace = explode("\\", $class);
         $class = array_pop($namespace);
         }
         $stdout = exec("find ../admin/ -type f -name " . $class . ".php");
         Console::log() -> mufasa($stdout);
         return (file_exists($stdout) ?
         require $stdout : false);

         }, false, true);
         }
         */
        /*
         spl_autoload_register(function($class)
         {
         return (file_exists($stdout = exec("find ../srv/dev/ -type f -name " .
         $class .
         ".php")) ?
         require $stdout : false);
         });

         // if (Request::$isElevated)
         // spl_autoload_register(function($class)
         // {
         // if (strpos($class, "\\")) {
         // $namespace = explode("\\", $class);
         // $class = array_pop($namespace);
         // }
         // $stdout = exec("find ../admin/ -type f -name " . $class . ".php");
         // Console::log() -> mufasa($stdout);
         // return (file_exists($stdout) ?
         // require $stdout : false);
         //
         // }, false, true);
         // }
         */

        // require ROOT_PATH."core/_autoloader.php";
        spl_autoload_register("self::errorHandler");
        return $this;
    }

    public function fetchUtilities($utilities = null)
    {
        if (DEV) {
            $this -> load("Utilities.php");
            $this->load("Console.php"); 
        }
        $this -> load("Extensions.php");
        if ($utilities) {
            if (file_exists($utilities)) {
                require $utilities;
            } elseif (is_array($utilities)) {
                foreach ($utilities as $utility) {
                    require $utility;
                }
            }
        }
        return $this;
    }

    public function defineConstants(array $key = null)
    {
        if (!defined("ROOT_PATH")) {
            define("URL", "http://" . $_SERVER['HTTP_HOST'] . "/");
            define("ROOT_PATH", "/" . trim($_SERVER['DOCUMENT_ROOT'], "skeleton/frontend/www") . "/");
            //^fuck
        }
        if ($key) {
            foreach ($key as $k => $v) {
                define($k, $v);
            }
        }

        return $this;

        /*
         foreach (scandir(ROOT_PATH."skeleton/_configs/") as $ini) {
         if ($ini != "." && $ini != "..") {
         foreach (parse_ini_file(ROOT_PATH."skeleton/_configs/".$ini, false,
         INI_SCANNER_RAW) as $constant => $value) {
         define($constant, ($ini == "paths.ini" ? ROOT_PATH : "") . $value);
         }
         }
         }
         return $this;*/
    }

    // TODO
    public function finalizeRoute()
    {
        // if (Request::$sub) {
        //    if (Request::$sub !== 'admin') {
        // Redirect that bitch.
        //       return $this;
        //  } elseif (Request::$isElevated) {
        return new Zero\Admin();
        // }
        //} else {
        //   return $this;
        // }
    }

    public function errorHandler($class)
    {
        if (DEV) {
            die("Can't load $class");
            Console::log() -> cannotLoad($class);
        }
        Error::_404($class);
    }

}
