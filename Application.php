<?php

Namespace Zero\Core; 

class Application {

    public function __construct($opts=null){ // usually an array.
//        if($_SERVER['SERVER_NAME'] == 'localhost'){
            define("DEVMODE",true);
            define("LOG_FILE", ZERO_ROOT."app.log"); 
            include ZERO_ROOT."core/Console.php"; 
            ini_set("html_errors",1); 
            ini_set("display_errors", "On");
            error_reporting(E_ALL & ~E_NOTICE); 
/*        } else {
            define("DEVMODE",false); 
            // we can leave these out after I figure out why my php.ini is blank <_< 
            ini_set("html_errors",0); 
            ini_set("display_errors", "Off");
            error_reporting(~E_ALL); 
        }
        */ 

        ob_start();

        if($opts['autorun']===false|| 
           $opts           ===false
          ){
            return; 
        }

        $this-> registerAutoloaders($opts['autoloaders']) ;
        $this-> parseRequest() ;
        $this-> defineConstants($opts['constants']);
        $this-> fetchExtensions($opts['extensions']); 
//            -> getClientSession()
            //           -> finalizeRoute() 
        $this-> run(
                    Request::$aspect,
                    Request::$endpoint, 
                    Request::$args
                  );
        
    }

    public function __destruct() {
    
        ob_flush(); // Why in destruct? Because there are exit()'s everywhere. 
                    // This way, there is no escape. 
    }

    

    /**
     *  @function defineConstants
     * Defines constants based on .ini files or based on 
     * @param @array $key
     * Which is an array of key=>value pairs that are defined as constants 
     */ 

    public function defineConstants(array $key = null){

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
     *  @function fetchExtensions
     *  Loads additional things to make everyone's lives easier. 
     */ 
    public function fetchExtensions($extensions = null){

       // require __DIR__ . "/../dev/Console/Console.php";
       // require __DIR__ . "/Extensions.php";
        //require __DIR__ . "/../../modules/Err/Err.php";

        if (is_array($extensions)) {
            foreach ($extensions as $extension) {
                if(file_exists($extension)){
                    require $extension;
                }
            }
        } else if ($extensions) {
            if (file_exists($extensions)) {
                require $extensions;
            } else {} //#TODO log warning
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
    public function run($aspect, $endpoint, $args)
    {
        //TODO $NamedAspect = $this->hasNamespace(ucfirst($aspect))) { 
        if ($this->isModule($Aspect=ucfirst($aspect))) {
        //Console::log("$aspect found and loaded"); 
            $Aspect = "\\Zero\\Module\\".$Aspect; 
            $aspect = new $Aspect();
        // This bit is new - the way I used to handle access control is via the "finalizeRoute" method
        // I think this way might be better because it also provides a more reasonable hook for 
        // automatically starting a session... 
        } else if($this->isProtected($Aspect) && Client::sessionExists()){ 
            $this->getClientSession(); 
            if(Client::hasAccess($Aspect, $endpoint)){
                $Aspect = new $Aspect();
            }
        } else {
            //Console::log("$aspect not found and not loaded."); 
            $aspect = new \Zero\Core\Response();

        }
        // TODO  ? XXX
        // if function does not exist, check for file
        // if file does not exist, check Index class for something 
        
        $aspect -> {$endpoint}($args);
    }

    public function isProtected($module){
    
        return false; // not yet implemented

    }
    
    //No longer in use.... good?
    // the new autoloader makes it obsolete. 
    /*
    public function zeroCoreLoader(){
    
        // In order of importance to core functionality.
        // Zero's core framework configurations will be based on this. 
        //
        require __DIR__."/Request.php";     
        require __DIR__."/Response.php";
    
        require __DIR__."/Client.php"; //Sooner the better, for ACL.. but...
    
    
        //Other logic can be set, here - 
        //        require __DIR__."/../defaults/Index/Index.php"; 
    
        require __DIR__."/Model.php"; // Database adapter.
    
        require __DIR__."/Module.php";
        //require __DIR__."/Restricted.php";
        //require __DIR__."/Whitelist.php";
     
        //       require __DIR__ . "/../dev/Console/Console.php";
        //       require __DIR__ . "/../defaults/Err/Err.php";
     
        require __DIR__."/Err.php"; 

    }*/

    

    public function load($filename, $path = null)
    {
        die("$filename with load"); 
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

//XXX Breaks in cases of \GlobalClass 
//XXX Breaks in cases of CamelCasedClasses. But we knew that already...
// On the upside, this is a very sane, PSR compliant autoloader. 
    public function autoloader($class){
        $path = explode("\\", strtolower($class)); 
        $path[count($path)-1] = ucfirst($path[count($path)-1]?:$path[1]); //XXX not anymore TESTME

        $psrPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$path).".php";
        if(file_exists($psrPath)){
            require_once($psrPath); 
            return true; 
        }
        Console::log("Devmode set to ".DEVMODE);
        Console::log("Failed to load $class after looking in $psrPath"); 
  }

    private function isModule($module){
        
        if(file_exists($file=ZERO_ROOT."modules/".$module."/".$module.".php")){
           return require_once $file;  
        }
        return false; 

    }


    public function registerAutoloaders($autoloader = null)
    {
        require ZERO_ROOT."lib/zxc/ZXC.php"; 
        require __DIR__."/Extensions.php";

       spl_autoload_register("self::autoloader"); 
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
 
       spl_autoload_register("self::errorHandler");
       return $this;
    }

    public function getClientSession(){ 
        new Client(); 
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
                      new Error(403);
                      } else {
        if ($_SERVER['HTTP_HOST'] != PRIMARY_DOMAIN && 
                !$this -> request -> access
           ) {
            header("Location: " . 
                    $this -> request -> protocol . 
                    "://" . PRIMARY_DOMAIN
                  );
            exit();
        } elseif ($_SERVER['HTTP_HOST'] == ADMIN_DOMAIN) {
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
        //}
    }

    public function errorHandler($class)
    {
        new Error(404, "We couldn't find the page you are looking for."); 
        xdebug_print_function_stack(); 
    }

} 

