<?php

Namespace Zero\Core; 

class Application {

    public function __construct(array $opts=null){

        $this 
            -> registerAutoloaders($opts['autoloaders']) 
            -> parseRequest() 
            -> defineConstants($opts['constants'])
            -> fetchExtensions($opts['extensions']) 
//            -> getClientSession()
            //           -> finalizeRoute() 
            -> run(
                    Request::$aspect,
                    Request::$endpoint, 
                    Request::$args
                  );

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

    }

    

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
    public function autoloader($class){
        $path = explode("\\", strtolower($class)); 
        $path[count($path)-1] = ucfirst($path[count($path)-1]?:$path[1]); //XXX not anymore TESTME
//        if(file_exist((
        $psrPath=ROOT_PATH.implode(DIRECTORY_SEPARATOR,$path).".php";
        if(file_exists($psrPath)){
            require_once($psrPath); 
            return true; 
        }
//        if(defined(DEVMODE)&&DEVMODE===true){ 
            trigger_error("Failed to load $class after looking in $psrPath");die(); 
//        } else {
//            new Error(500,"Oops! Something went missing...");     
//        }
    }

    private function isModule($module){
        
        if(file_exists($file=ZERO_ROOT."modules/".$module."/".$module.".php")){
           return require_once $file;  
        }
        echo "Failed to load via new autoloader: ".$file; die(); 
        return false; 

    }


    public function registerAutoloaders($autoloader = null)
    {
        require ZERO_ROOT."lib/zxc/ZXC.php"; 
        require __DIR__."/Extensions.php";
        // Framework manages it's core, first
//        $this->zeroCoreLoader();

        spl_autoload_register("self::autoloader"); 
        //spl_autoload_register("self::load");

        // for Composer + PSR compatability
        if (file_exists($file = ZERO_ROOT . "vendor/autoload.php")) {
            require $file;
        }
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
        if (defined("DEVMODE") && DEVMODE == true) {
           // Console::log() -> error($class);
        }
        xdebug_print_function_stack(); 
        new Error(404, "There is no such thing as $class");
    }

} 

