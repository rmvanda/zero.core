<?php
/**
 * Maximally Underwritten Fast As Shit Autoloader Array
 * MUFASA - !
 * @version 0.8.2
 *
 * This is the first truly stable version of MUFASA.
 * This stability was made possible via dividing the autoloader into 4 parts:
 *
 *
 * It will probably change into a class in the long run, but this is quite
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
    if (strpos($class, "\\")) {
        $namespace = explode("\\", $class);
        $class = array_pop($namespace);
    }
    $stdout = exec("find ../srv/base/ -type f -name " . $class . ".php");
   // echo $stdout."<br>";
    return (file_exists($stdout) ?
    require $stdout : false);
});
/*
 * Loads app classes.
 */
spl_autoload_register(function($class)
{
    return (file_exists($stdout = exec("find ../app/ -type f -name " . $class . ".php")) ?
    require $stdout : false);
});
/*
 * Loads plugins/modules
 *
 * spl_autoload_register(function($class)
 {
 return (file_exists($file = MODULE_PATH . $class . "/" . $class . ".php") ?
 require $file : false);
 });
 *
 * loads things from the 'lib' folder
 */
spl_autoload_register(function($class)
{
    return (file_exists($stdout = exec("find ../srv/libs/ -type f -name " . $class . ".php")) ?
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
 return (file_exists($stdout = exec("find ../srv/dev/ -type f -name " . $class .
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
