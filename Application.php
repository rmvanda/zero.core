<?php

Namespace Zero\Core; 
use \Zero\Core\Request as Request; 
class Application {

    public function __construct(){ 
        
        ob_start();

    }

    public function __destruct() {
    
        ob_flush(); // Why in destruct? Because there are exits() in some places
                    // So this way, if you don't call ob_flush() when you exit()
                    // You'll still get output. 
    }

    /**
     *  @function defineConstants
     * Defines constants based on .ini files or based on 
     * @param @array $key
     * Which is an array of key=>value pairs that are defined as constants 
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
        if(file_exists($inifile=($mp=MODULE_PATH.Request::$Aspect)."/config.ini")){
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
        return $this;
    }


    /**
     *  @function run
     * This is where the real magic happens - 
     * takes 
     * @param $aspect
     * @param $endpoint
     * @param (array) $args ($opt)
     *
     */
    public function run($aspect, $endpoint, $args){
        if ($this->isModule($Aspect=ucfirst($aspect))) {
            //print("Loading $aspect"); 
            $Aspect = "\\Zero\\Module\\".$Aspect; 
            $aspect = new $Aspect();
       } else {
            // loading Index here so we can reference it as a fallback in Response
            // The reason we don't go ahead and do that here is because modules
            // still get precedence over the built in Index 
            $this->isModule($Aspect="Index");
            $aspect = new \Zero\Core\Response();
       }
        $aspect -> {$endpoint}($args);
    }

    public static function autoloader($class){
        $path = explode("\\", strtolower($class)); 
        $step = explode("\\", $class);
        $camel= array_pop($step); 
        $path[count($path)-1] = ucfirst($path[count($path)-1]?:$path[1])??""; //XXX not anymore TESTME
        //$newpath[count($path)-2] = ucfirst(($newpath[count($path)-2]?:$newpath[1])??""); //XXX 
        $psrPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$path).".php";

        $path[count($path)-1] = $camel; 
        $altPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$path).".php"; 

        //$fixPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$newpath).".php"; 
        //echo "Looking in $psrPath and $altPath ... <br>"; 
        if(file_exists($psrPath)){
            require_once($psrPath); 
            return true; 
        } elseif (file_exists($altPath)){ // this should maybe be the way we do it... 
            require_once($altPath); 
            return true; 
        } 
        //if(file_exists($altaltpath=)) //XXX TODO
        //echo "<pre>Neither $psrPath nor $altPath exist <br></pre>"; 
        Console::log("Failed to load $class after looking in $psrPath and $altPath"); 
    }

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


    public function registerAutoloaders($autoloader = null)
    {
        require ZERO_ROOT."lib/zxc/ZXC.php"; 
        spl_autoload_register("\Zero\Core\Application::autoloader"); 
        // if you want to add external autoloaders
        if ($autoloader) {
            if (is_array($autoloader)) {
                if(is_callable($autoloader)){
                    foreach ($autoloader as $al) {
                        spl_autoload_register($al);
                    }
                }
            }else 
                if (is_callable($autoloader)) {
                    spl_autoload_register($autoloader);
                } 
        }
        // for Composer + PSR compatability
        if (file_exists($file = ROOT_PATH . "vendor/autoload.php")) {
            require $file;
        }
       spl_autoload_register("\Zero\Core\Application::errorHandler");
       return $this;
    }

    public function errorHandler($class)
    {
        new Error(404, "We couldn't find $class."); 
        if(defined("DEVMODE") && DEVMODE == true){
            xdebug_print_function_stack(); 
        }
    }

} 

