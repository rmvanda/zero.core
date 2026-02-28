<?php

namespace Zero\Core; 
//use \Zero\Core\Request as Request; 

function print_wrap($txt,$tag){
    printf("<%s>%s</%s>",$tag,$txt,$tag); 
}

function hprint($m, $n=1){
    print_wrap($m,"h$n"); 
}

function pprint($m){
    print_wrap($m,"pre"); 
}

class Application {

    /** Loaded plugin instances */
    private array $plugins = [];

    /**
     * Attribute processing log for DevToolbar.
     * Each entry: ['name' => string, 'scope' => 'class'|'method', 'target' => string]
     * Only populated in DEVMODE (when DevToolbar calls enableQueryTracking).
     * All recorded entries implicitly passed — a failing attribute exits before afterRun().
     */
    public static array $attributeLog = [];

   /**
    * Entry point to the core of the zero framework.
    *
    * Using this allows us to do things like "send" output before sending headers.
    * This also allows us to throw HTTP Errors anywhere in the execution flow.
    */
    public function __construct(){
        ob_start();
        // Start session for all module requests
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Time-based session regeneration for security
        if (isset($_SESSION['created_at'])) {
            $sessionAge = time() - $_SESSION['created_at'];
            if (defined('SESSION_REGENERATE_INTERVAL') && $sessionAge > SESSION_REGENERATE_INTERVAL) {
                session_regenerate_id(true);
                $_SESSION['created_at'] = time();
            }
        } else {
            // Initialize timestamp for new sessions
            $_SESSION['created_at'] = time();
        }
    }
   /**
    * Exit point to the core of the zero framework. 
    * 
    * Doing this allows us to exit at any time and still get the "right" output
    * 
    */ 
    public function __destruct() {
        ob_flush(); 
    }

    /**
     *  @function defineConstants
     * Defines constants based on found ini files. 
     * First scans the ZERO_ROOT/app/config/ directory, 
     * then allows other constants to be defined via parameter: 
     *
     * @param @array $key
     *
     * Which is an array of key=>value pairs that are defined as constants 
     *
     * Finally, checks if there's a module-specific config file or config directory
     * to pull and define constants from. 
     */ 
    public function defineConstants(?array $key = null){
        // Since the shift has been towards modules, this should 
        // be able to change in some way that respects the modules a bit more. 
        // Also, see the modular-respective block before the return
        $inis = array_diff(scandir(ZERO_ROOT."app/config/"),['.','..']); 
        foreach ($inis as $ini) {
            $inifile   = ZERO_ROOT."app/config/".$ini; 
            $constants = parse_ini_file($inifile, false, INI_SCANNER_RAW); 
            foreach ($constants as $constant => $value) {
                // if we're defining paths, auto-append the ZERO_ROOT
                define($constant,($ini == "paths.ini"?ZERO_ROOT:"").$value);
            }
        }
        if ($key) {
            foreach ($key as $k => $v) {
                define($k, $v);
            }
        }
        //$mp for module path
        //$mf for module folder
        if(file_exists($inifile=($mp=MODULE_PATH.Request::$Module)."/config.ini")){
            $constants = parse_ini_file($inifile); 
            foreach($constants as $constant=>$value){
                define($constant,$value) ;    
            }
        }
        if(file_exists($mf=$mp."/config/")){
            $inis = array_diff(scandir($mf),['.','..']); 
            foreach ($inis as $ini) {
                $inifile   = $mf.$ini; 
                $constants = parse_ini_file($inifile, false, INI_SCANNER_RAW); 
                foreach ($constants as $constant => $value) {
                    // if we're defining paths, auto-append the ZERO_ROOT
                    define($constant,$value);
                }
            }
        }
        // TODO - maybe this goes somewhere else. 
        if(str_contains($_SERVER['REMOTE_ADDR'], DEV_SUBNET)){
            define("DEVMODE", true); 
            ini_set("xdebug.var_display_max_children", '-1');
            ini_set("xdebug.var_display_max_data", '-1');
            ini_set("xdebug.var_display_max_depth", '-1');
            //ini_set('xdebug.var_display_max_depth', 10);
            //ini_set('xdebug.var_display_max_children', 256);
            //ini_set('xdebug.var_display_max_data', 1024);
        }

        \Zero\Core\PluginLoader::hook($this->plugins, 'afterConstants');

        return $this;

    }

    /**
     *  @function parseRequest
     * Instantiates the Request interpreter. Simple as that. 
     *
     */
    public function parseRequest()
    {
        new Request();
        \Zero\Core\PluginLoader::hook($this->plugins, 'afterParseRequest');
        return $this;
    }


    /**
     *  @function run
     * This is where the real magic happens - 
     * takes 
     * @param $module    the class that must be loaded, defaulting to an Index class.
     * @param $endpoint  the method that gets called in the class.  
     * @param (array) $args  Everything else in the URL gets passed to the endpoint method. 
     *
     */
    public function run($module, $endpoint, $args){
        Console::debug("Module: $module Endpoint: $endpoint Args: ".print_r($args,true)); 
        if ($this->isModule($Module=ucfirst($module))) {
            $Module = "\\Zero\\Module\\".$Module;
        } else {
            // loading Index here so we can reference it as a fallback in Response
            // The reason we don't go ahead and do that here is because modules
            // still get precedence over the built in Index.
            // See Response class for more.
            $this->isModule("Index");
            $Module = "\\Zero\\Core\\Response";
        }

        \Zero\Core\PluginLoader::hook($this->plugins, 'beforeRun', $module, $endpoint, $args);

        $this->checkForAttributes($Module,$endpoint);
        $moduleInstance = new $Module();
        try { 
            $moduleInstance -> {$endpoint}(...$args);
        } catch(\ArgumentCountError $e){
            new Error(400); 
        }

        \Zero\Core\PluginLoader::hook($this->plugins, 'afterRun', $module, $endpoint, $args);
    }

    private function checkForAttributes($Module,$endpoint) : void {

        $reflection = new \ReflectionClass($Module);

        $classAttributes  = $reflection->getAttributes();
        $methodAttributes = [];
        if($reflection->hasMethod($endpoint)){
            $methodAttributes = $reflection->getMethod($endpoint)->getAttributes();
        }

        // Record for DevToolbar — scope-tagged so the tab can distinguish class vs method attributes
        foreach ($classAttributes as $attr) {
            self::$attributeLog[] = ['name' => $attr->getName(), 'scope' => 'class', 'target' => $Module];
        }
        foreach ($methodAttributes as $attr) {
            self::$attributeLog[] = ['name' => $attr->getName(), 'scope' => 'method', 'target' => $Module . '::' . $endpoint];
        }

        $attributes = array_merge($classAttributes, $methodAttributes);

        if(count($attributes) > 0) {
            Console::debug("Checking " . count($attributes) . " attribute(s) for {$Module}::{$endpoint}");
        }

        $this->handleAttributes($attributes);

    }

    private function handleAttributes($attributes){
         foreach($attributes as $attribute){
            $attributeName = $attribute->getName();
            Console::debug("Processing attribute: {$attributeName}");
            $attr = $attribute->newInstance();
            $attr->handler();
         }
    }

    /**
     * autoloader for zero things that are NOT modules... see the isModule method
     * for how modules are loaded. 
     * 
     * This USED to be for loading modules too. 
     * TODO - maybe this isn't needed? or can be simplified further? 
     * TODO - deprecate the hell out of this. 
     */
    public static function autoloader($class){
        $path = explode("\\", strtolower($class)); 
        $step = explode("\\", $class);

        $camel = array_pop($step); 
        //$path[count($path)-1] = ucfirst($path[count($path)-1]?:$path[1])??""; 
        $path[count($path)-1] = $camel; 

        $psrPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$path).".php";
        if(file_exists($psrPath)){
            return require_once($psrPath); 
            //return true; 
        }

        Console::log("Dumb autoloader Failed to load $class after looking in $psrPath"); 

        // try another, simpler approach for module sub-classes: 
        $check = str_replace("Zero", "zero", $class); 
        $check = str_replace("Module", "modules", $check); 
        $check = str_replace("Model", "model", $check); 
        $check = str_replace("Lib", "lib", $check); 
        $step  = explode("\\", $check); 
        $newPath = ROOT_PATH.implode(DIRECTORY_SEPARATOR,$step).".php";

        Console::log("Atempting to load $class after looking in $newPath"); 

        if(file_exists($newPath)){
            return require_once($newPath); 
        }

        Console::log("STILL Failed to load $class after looking in $newPath"); 

        //echo "Failed to load $class after looking in $psrPath<br>"; 
        return false; 
    
    }
    /** 
     * If a class if found in the MODULE_PATH *TODO: why isn't this using MODULE_PATH
     * Then it loads it and returns true. 
     * Doing this ensures the controller can't call things outside of the module path
     */
    private function isModule($module){
        if(
            file_exists($file=$a=ZERO_ROOT."modules/".$module."/".$module.".php")||
            file_exists($file=$b=ZERO_ROOT."modules/".strtolower($module)."/".$module.".php")
        ){
           return require_once $file;  
        } 
        Console::log("Could not find a $module module in $a or $b"); 
        return false; 
    }

    /** 
     * Registers other autoloaders. 
     * May optionally pass your own autoloader functions to be called. 
     * Can pass as a string referencing a callable function, an array or such strings
     * or a function directly, or an array of functions. #TODO: test this... 
     *
     * Finally, also loads the vendor autoload. 
     */
    public function registerAutoloaders(null|string|callable|array $autoloader = null){
        spl_autoload_register("\Zero\Core\Application::autoloader"); 
        // if you want to add external autoloaders
        if ($autoloader) {
            if (is_array($autoloader)) {
                if(is_callable($autoloader)){
                    foreach ($autoloader as $al) {
                        spl_autoload_register($al);
                    }
                }
            } else if (is_callable($autoloader)) {
                    spl_autoload_register($autoloader);
            } 
        }
        // for Composer + PSR compatability
        if (file_exists($file = ROOT_PATH . "vendor/autoload.php")) {
            require $file;
            // TODO - this was shimmed in to get PushNotifications off the ground..
            // This should be removed as zero framework itself should not have 
            // composer dependencies... thus the push notification library should 
            // be moved to zero/lib or some other such benign location. 
        } 
        if (file_exists($file = ZERO_ROOT . "vendor/autoload.php")) {
            require $file;
        }

        // Load plugins after all autoloaders are ready
        $this->plugins = \Zero\Core\PluginLoader::load();
        \Zero\Core\PluginLoader::hook($this->plugins, 'afterAutoloaders');

       return $this;
    }

} 
