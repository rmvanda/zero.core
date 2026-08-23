<?php

namespace Zero\Core;

/*
 * Assigning to an undeclared property is deprecated as of PHP 8.2 and becomes
 * an error in PHP 9. This framework assigns view data to undeclared properties
 * by design — defineBasePaths() writes $this->{$path."Path"} from a list, and
 * every module sets whatever its views read — so the deprecation fires on
 * essentially every request.
 *
 * Opting out here rather than declaring the properties: the attribute is
 * inherited, so this one line covers Module and every module that extends it.
 * Leading backslash because this file is in namespace Zero\Core and the
 * attribute is a global-namespace class.
 */
#[\AllowDynamicProperties]
class Response
{

    public $title;  // TODO - this isn't quite hooked in correctly. 

    protected $status; 
    protected $data; 

    protected $included = array(); 

    protected static $built = false; 

    public $paths = ["frame", "view", "component", "model"];

    public function __construct($altconfig = null){
        $this->defineBasePaths();
        $this->registerAutoloaders();
    }

    public function __destruct(){
        if(!static::$built){
            $this->build($this->body);
        }
    }

    protected function registerAutoloaders(){
        $modelPath = $this->modelPath;

        spl_autoload_register(function($class) use ($modelPath) {
            // Extract just the class name from the fully qualified namespace
            $parts = explode('\\', $class);
            $className = array_pop($parts);

            // Check if it's in a Model namespace and modelPath is set
            if (strpos($class, '\\Model\\') !== false && !empty($modelPath)) {
                $file = $modelPath . $className . '.php';
                if (file_exists($file)) {
                    require_once $file;
                    return true;
                }
            }

            return false;
        }, true, true);
    }

    protected function defineBasePaths(){
        //$class_info = new \ReflectionClass(get_class($this));
        //$dirname    = dirname($class_info->getFileName())."/";
        $dirname = MODULE_PATH.Request::$Module."/";


        foreach($this->paths as $path){
            $pathString = $path."Path";
            if(!$this->$pathString && is_dir($dirname.$path)){
// Paths resolve per request and are not persisted. Error pages need no help
// here: renderError() runs on the live module instance, whose framePath this
// method has already set. Cross-request frame inheritance, for shared modules
// that should look like whatever sent the visitor to them, is adoptFrameFrom().
                $this->$pathString = $dirname.$path."/";
            } else {
                // this is not valid for model, but whatever. FIXME later
                $this->$pathString = ZERO_ROOT."app/frontend/".$path."/";
            }
            //echo "Set $pathString to: {$this->$pathString}<br>";
        }
    }

    /**
     * Adopt the frame of the module that referred the client here.
     *
     * For shared modules — ones reachable from anywhere, that should look like
     * part of whatever sent the visitor to them. /user/profile linked from a
     * Ttrpg page should render in Ttrpg's frame, not the site default.
     *
     * Call it from the module constructor, BEFORE parent::__construct(), and
     * pass $_SERVER['HTTP_REFERER']. defineBasePaths() skips any path that is
     * already set, so an adopted frame survives and everything else still
     * resolves normally.
     *
     * Referer rather than stored state: the frame belongs to one handoff, not
     * to the browser, and storing it would cost a session file per visitor.
     * Referer rather than an explicit ?from= parameter: the handoff is usually
     * an ordinary link that no framework code builds, so there is nothing to
     * append to.
     *
     * Absent, stripped or off-site Referer returns false and changes nothing —
     * the caller keeps its own frame. That is the common case (direct
     * navigation, bookmarks, privacy settings), so it is a quiet fallback and
     * not an error. Note Auth::token() sets `Referrer-Policy: no-referrer`
     * deliberately; pages reached from there always fall back.
     *
     * Referer is client-controlled, so the value is treated as untrusted input
     * and only ever used to SELECT a directory, never to build one:
     *
     *   1. Host must equal Request::$domain. Compared host-to-host with the
     *      port stripped from both, so a non-default port is not read as a
     *      different site, and compared for equality rather than suffix, so
     *      unisolu.com.evil.example does not pass.
     *   2. The first path segment must match ^[a-z0-9-]+$ AFTER lowercasing.
     *      That is the whole traversal defence: ".." , "%2e%2e", a null byte
     *      and a nested path all fail the pattern rather than being stripped
     *      out of it. Nothing is decoded first — an encoded separator has no
     *      legitimate reading here, so it is refused, not unwrapped.
     *   3. The resulting directory must exist AND contain frame/. A module
     *      without its own frame contributes nothing to inherit.
     *
     * @param  string|null $url Typically $_SERVER['HTTP_REFERER'].
     * @return bool True when framePath was set from $url.
     */
    protected function adoptFrameFrom(?string $url): bool
    {
        if($url === null || $url === ""){
            return false;
        }

        $parts = parse_url($url);
        if($parts === false || empty($parts['host']) || empty($parts['path'])){
            return false;
        }

        // Same site only. strtok() drops the ":port" suffix; HTTP_HOST carries
        // one whenever the URL did, and Request::$domain is HTTP_HOST verbatim.
        $refererHost = strtolower(strtok($parts['host'], ":"));
        $ownHost     = strtolower(strtok((string) Request::$domain, ":"));
        if($refererHost === "" || $refererHost !== $ownHost){
            return false;
        }

        // First segment of the path — the module, exactly as Request reads it.
        $segment = strtolower(explode("/", trim($parts['path'], "/"))[0] ?? "");
        if(!preg_match('/^[a-z0-9-]+$/', $segment)){
            return false;
        }

        // Same dash-to-camel rule routing applies, so /access-request/ resolves
        // to AccessRequest here exactly as it does in Request::__construct().
        // Inlined rather than shared because Request::friendlyURLConverter() is
        // private and non-static; if that ever changes, delegate to it instead.
        $module = str_contains($segment, "-")
            ? str_replace(" ", "", ucwords(str_replace("-", " ", $segment)))
            : $segment;
        $module = ucfirst($module);

        $frame = MODULE_PATH . $module . "/frame/";
        if(!is_dir($frame)){
            return false;
        }

        $this->framePath = $frame;
        return true;
    }

    public function __call($func, $args)
    {
        // At this point, we know there's no method to handle the request.
        // Three things can still answer it, in descending precedence:
        //
        //   1. A view file in the requested module's own view/ folder.
        //   2. A public method on Index named after the first URL segment.
        //   3. A view file in Index's view/ folder, named after that segment.
        //
        // 2 beats 3 deliberately. Where Index has both a foo() method and a
        // view/foo.php, the method is the page and the file is only its
        // template — building the file directly renders the page with none of
        // the data the method exists to fetch, and the result looks like a
        // working page rather than a broken one.
        //
        // 1 still beats 2: a real module's own view is a better answer for
        // /module/endpoint than a same-named standalone page in Index.
        // TODO - CLAUDE - let's put this in a loop or some such.
        if (file_exists($view = MODULE_PATH .
                        ucfirst(Request::$module) .
                        "/view/" .
                        Request::$endpoint .
                        ".php"
                    )
        || file_exists($view = MODULE_PATH .
                        ucfirst(Request::$module) .
                        "/view/" .
                        Request::$endpointOrig .
                        ".php"
                    )

        || file_exists($view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) .
                        "/view/" .
                        Request::$endpoint .
                        ".php"
                    )
        || file_exists($view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) .
                        "/view/" .
                        Request::$endpointOrig .
                        ".php"
                    )
       ){
            $this->build($view);

        } else if ($method = $this->indexEndpoint()) {
            // A standalone page with a controller behind it.
            //
            // Gate it the way Module::__call() gates a submodule. Application
            // resolved attributes once, against the *requested* module — which
            // by definition does not exist here — and the URL's second segment,
            // so nothing has gated this method. Without this, #[RequireLogin]
            // on a standalone page would be decoration.
            //
            // Deliberately BEFORE construction, for the same reason the
            // submodule gate is: a check that runs after the constructor has
            // opened connections and loaded models gates the response, not the
            // work.
            Application::checkAttributes(\Zero\Module\Index::class, $method);

            $index = new \Zero\Module\Index();

            // Assets are matched on the endpoint name, and to Index this
            // segment IS the endpoint — Request::$endpoint is the *second*
            // segment, which for a bare /foo is the synthetic "index". Without
            // these, /foo would look for index.css and never foo.css.
            $index->activeEndpoint     = $method;
            $index->activeEndpointOrig = Request::$moduleOrig;

            $index->{$method}();

        } else if (file_exists($view = MODULE_PATH .
                        "Index/view/" .
                        Request::$module .
                        ".php"
                    )
        || file_exists($view = MODULE_PATH .
                        "Index/view/" .
                        Request::$moduleOrig .
                        ".php"
                    )
       ){
            // TODO- this solution isn't great, but since
            // "standalone" pages ultimately belong to the Index module, it means
            // so do their assets. Therefore, this allows those assets to be auto-loaded
            Request::$module = "index";
            $this->build($view);

        } else {
            //new Error(404);
            throw new HTTPError(404, $func, $args);
            // TODO: surface Request::$moduleOrig path detail in renderError
        }
    }

    /**
     * The Index method that should answer this request, if there is one.
     *
     * Standalone pages are addressed by their first URL segment (/foo, not
     * /index/foo), so that segment — not $func, which is the endpoint — is the
     * method name. Both the camelCased and raw forms are tried, matching how
     * the view-file candidates treat Request::$module and Request::$moduleOrig.
     *
     * Only methods Index itself declares as public qualify. method_exists()
     * alone would also match everything inherited from Module and Response,
     * making /renderError and /getAuxiliary routable — and since PHP matches
     * method names case-insensitively, the URL would not even have to be spelt
     * the same way.
     *
     * @return string|null Method name as declared, or null if Index has none
     */
    private function indexEndpoint(): ?string
    {
        // Application::run() require_once's Index whenever it falls back to
        // Response, but a real module reaching __call may not have. The
        // framework autoloader cannot resolve module classes — that is what
        // Application::isModule() exists for — so load it by path or give up.
        if (!class_exists('\Zero\Module\Index', false)) {
            if (!file_exists($file = MODULE_PATH . "Index/Index.php")) {
                return null;
            }
            require_once $file;
        }

        foreach ([Request::$module, Request::$moduleOrig] as $name) {
            if ($name === "" || str_starts_with($name, "__")) {
                continue; // never route to a magic method
            }

            try {
                $candidate = new \ReflectionMethod('\Zero\Module\Index', $name);
            } catch (\ReflectionException $e) {
                continue; // no such method
            }

            if ($candidate->isPublic()
             && !$candidate->isStatic()
             && $candidate->getDeclaringClass()->getName() === 'Zero\Module\Index'
            ) {
                // Reflection matched case-insensitively; hand back the real name.
                return $candidate->getName();
            }
        }

        return null;
    }

    /**
     * Render an HTTPError using the existing Response instance.
     * Called by Application::run() after catching an HTTPError, so that
     * framePath and all other resolved paths from the original module are preserved.
     *
     * @param HTTPError $e
     * @param string|null $err Optional detail string (e.g. the URL that wasn't found)
     */
    public function renderError(HTTPError $e)
    {
        $code    = $e->getCode();
        $message = $e->getMessage();
        $detail  = $e->detail;

        header("HTTP/1.1 $code $message");
        $this->title = "Error: $message";

        // Use a custom error view if one exists in the already-resolved viewPath
        $view = file_exists($v = $this->viewPath . "error" . $code . ".php")
            ? $v
            : "<h1>$code - $message</h1><hr>" . ($detail ? "<h4>$detail</h4><br>" : "");

        $this->respond($view, [
            "status"  => "error",
            "message" => $message,
            "code"    => $code,
        ]);

        Console::error("$code - $message" . ($detail ? " : $detail" : ""));
        exit();
    }

    /**
     * Automatically respond with JSON or HTML based on Accept header
     *
     * For dual-purpose endpoints (HTML view + JSON API):
     * - Set $this->data with the JSON payload
     * - Call respond($viewPath)
     * - Framework exports $this->data for JSON, renders view for HTML
     *
     * @param mixed $viewOrData View path (string) or data array for JSON-only endpoints
     * @param array $extra Additional data merged at root level of JSON response
     */
    protected function respond($viewOrData, array $extra = [])
    {
        if (Request::$acceptsJSON) {
            // If it's a view path, export $this->data instead of the path
            if (is_string($viewOrData) && file_exists($viewOrData)) {
                $this->export($this->data ?? [], $extra);
            } else {
                $this->export($viewOrData, $extra);
            }
        } else {
            $this->build($viewOrData);
        }
    }

    protected function build($view = "")
    {
        if(static::$built || Request::$acceptsJSON){
            return; 
        }
        static::$built = true; 
        $this -> add("head");
        $this -> add("header");
        if(file_exists($view)){
            include $view ; 
        } else {
            echo $view; 
            
            Console::warn("Built view with text instead of file path."); 
            /*
            Console::warn("Building bad view with text instead of file path:\n".
            "\tview: $view\n\n".
            "\tRequest::\$module: ".Request::$module."\n".
            "\tRequest::\$endpoint: ".Request::$endpoint."\n".
            "\n"
            ); 
            */ 
        }
        $this -> add("sideNav");  // not necessary in all contexts. 
        $this -> add("footer");
    }

    private function add(string $piece){
        if($this->included[$piece] || Request::$madeWithAJAX){
            return;
        }
        $this->included[$piece] = true;
        if(file_exists($path = $this->framePath.$piece.".php")
        || file_exists($path = $this->viewPath .$piece.".php")
        || file_exists($path = VIEW_PATH.$piece.".php")
        ){
            Console::debug("Using $path for $piece"); 
            return include_once $path; 
        } 
        Console::warn("Could not find $piece to add to response"); 
    }

    /**
     * Auxiliary file loader — no-op at Response level.
     * Module overrides this with real functionality.
     * Exists here so frame templates can call it safely on any Response instance.
     */
    public function getAuxiliary(string $file){}

    protected function getStylesheets()
    {
        $assetdir = WEB_ROOT . "/assets/" . ($this->assetUrlPath ?? Request::$module) . "/css/";
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("css", $assetdir);
        } else {
            Console::debug($assetdir." not found, so not loading.");
        }
    }

    protected function getScripts()
    {
        $assetdir = WEB_ROOT . "/assets/" . ($this->assetUrlPath ?? Request::$module) . "/js/";
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("js", $assetdir);
        } else {
            Console::debug($assetdir." not found, so not loading.");
        }
    }

    /**
     * Load module-specific web components
     *
     * Automatically includes component HTML files from both global and module component directories.
     * Components are loaded in order: global components first, then module components.
     * Within each directory, components are loaded in alphabetical order (use numeric prefixes
     * like "1.component.html" to control load order).
     *
     * TODO: move this into some odd static method into a class by itself since
     * components are ultimately supposed to be an optional feature of this framework.
     */
    protected function getComponents()
    {
        $globalComponentDir = ZERO_ROOT . 'app/frontend/www/shadow-component/components/';
        $moduleComponentDir = ($this->moduleAssetDir ?? MODULE_PATH . Request::$Module . "/assets/") . "component/";

        // Load global components first
        $this->getComponentsInDirectory($globalComponentDir, 'global');

        // Then load module-specific components
        $componentLabel = $this->moduleName ?? Request::$module;
        if(is_dir($moduleComponentDir)){
            $this->getComponentsInDirectory($moduleComponentDir, $componentLabel);
        } else {
            Console::debug("No component directory for " . $componentLabel . " module");
        }
    }

    /**
     * Load all component HTML files from a specific directory
     *
     * @param string $dir The directory path to scan for components
     * @param string $label Label for console logging (e.g., 'global' or module name)
     */
    private function getComponentsInDirectory($dir, $label)
    {
        if(!is_dir($dir)){
            return;
        }

        $components = scandir($dir);

        foreach($components as $component){
            if($component[0] == "." || !str_ends_with($component, '.html')){
                continue; // Skip dotfiles and non-HTML files
            }

            $componentPath = $dir . $component;
            if(file_exists($componentPath)){
                Console::debug("Loading $label component: $component");
                require_once $componentPath;
            }
        }
    }

    private function loadAssetTypeFromDir($type, $dir){
        if($type == "css"){
            $html_asset = '<link rel="stylesheet" type="text/css" href="%s">';
        } else if($type == "js"){
            $html_asset = '<script src="%s"></script>';
        } else if($type == "component"){
            $html_asset = '';
        } else {
            Console::warn("Unknown asset type: $type. Refusing to load.");
            return; 
        }

        $assets = scandir($dir);
        $pubdir = str_replace(WEB_ROOT, "", $dir);

        // Build list of filenames to match
        // (module name, endpoint name, and their kebab-case versions)
        $moduleName = $this->moduleName ?? Request::$module;
        $moduleNameOrig = Request::$moduleOrig;
        $endpointName = $this->activeEndpoint ?? Request::$endpoint;
        $endpointNameOrig = $this->activeEndpointOrig ?? Request::$endpointOrig;

        $matches = [
            $moduleName . '.' . $type,
            $moduleNameOrig . '.' . $type,
            $endpointName . '.' . $type,
            $endpointNameOrig . '.' . $type,
        ];

        foreach($assets as $asset){
            if($asset[0] == "."){continue;} // Skip dotfiles
            // Load if it matches module name or endpoint name
            if(in_array($asset, $matches)){
                echo sprintf($html_asset, $pubdir . $asset);
            } else {
                Console::debug("$asset doesn't match so not loaded");  
            }
        }
    }

    /**
     * Export data as JSON response
     *
     * @param mixed $data Array for data payload, string for message, null for empty
     * @param array $extra Additional fields merged at root level of response
     */
    protected function export($data = null, array $extra = []) {
        $export = array();

        if (is_array($data)) {
            $export['data'] = $data;
        } else if (!empty($data)) {
            $export['message'] = strip_tags($data);
        }

        // Merge $this->data into data (for dual-purpose endpoints)
        if (!empty($this->data)) {
            if (isset($export['data'])) {
                $export['data'] = array_merge($export['data'], $this->data);
            } else {
                $export['data'] = $this->data;
            }
        }

        // Extra fields merged at root level
        $export = array_merge($export, $extra);

        $export['status'] = $extra['status'] ?? $this->status ?: "unknown";
        $export['code'] = $extra['code'] ?? $this->code ?: 200;

        static::$built = true;
        header("Content-Type: application/json");
        echo json_encode($export, (defined('DEVMODE') && DEVMODE) ? JSON_PRETTY_PRINT : 0);
    }

    /**
     * Send success response (JSON) or redirect (HTML)
     * For CRUD operations that redirect on success
     */
    protected function success($message, $data = []) {
        if (Request::$acceptsJSON) {
            $this->export(array_merge(['success' => true, 'message' => $message], $data));
            exit;
        }
    }

    /**
     * Send error response (JSON) or return false (HTML renders with error)
     * For CRUD operations that show error in form
     */
    protected function error($message, $data = []) {
        if (Request::$acceptsJSON) {
            http_response_code($data['code'] ?? 400);
            $this->export(array_merge(['success' => false, 'error' => $message], $data));
            exit;
        }
    }

}
