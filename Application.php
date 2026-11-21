<?php

namespace Zero\Core;
use Zero\Core\Exception\HTTPError;
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

require_once __DIR__ . "/Modules.php";

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
        // Wording first: the site's app/strings/*.ini. A key defined here is
        // not defined again by a config file. The fallbacks for SITE_NAME and
        // SITE_SHORT_NAME come after the config files are read.
        Strings::loadSite(ZERO_ROOT . 'app/strings/');
        // Since the shift has been towards modules, this should 
        // be able to change in some way that respects the modules a bit more. 
        // Also, see the modular-respective block before the return
        // A config file the site does not have is read from core/skeleton/config/.
        foreach (self::configFiles(ZERO_ROOT."app/config/", __DIR__."/skeleton/config/") as $inifile) {
            $constants = parse_ini_file($inifile, false, INI_SCANNER_RAW); 
            foreach ($constants as $constant => $value) {
                // if we're defining paths, auto-append the ZERO_ROOT
                self::defineOnce($constant,self::configValue($inifile,$value),$inifile);
            }
        }
        Strings::defineSiteFallbacks();
        // Core's own wording for whatever the site did not word itself.
        Strings::loadDefaults();
        if ($key) {
            foreach ($key as $k => $v) {
                define($k, $v);
            }
        }
        // The module's own constants. A module that is off or does not exist
        // contributes none.
        //$mp for module path
        //$mf for module folder
        self::loadModuleConfig(Request::$Module);
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

    /**
     * Bans the client of a request no legitimate client makes: a probe for a
     * .php file, a .env file or a WordPress path. The ban is a Cloudflare
     * block rule and the request ends here.
     *
     * A client listed in app/config/allowed-ips.lst is never banned: its
     * request carries on and is answered like any other (a 404, usually).
     */
    public function banNefariousRequest(){
        $reason = self::nefariousReason($_SERVER['REQUEST_URI'] ?? '');
        if($reason === null){
            return;
        }

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

        // The list is read only here, once a request is about to be banned, so
        // an ordinary request never opens the file. No database is involved:
        // this runs before anything connects to one.
        if(self::isAllowedIp($ip, ZERO_ROOT . 'app/config/allowed-ips.lst')){
            return;
        }

        CloudFlare::block($ip, "{$reason} ({$_SERVER['REQUEST_URI']})");
        die("permabanned");
    }

    /**
     * Why a request for $uri gets its client banned, or null when it does not.
     */
    public static function nefariousReason(string $uri): ?string
    {
        if(str_contains($uri, ".php")){
            return "trying to call some .php file.";
        }
        if(str_contains($uri, ".env")){
            return "trying to call some .env file.";
        }
        if(str_contains($uri, "wp-")){
            return "trying to call some wordpress file.";
        }
        return null;
    }

    /**
     * The entries of an allowed-ips list file: one plain IP or CIDR range per
     * line. Blank lines are skipped and `#` starts a comment, on its own line
     * or after an entry. A file that is missing or unreadable is an empty list.
     * A site need not have the file: a missing one says nothing, and one that
     * is there but cannot be read leaves an info line in the log.
     *
     * @return string[]
     */
    public static function allowedIps(string $file): array
    {
        if(!is_file($file)){
            return [];
        }
        if(!is_readable($file)){
            Console::info("Allowed-ips list {$file} cannot be read, so no client is exempt from a ban");
            return [];
        }
        $entries = [];
        foreach(file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line){
            $entry = trim(explode('#', $line, 2)[0]);
            if($entry !== ''){
                $entries[] = $entry;
            }
        }
        return $entries;
    }

    /**
     * Is $ip on the allowed-ips list in $file? An entry that is not a valid
     * IP or range matches nothing, and so does an empty $ip.
     */
    public static function isAllowedIp(string $ip, string $file): bool
    {
        return $ip !== '' && \Zero\Lib\Net\Cidr::matchesAny($ip, self::allowedIps($file));
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
        $this->banNefariousRequest(); 

        // Two modules of one name in two folders is a configuration error, not
        // a missing page: the request fails with a bare 500 and nothing else.
        if (!self::checkModules(defined('WEB_ROOT') ? WEB_ROOT : null, ZERO_ROOT . 'storage/cache/modules-assets.marker')) {
            http_response_code(500);
            header('Content-Type: text/plain');
            echo "Internal Server Error";
            return;
        }

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
     * core/tests/module/AttributeOrderTest.php enforces it across every module by
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
     * autoloader for every Zero class: core, lib, entity, app/lib and module
     * classes (controllers, models, libs, submodules). classFile() says where
     * a class lives; this loads it. Names outside the Zero\ namespaces belong
     * to the other autoloaders.
     *
     * This USED to be for loading modules too. 
     * TODO - maybe this isn't needed? or can be simplified further? 
     * TODO - deprecate the hell out of this. 
     * (Now it loads module classes too, through classFile(); the notes above
     * are the owner's, from when it was the "dumb" autoloader for non-modules.)
     */
    public static function autoloader($class){
        $file = self::classFile($class);
        if ($file !== null) {
            return require_once($file);
            //return true; 
        }

        if (str_starts_with(ltrim($class, "\\"), "Zero\\")) {
            Console::warn("Autoloader failed to place $class");
        }

        //echo "Failed to load $class after looking in $psrPath<br>"; 
        //$path[count($path)-1] = ucfirst($path[count($path)-1]?:$path[1])??""; 
        // (classFile() now computes the path these lines were about.)
        return false;
    }

    /**
     * The file a class name maps to, or null when the namespace map has no
     * such file. The map, in order:
     *
     *   Zero\Core\Attribute\  core/attribute/
     *   Zero\Core\            core/
     *   Zero\Lib\             core/lib/
     *   Zero\Entity\          core/entity/, then the site's entity/ (ENTITY_SITE_DIR
     *                         when defined). A site class with the name of a
     *                         framework class is never loaded: the framework's wins.
     *   Zero\Aux\             aux/
     *   Zero\App\Lib\        app/lib/
     *   Zero\Module\{X}\...  the folder Modules::path(X) names
     *
     * The remaining namespace segments are folders. Each is tried as written,
     * then as a submodule (module classes only: submodule/{Segment}/), then
     * lowercased (Model -> model/, Lib -> lib/). The class of a submodule
     * resolves under submodule/{Class}/ the same way.
     *
     * Every segment must be a PHP identifier, and the file found must lie
     * inside the folder its rule names (a module's own folder for a module
     * class); anything else is null. The framework root is the folder that
     * holds core/, i.e. dirname(__DIR__): core/ sits directly inside the site
     * root, and the site folder may carry any name.
     */
    public static function classFile(string $class): ?string
    {
        $class = ltrim($class, "\\");
        if (!self::isQualifiedName($class)) {
            return null;
        }
        $root  = dirname(__DIR__) . "/";

        foreach ([
            "Zero\\Core\\Attribute\\" => "core/attribute/",
            "Zero\\Core\\"            => "core/",
            "Zero\\Lib\\"             => "core/lib/",
            "Zero\\Entity\\"          => "core/entity/",
            "Zero\\Aux\\"             => "aux/",
            "Zero\\App\\Lib\\"        => "app/lib/",
        ] as $prefix => $folder) {
            if (str_starts_with($class, $prefix)) {
                $rest = substr($class, strlen($prefix));
                $file = self::findIn($root . $folder, $rest, false);
                if ($file === null && $prefix === "Zero\\Entity\\") {
                    // A site's own entity classes live in its entity/ folder, a second allowed base.
                    $site = defined("ENTITY_SITE_DIR") ? rtrim(ENTITY_SITE_DIR, "/") . "/" : $root . "entity/";
                    $file = self::findIn($site, $rest, false);
                }
                return $file;
            }
        }

        if (str_starts_with($class, "Zero\\Module\\")) {
            $rest = substr($class, strlen("Zero\\Module\\"));
            $name = strstr($rest, "\\", true);
            $module = $name === false ? $rest : $name;
            $dir = Modules::path($module);
            if ($dir === null) {
                return null;
            }
            return self::findIn($dir, $name === false ? $module : substr($rest, strlen($name) + 1), true);
        }

        return null;
    }

    /**
     * Whether every namespace segment of a class name is a PHP identifier.
     */
    public static function isQualifiedName(string $class): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/D', ltrim($class, "\\")) === 1;
    }

    /**
     * Whether a URL's module name is one identifier: a URL names a
     * controller, never a nested class or a path.
     */
    public static function isModuleName(string $name): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*$/D', $name) === 1;
    }

    /**
     * Walk the namespace segments of $rest down from $base; the last one is
     * the class, the rest are folders. The file must lie inside $base.
     */
    private static function findIn(string $base, string $rest, bool $submodules): ?string
    {
        $dir = $base;
        $segments = explode("\\", $rest);
        $class = array_pop($segments);

        foreach ($segments as $segment) {
            if (is_dir($next = $dir . $segment)) {
                $dir = $next . "/";
            } elseif ($submodules && is_dir($next = $dir . "submodule/" . $segment)) {
                $dir = $next . "/";
            } elseif (is_dir($next = $dir . strtolower($segment))) {
                $dir = $next . "/";
            } else {
                return null;
            }
        }

        if (is_file($file = $dir . $class . ".php")
            || ($submodules && is_file($file = $dir . "submodule/" . $class . "/" . $class . ".php"))) {
            $real = realpath($file);
            $inside = realpath($base);
            if ($real !== false && $inside !== false && str_starts_with($real, rtrim($inside, "/") . "/")) {
                return $file;
            }
        }

        return null;
    }

    /**
     * The start-of-request check on the module folders. A name held by two
     * roots is logged once through Console (the message names both paths and
     * is never shown to a visitor) and false is returned. Otherwise a module
     * that is switched off loses its public asset links (when a web root is
     * given) and true is returned. The links are cleaned only when the set of
     * disabled modules differs from the one the marker file recorded.
     */
    public static function checkModules(?string $webRoot, string $marker): bool
    {
        try {
            Modules::assertNoClash();
        } catch (\RuntimeException $e) {
            Console::error($e->getMessage());
            return false;
        }
        if ($webRoot !== null) {
            Modules::dropDisabledAssetLinksWhenChanged($marker, $webRoot);
        }
        return true;
    }

    /**
     * The module's directory (trailing slash) when the module exists and is
     * switched on, otherwise null. Its config.ini and config/ are loaded from
     * there; a module that is off contributes no constants.
     */
    public static function moduleConfigDir(string $module): ?string
    {
        return self::isModuleName($module) ? Modules::routablePath($module) : null;
    }

    /**
     * The config files to read: every file in the site's config directory,
     * then each file of core's skeleton config directory the site does not
     * have. A site overrides a skeleton file by having one of the same name.
     *
     * @return string[] full paths
     */
    public static function configFiles(string $siteDir, string $skeletonDir): array
    {
        $files = [];
        // Only .ini files hold constants. Anything else in the folder
        // (allowed-ips.lst, an editor's swap file) is not a config file.
        foreach (is_dir($siteDir) ? array_diff(scandir($siteDir), ['.', '..']) : [] as $name) {
            if (str_ends_with($name, '.ini')) {
                $files[$name] = $siteDir . $name;
            }
        }
        foreach (is_dir($skeletonDir) ? array_diff(scandir($skeletonDir), ['.', '..']) : [] as $name) {
            if (str_ends_with($name, '.ini')) {
                $files[$name] ??= $skeletonDir . $name;
            }
        }
        return array_values($files);
    }

    /**
     * A config file's value as it is defined: the values of a file named
     * paths.ini are paths and get ZERO_ROOT in front.
     */
    public static function configValue(string $file, mixed $value): mixed
    {
        return (basename($file) === 'paths.ini' ? ZERO_ROOT : '') . $value;
    }

    /**
     * Define a module's own wording and constants: its strings/, its
     * config.ini and each file of its config/. A module that is off or does
     * not exist contributes none.
     *
     * The live module's are defined at the start of a request. Admin calls
     * this for the module whose admin offering it routes to, because that
     * module is not the live one. A constant that exists keeps its value.
     */
    public static function loadModuleConfig(string $module): void
    {
        $mp = self::moduleConfigDir($module);
        if($mp !== null){
            Strings::loadModule($module);
        }
        if($mp !== null && file_exists($inifile=$mp."config.ini")){
            $constants = parse_ini_file($inifile); 
            foreach($constants as $constant=>$value){
                self::defineOnce($constant,$value,$inifile) ;    
            }
        }
        if($mp !== null && file_exists($mf=$mp."config/")){
            $inis = array_diff(scandir($mf),['.','..']); 
            foreach ($inis as $ini) {
                $inifile   = $mf.$ini; 
                $constants = parse_ini_file($inifile, false, INI_SCANNER_RAW); 
                foreach ($constants as $constant => $value) {
                    // if we're defining paths, auto-append the ZERO_ROOT
                    self::defineOnce($constant,$value,$inifile);
                }
            }
        }
    }

    /**
     * Define a constant from a config file unless something (a strings file,
     * an earlier config file) already did; a site should not hold the same
     * key in two places, so the repeat is named in the log.
     */
    private static function defineOnce(string $constant, mixed $value, string $file): void
    {
        if (!Strings::isConstantName($constant)) {
            Console::warn("Constant name $constant in $file is not valid and is skipped");
            return;
        }
        if (defined($constant)) {
            Console::warn("Constant $constant from $file is already defined and keeps its value");
            return;
        }
        define($constant, $value);
    }

    /**
     * The controller file of a module that exists and is switched on, or null.
     * A module that is off is answered exactly as one that does not exist.
     */
    public static function controllerFile(string $module): ?string
    {
        if (!self::isModuleName($module) || !Modules::routable($module)) {
            return null;
        }
        return self::classFile("Zero\\Module\\" . (Modules::name($module) ?? $module));
    }

    /** 
     * If a class if found in the MODULE_PATH *TODO: why isn't this using MODULE_PATH
     * (It looks in the folder Modules::path() names, which covers every module
     * root, and only for a module that is switched on.)
     * If the module's controller class file exists and the module is on, load
     * it and return true. Modules::path() says where the module lives. 
     * Doing this ensures the controller can't call things outside of the module path
     */
    private function isModule($module){
        $file = self::controllerFile($module);
        if ($file !== null) {
           return require_once $file;  
        } 
        Console::debug("Could not find a $module module that is switched on in " . (Modules::path($module) ?? "any module folder"));
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
        // the autoloader asks Modules where a module lives
        Modules::configure([MODULE_PATH, ZERO_ROOT . "core/modules/"], ZERO_ROOT . "app/modules.ini");
        spl_autoload_register("\Zero\Core\Application::autoloader"); 
        // if you want to add external autoloaders
        // One autoloader (a callable array such as [$object, 'method'] included)
        // or a list of them.
        if ($autoloader) {
            if (is_callable($autoloader)) {
                spl_autoload_register($autoloader);
            } else if (is_array($autoloader)) {
                foreach ($autoloader as $al) {
                    if (is_callable($al)) {
                        spl_autoload_register($al);
                    }
                }
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
