<?php

namespace Zero;
require "../srv/dev/Console.php";

use \Request as Request;
use \Application as Application;
use \Console as Console;

class Admin extends Application
{

    public function __construct()
    {
        spl_autoload_register(function($class)
        {
            echo $class;
            if (strpos($class, "\\")) {
                $namespace = explode("\\", $class);
                $class = array_pop($namespace);
            }
            Console::log() -> autoloader($class, $stdout = exec("find ../ -type f -name " . $class . ".php"));
            echo $stdout;
            return (file_exists($stdout) ?
            require $stdout : false);
        }, false, true);
    }

    public function run()
    {

        if (Request::$isAjax) {
            $admin = new Request::$aspect;
            $admin -> {Request::$endpoint}();
        } else {
            try {
                AdminPanel::generate() -> header();
            } catch(exception $e) {
                print_x($e);
            }
        }

        $aspect = ucfirst(Request::$aspect);
        $app = new $aspect;
        $app -> {Request::$endpoint}();

        Console::output();
    }

}
