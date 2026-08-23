<?php

namespace Zero\Core;
//use \Zero\Core\Request as Request;
use \Zero\Lib\CloudFlare\CloudFlare;

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
        /*
         * Application timezone.
         *
         * PHP's date.timezone is set to UTC in php.ini while MySQL runs on
         * SYSTEM, so date() and NOW() disagreed by the UTC offset. Any row
         * written in the evening got a PHP-derived date one day AHEAD of its
         * own MySQL created_at — a receipt dated after the moment it was
         * recorded. Receipt dates in particular fall back to the upload date,
         * so this quietly mis-dated real data every night.
         *
         * Defining TIMEZONE in app/config aligns PHP with the database and the
         * host. Unset, nothing changes and PHP keeps whatever php.ini gave it.
         */
        if (defined('TIMEZONE') && TIMEZONE !== '') {
            if (!date_default_timezone_set(TIMEZONE)) {
                Console::warn('Invalid TIMEZONE constant: ' . TIMEZONE);
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

    public function banmotherfuckers(){
        // CF-Connecting-IP is the true client IP behind Cloudflare (single, reliable)
        // X-Forwarded-For can be a chain; grab the first entry as fallback
        //
        // This used to inline that precedence as a ?? chain, which had a dead
        // branch: explode(',', '')[0] returns '' rather than null, so
        // `?? $_SERVER['REMOTE_ADDR']` was never reached and a direct-to-origin
        // request resolved to ''. The bans below then called CloudFlare::block('')
        // — an empty rule that matches nothing — so a scanner probing for .php
        // straight at the origin was never actually banned, repeatably.
        $ip = Request::clientIp();

        if(str_contains($_SERVER['REQUEST_URI'], ".php")){
            CloudFlare::block($ip, "trying to call some .php file. ({$_SERVER['REQUEST_URI']})");
            die("permabanned");
        }
        if(str_contains($_SERVER['REQUEST_URI'], ".env")){
            CloudFlare::block($ip, "trying to call some .env file. ({$_SERVER['REQUEST_URI']})");
            die("permabanned");
        }
        if(str_contains($_SERVER['REQUEST_URI'], "wp-")){
            CloudFlare::block($ip, "trying to call some wordpress file. ({$_SERVER['REQUEST_URI']})");
            die("permabanned");
        }
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
        $this->banmotherfuckers(); 
        //Console::log("Module: $module Endpoint: $endpoint Args: ".print_r($args,true)); 

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

        $moduleInstance = new $Module();
        try {
            self::checkAttributes($Module,$endpoint);
            $moduleInstance -> {$endpoint}(...$args);
        } catch(HTTPError $e){
            $moduleInstance->renderError($e);
        } catch(\ArgumentCountError $e){
            $moduleInstance->renderError(new HTTPError(400));
        }

        \Zero\Core\PluginLoader::hook($this->plugins, 'afterRun', $module, $endpoint, $args);
    }

    /**
     * Run every attribute that gates $class::$endpoint.
     *
     * Public and static because Application::run() is NOT the only dispatcher.
     * Module::__call() resolves submodules and calls their methods directly, and
     * Admin::routeToModuleAdmin() does the same for module-admin controllers —
     * so every attribute declared inside modules/{X}/submodule/ was dead until
     * those two call this as well. Measured before the fix: an anonymous
     * GET /ttrpg/hexmap/dm returned 200 with #[RequireAuthLevel(1)] on the
     * method, and GET /ttrpg/hexmap/save returned 400 rather than 405 with
     * #[AllowedMethods('POST')] on it.
     *
     * Each dispatch level checks its OWN (class, endpoint) pair, so the gates
     * are additive down the nesting chain and nothing is evaluated twice:
     * /ttrpg/hexmap/save runs (Ttrpg, 'hexmap') here — Ttrpg's class attributes,
     * since Ttrpg has no hexmap() method — and then (Ttrpg\Hexmap, 'save') from
     * Module::__call(). Class-level attributes are not inherited by PHP
     * reflection, and a submodule extends Module rather than its parent module,
     * so the first level's attributes cannot re-fire at the second.
     *
     * @param string $class    Fully-qualified class that will serve the request.
     * @param string $endpoint Method name resolved from the URL segment.
     */
    public static function checkAttributes(string $class, string $endpoint) : void {

        $resolved = self::resolveAttributes($class, $endpoint);

        // Record for DevToolbar — scope-tagged so the tab can distinguish class vs method attributes.
        // `overridden` marks class-level attrs shadowed by a same-type method-level attr.
        foreach ($resolved['class'] as $attr) {
            self::$attributeLog[] = [
                'name'       => $attr->getName(),
                'scope'      => 'class',
                'target'     => $class,
                'overridden' => $resolved['overridden'][$attr->getName()] ?? false,
            ];
        }
        foreach ($resolved['method'] as $attr) {
            self::$attributeLog[] = [
                'name'   => $attr->getName(),
                // 'call' marks an attribute read from __call() on behalf of a
                // virtual endpoint that has no method of its own.
                'scope'  => $resolved['scope'],
                'target' => $class . '::' . $endpoint,
            ];
        }

        self::handleAttributes($resolved['effective']);

    }

    /**
     * Work out which attributes gate $class::$endpoint, and in what order — the
     * decision half of checkAttributes(), with none of the running.
     *
     * Split out so it can be asked the question without answering it. Attribute
     * ORDER is a real invariant — #[AllowWithToken] seeds the session that
     * #[RequireLogin] then checks, so a class-level RequireLogin above a
     * method-level AllowWithToken silently breaks token auth — and
     * core/tests/AttributeOrderTest.php enforces it across every module by
     * calling this for every endpoint. That test has to see the sequence the
     * framework will actually run, including the replace/additive filtering and
     * the __call fallback below; reimplementing those rules in the test would
     * mean the test could keep passing while this method changed underneath it.
     *
     * @return array{
     *     class:      \ReflectionAttribute[],           all class-level attributes, overridden ones included
     *     method:     \ReflectionAttribute[],           all method-level (or __call) attributes
     *     effective:  \ReflectionAttribute[],           exactly what runs, in the order it runs
     *     scope:      string,                           'method', or 'call' for a virtual endpoint
     *     overridden: array<string,bool>                class-level attribute names shadowed by the method
     * }
     */
    public static function resolveAttributes(string $class, string $endpoint) : array {

        $reflection = new \ReflectionClass($class);

        $classAttributes  = self::gates($reflection->getAttributes());
        $methodAttributes = [];
        $methodScope      = 'method';
        if($reflection->hasMethod($endpoint)){
            $methodAttributes = self::gates($reflection->getMethod($endpoint)->getAttributes());
        } elseif($reflection->hasMethod('__call')){
            // Virtual endpoint. The URL segment is data — a paste slug, a short
            // link, a button name, an image path — not a method, so it has no
            // ReflectionMethod of its own and can carry no attributes. Reading
            // __call()'s attributes lets a class gate everything it dispatches
            // dynamically, which was previously impossible: only class-level
            // attributes applied, and nothing could be said per virtual route.
            //
            // Additive only. A class-level gate still applies when __call()
            // declares nothing, so this cannot express "class gated, __call
            // deliberately public" — that case is served by dropping the
            // class-level attribute and gating each real method instead, which
            // is what modules/TheButton already does.
            //
            // Also fires when $endpoint resolves to a SUBMODULE, since the
            // parent has no method of that name either. The submodule's own
            // check then runs on top, so the effect is a superset gate — it
            // fails safe, but declare with that in mind.
            $methodAttributes = self::gates($reflection->getMethod('__call')->getAttributes());
            $methodScope      = 'call';
        }

        // Method-level attributes override class-level attributes of the same type.
        // If a method declares any attribute of type X, class-level attributes of
        // type X are skipped for this endpoint (replace semantics).
        //
        // Replace is the right default because the attributes it governs name a
        // DESTINATION or a CONSTRAINT, and two of those contradict each other:
        // #[RequireLogin('/notes/welcome')] on a method exists precisely to
        // override the class's landing page, and running both would send the
        // visitor to whichever fired first.
        $methodNames = array_flip(array_map(
            fn($a) => $a->getName(),
            $methodAttributes
        ));

        $effectiveClass = array_filter(
            $classAttributes,
            fn($a) => !isset($methodNames[$a->getName()])
                   || self::isAdditive($a->getName())
        );

        $overridden = [];
        foreach ($classAttributes as $attr) {
            $overridden[$attr->getName()] = isset($methodNames[$attr->getName()])
                                         && !self::isAdditive($attr->getName());
        }

        return [
            'class'      => $classAttributes,
            'method'     => $methodAttributes,
            'effective'  => array_merge($effectiveClass, $methodAttributes),
            'scope'      => $methodScope,
            'overridden' => $overridden,
        ];
    }

    /**
     * Does a method-level declaration of this attribute ADD to the class-level
     * one rather than replace it?
     *
     * One type, named rather than abstracted behind a marker interface — an
     * interface for a set of size one is machinery without a second case to
     * justify it. Add to the list if a second type ever earns it.
     *
     * #[RequirePermission] is that type because a permission is neither a
     * destination nor a constraint: two of them are a longer list, not a
     * contradiction. Under replace semantics, modules/Receipts declaring
     * 'allow.receipt.view' on the class and 'allow.receipt.edit' on ten methods
     * meant those methods required edit ALONE — measured, not inferred: a user
     * holding only the method-level key passed, and the same user was denied on
     * a sibling endpoint that declared no attribute of its own. A grant that
     * reads narrower was in fact wider, and nothing surfaced it.
     *
     * Both declarations now run, each checking its own list, so the effective
     * requirement is the union. Nothing in any module changes — the attributes
     * already written start meaning what they look like they mean.
     */
    /**
     * Keep only the attributes this framework is meant to run.
     *
     * handleAttributes() calls handler() on everything it is given, so anything
     * reaching it that is not one of ours is a fatal. PHP's own built-ins are
     * the live case: Response carries #[\AllowDynamicProperties], and
     * Application::run() passes \Zero\Core\Response to checkAttributes()
     * whenever the URL names no module — which made every standalone page and
     * every 404 a 500.
     *
     * Namespace is the test because that is the only contract these attributes
     * share; they have no common interface or base class, and isAdditive()
     * identifies them the same way. Filtered here at the point of gathering
     * rather than in handleAttributes() so 'effective' keeps meaning exactly
     * what runs — AttributeOrderTest reads it precisely to avoid that drift.
     */
    private static function gates(array $attributes): array {
        return array_values(array_filter(
            $attributes,
            fn($a) => str_starts_with($a->getName(), 'Zero\\Core\\Attribute\\')
        ));
    }

    private static function isAdditive(string $attributeClass): bool {
        return $attributeClass === \Zero\Core\Attribute\RequirePermission::class;
    }

    private static function handleAttributes($attributes){
         foreach($attributes as $attribute){
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

        Console::warn("Dumb autoloader Failed to load $class after looking in $psrPath");

        // try another, simpler approach for module sub-classes: 
        $check = str_replace("Zero", "zero", $class); 
        $check = str_replace("Module", "modules", $check); 
        $check = str_replace("Model", "model", $check); 
        $check = str_replace("Lib", "lib", $check); 
        $step  = explode("\\", $check); 
        $newPath = ROOT_PATH.implode(DIRECTORY_SEPARATOR,$step).".php";

        Console::debug("Atempting to load $class after looking in $newPath");

        if(file_exists($newPath)){
            return require_once($newPath); 
        }

        Console::warn("STILL Failed to load $class after looking in $newPath");

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
        Console::warn("Could not find a $module module in $a or $b");
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
