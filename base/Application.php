<?php

class Application
{

    public function __construct()
    {


        $this -> defineConstants()
            -> fetchUtilities() 
            -> registerAutoloaders() 
            -> getClientSession()
            -> parseRequest() 
            -> finalizeRoute() 
            -> run(
                    Request::$aspect, 
                    Request::$endpoint, 
                    Request::$args
                  );

    }

    public function modprobe(array $modprobe)
    {
        foreach ($modprobe?:array() as $module) {
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

        if (loads(ucfirst($aspect))) {
            $aspect = new $aspect();
        } else {
            $aspect = new Response();
        }

        $aspect -> {$endpoint}($args);

    }


    private function friendlyURLConverter($url)
    {
        return lcfirst(str_replace(" ", "", 
                    ucwords(
                        str_replace("-", ' ', $url))));
    }

    public function load($filename, $path = null)
    {
        if (loads($filename)) {
            return true;
        } else {
            return false;
        }

    }

    public function suload($filename)
    {
        if (suloads($filename)) {
            return true;
        } else {
            return false;
        }
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
        spl_autoload_register("self::load");
        /**
         * Maximally Underwritten Fast As Shit Autoloader Array
         * MUFASA - !
         * @version 0.8.2
         *

         spl_autoload_register(function($class)
         {

        //  echo $class;
        if (strpos($class, "\\")) {
        $namespace = explode("\\", $class);
        $class = array_pop($namespace);
        }
        $stdout = exec("find " . ROOT_PATH . ' -not -iwholename "*admin*"
        -type f -name ' . $class . ".php");
        //	Console::log() -> autoloading("Class $class in path: $stdout
        // <br>");
        //echo " $stdout !<br>";
        return (file_exists($stdout) ?
        require $stdout : false);
        });
         */
        spl_autoload_register("self::errorHandler");
        return $this;
    }

    public function getClientSession(){ 
        new Client(); 
        return $this; 
    }

    public function fetchUtilities($utilities = null)
    {

        require ROOT_PATH . "admin/dev/Console/Console.php";
        require __DIR__ . "/Extensions.php";
        require __DIR__ . "/../defaults/Err/Err.php";
        //loads("Err");

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
        if ($_SERVER['REMOTE_ADDR'] == "192.168.1.77"  || 
                $_SERVER['REMOTE_ADDR'] == "50.199.113.222"||
                $_SERVER['HTTP_HOST']   == "localhost"
           ) {
            define("DEV",     true);
            define("DEVMODE", true); 
        }

        if (!defined("ROOT_PATH")) {
            define("URL", "http://" . $_SERVER['HTTP_HOST'] . "/");
            $root_path = explode("core", __DIR__ );
            define("ROOT_PATH", $root_path[0]) ;
        }

        define("VIEW_PATH", ROOT_PATH . "app/frontend/views/");

        foreach (scandir(ROOT_PATH."app/_configs/") as $ini) {
            if ($ini != "." && $ini != "..") { // there's a flag to kill this
                foreach (
                        parse_ini_file(
                            ROOT_PATH."app/_configs/".$ini,
                            false, 
                            INI_SCANNER_RAW
                            ) 
                        as $constant => $value) {

                    define($constant, 
                            ($ini == "paths.ini" ? 
                             ROOT_PATH : "") . $value);
                    // ^ this will be largely inefficient if there are too many .ini's
                }
            }
        }

        if ($key) {
            foreach ($key as $k => $v) {
                define($k, $v);
            }
        }
        return $this;
    }

    // TODO
    // ACL HOOK?
    public function finalizeRoute()
    {
        return $this ; // your AC is no good here
        /*            if (in_array(($aspect = ucfirst($aspect)), get_declared_classes()) 
                      && !defined("DEV")
                      ){
                      new Err(403);

                      } else {


         */
        if ($_SERVER['HTTP_HOST'] != PRIMARY_DOMAIN && 
                !$this -> request -> access
           ) {
            header("Location: " . 
                    $this -> request -> protocol . 
                    "://" . PRIMARY_DOMAIN
                  );
            exit();
        } elseif ($_SERVER['HTTP_HOST'] == ADMIN_DOMAIN) {
            //                echo "Suload says: ".
            $this -> suload("Admin");
            return new Admin();
            /**
             * APP_MODE simply designated that this Application should act
             * like an app
             * and force the user to login if they want to do anything -
             * otherwise, display a page,
             * or take some action - (perhaps redirect to an info.domain.com
             * which is not running Zero)
             *
             */
            //  } elseif (!$_SESSION['uid'] && defined("APP_MODE") && APP_MODE == true && $this -> request -> aspect != "auth") {
            //	header("Location: /auth/login");
            //include VIEW_PATH . "_global/login.html";
            //  exit();
    }
    return $this;
    }
    public function errorHandler($class)
    {
        if (defined("DEV")) {
            xdebug_print_function_stack();
            Console::log() -> error($class);
            die("<h1 style='color:red'>Can't load $class</h1>");
        }
        new Err(404, $class);
    }

    }
