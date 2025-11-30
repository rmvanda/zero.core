<?php

namespace Zero\Core; 

class Response
{

    public $title;  // TODO - this isn't quite hooked in correctly. 

    protected $status; 
    private $message; // TODO DEPRECATED
    private $body;   // TODO DEPRECATED
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
        $class_info = new \ReflectionClass(get_class($this));
        $dirname    = dirname($class_info->getFileName())."/"; 

        foreach($this->paths as $path){
            $pathString = $path."Path"; 
            if(!$this->$pathString && is_dir($dirname.$path)){
                $this->$pathString = $dirname.$path."/";  
            } else {
                // this is not valid for model, but whatever. FIXME later
                $this->$pathString = ZERO_ROOT."/app/frontend/".$path."/";
            }
        }
    }

    public function __call($func, $args)
    {
        // At this point, we know there's no method to handle the request. 
        // So, we're going to see if there's a view file to use::
        // -- Maybe in the module's view folder? 
        if (file_exists($a = $view = MODULE_PATH .
                        ucfirst(Request::$module) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
        // 
        || file_exists($b = $view = MODULE_PATH .
                        ucfirst(Request::$module) . 
                        "/views/" . 
                        Request::$endpointOrig . 
                        ".php"
                    ) 

        || file_exists($c = $view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
        || file_exists($d = $view = MODULE_PATH .
                        ucfirst(Request::$moduleOrig) . 
                        "/views/" . 
                        Request::$endpointOrig . 
                        ".php"
                    ) 

        || file_exists($e = $view = MODULE_PATH . 
                        "Index/views/" . 
                        Request::$module . 
                        ".php"
                    )

        || file_exists($f = $view = MODULE_PATH . 
                        "Index/views/" . 
                        Request::$moduleOrig . 
                        ".php"
                    )
       ){
            $this->build($view); 
        } else {
            /*
            echo $a ."<br>"; 
            echo $b ."<br>"; 
            echo $c ."<br>"; 
            echo $d ."<br>"; 
            echo "==== index checks: ====<br>";
            echo $e ."<br>"; 
            echo $f ."<br>"; 
            */
            require_once MODULE_PATH."Index/Index.php";  // XXX I do not like this. 
            $fallback = new \Zero\Module\Index(); 

            if(method_exists($fallback, Request::$module)){
                // Note this means your args are discarded. But you probably don't 
                // want to have something that deep in your Index module anyway.
                $this->build($fallback -> {Request::$module}(Request::$endpoint)); 
            } else {
                new Error(404, "Failed to find a respose to give for $func");
            }
        }
    }

    /**
     * Automatically respond with JSON or HTML based on Accept header
     *
     * This eliminates the need for if(Request::$acceptsJSON) checks in every method
     *
     * @param mixed $data Data to export as JSON (array/object) or view path (string)
     * @param string|null $view Optional view path for HTML response (if $data is not a view path)
     */
    protected function respond($viewOrData, array $extra = []) 
    {
        if (Request::$acceptsJSON) {
            $this->export($viewOrData, $extra);
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
            Console::warn("Building bad view with text instead of file path."); 
        }
        //$this -> add("sideNav");  // TODO - still needed? 
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

    protected function getStylesheets()
    {
        $assetdir = WEB_ROOT."/assets/".Request::$module."/css/";
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("css", $assetdir);
        } else {
            echo "<!-- ".$assetdir." not found, so not loading. -->";
        }
    }

    protected function getScripts()
    {
        $assetdir = WEB_ROOT."/assets/".Request::$module."/js/";
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("js", $assetdir);
        } else {
            echo "<!-- ".$assetdir." not found, so not loading. -->";
        }
    }

    /**
     * Load module-specific web components
     *
     * Automatically includes component HTML files from the module's components directory.
     * Components are loaded before they're used in the page.
     */
    protected function getComponents()
    {
        $componentDir = MODULE_PATH . ucfirst(Request::$module) . "/components/";

        if(!is_dir($componentDir)){
            echo "<!-- No components directory for " . Request::$module . " module -->";
            return;
        }

        $components = scandir($componentDir);
        foreach($components as $component){
            if($component[0] == "." || !str_ends_with($component, '.html')){
                continue; // Skip dotfiles and non-HTML files
            }

            $componentPath = $componentDir . $component;
            if(file_exists($componentPath)){
                echo "<!-- Loading component: $component -->\n";
                require_once $componentPath;
            }
        }
    }

    private function loadAssetTypeFromDir($type, $dir){
        $assets = scandir($dir);
        if($type == "css"){
            $html_asset = '<link rel="stylesheet" type="text/css" href="%s">';
        } else if($type == "js"){
            $html_asset = '<script src="%s"></script>';
        } else {
            Console::warn("Unknown asset type: $type. Refusing to load.");
            return; 
        }

        $pubdir = str_replace(WEB_ROOT, "", $dir);

        // Build list of filenames to match 
        // (module name, endpoint name, and their kebab-case versions)
        $matches = [
            Request::$module . '.' . $type,
            Request::$moduleOrig . '.' . $type,
            Request::$endpoint . '.' . $type,
            Request::$endpointOrig . '.' . $type,
        ];

        foreach($assets as $asset){
            if($asset[0] == "."){continue;} // Skip dotfiles
            // Load if it matches module name or endpoint name
            if(in_array($asset, $matches)){
                echo sprintf($html_asset, $pubdir . $asset);
            } else {
                Console::debug("$asset doesn't match so not loaded");  
                echo "<!-- $asset doesn't match so not loaded -->"; 
            }
        }
    }

    // TODO - this could afford to be fixed up.
    protected function export($data = null, array $extra = []){

        $export = array();
        if(is_array($data)){
            $export['data']    = $data;
        } else if(!empty($data)){
            $export['message'] = strip_tags($data);
        }

        // second overwrites the first:
        $export = array_merge($export, $extra);

        $export['status'] = $extra['status'] ?? $this->status ?: "unknown";
        $export['code']   = $extra['code']   ?? $this->code   ?: 200;


        if(!empty($this->data)){
            $export['data_extra'] = $this->data;
        }

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
