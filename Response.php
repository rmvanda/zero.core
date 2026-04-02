<?php

namespace Zero\Core; 

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
// TODO - CLAUDE:
// The purpose of putting the frame in the session was to allow the frame to persist
// in the event of hitting things like error pages or "you need to log on" pages 
// naturally, this implementation does not work and should be revisited. 
                $this->$pathString = $dirname.$path."/";  
            } else {
                // this is not valid for model, but whatever. FIXME later
                $this->$pathString = ZERO_ROOT."app/frontend/".$path."/";
            }
            //echo "Set $pathString to: {$this->$pathString}<br>"; 
        }
    }

    public function __call($func, $args)
    {
        // At this point, we know there's no method to handle the request. 
        // So, we're going to see if there's a view file to use::
        // -- Maybe in the module's view folder? 
        // TODO - CLAUDE - let's put this in a loop or some such. 
        if (file_exists($a = $view = MODULE_PATH .
                        ucfirst(Request::$module) . 
                        "/view/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
        || file_exists($b = $view = MODULE_PATH .
                        ucfirst(Request::$module) . 
                        "/view/" . 
                        Request::$endpointOrig . 
                        ".php"
                    ) 

        || file_exists($c = $view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) . 
                        "/view/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
        || file_exists($d = $view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) . 
                        "/view/" . 
                        Request::$endpointOrig . 
                        ".php"
                    ) 

        || file_exists($e = $view = MODULE_PATH . 
                        "Index/view/" . 
                        Request::$module . 
                        ".php"
                    )

        || file_exists($f = $view = MODULE_PATH . 
                        "Index/view/" . 
                        Request::$moduleOrig . 
                        ".php"
                    )
       ){
            // TODO- this solution isn't great, but since 
            // "standalone" pages ultimately belong to the Index module, it means 
            // so do their assets. Therefore, this allows those assets to be auto-loaded
            if($e || $f){Request::$module = "index";}
            $this->build($view); 
            
        } else {
            // TODO: 
            // This whole fallback block is never used except in the case 
            // of not having anything else to do. If you're here, that means 
            // we're about to issue a 404, but we're checking to see if there is
            // a fallback method in Index we can call, first. 
            // This is good if you want some simple, short thing, or perhaps an 
            // alias or some such, but is probably not the cleanest approach. 
            // 
            //require "/var/www/html/unisolu.com/zero/modules/Index/Index.php";
            // TODO ^ investigate why this doesn't autoload... 
            //$fallback = new \Zero\Module\Index(); 
            //if(method_exists($fallback, Request::$module)){
                // Note this means your args are discarded. But you probably don't 
                // want to have something that deep in your Index module anyway.
                //$this->build( // to build or not to build? TODO 
                //$fallback -> {Request::$module}(Request::$endpoint); 
                //); 
            //} else {
                //new Error(404); 
                throw new HTTPError(404, $func, $args);
                // TODO: surface Request::$moduleOrig path detail in renderError
            //}
        }
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
