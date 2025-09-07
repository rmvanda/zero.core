<?php

namespace Zero\Core; 

class Response
{

    protected $module;
    protected $endpoint, $model, $viewPath;

    protected $type; 

    protected $status; 
    protected $data; 

    protected $headerIncluded,$headIncluded,$sideBarIncluded,$footerIncluded; 

    public $title; 

    protected $sideNavBefore; 
    protected $sideNavAfter; 

    public $body; 

    private $built = false; 

    public function __construct($altconfig = null){
        $this->defineBasePaths(); 
        $this->setResponseType(); 
        $this->registerAutoloader(); 

        /*
        if($this->type == "full"){
            $this->buildHead(); 
            $this->buildHeader(); 
        }
        */
    }

    public function __destruct(){
        if(!$this->built){
            $this->build($this->body); 
        }
        /*
        if($this->type == "full"){
            $this->buildSideNav(); 
            $this->buildFooter();  
        }
        */
    }
    
    public function registerAutoloader(){
        spl_autoload_register(function($class){
            $step = explode("\\",get_called_class()); 
            $a = array_pop($step) ; 
            if(file_exists($a."/"."$class.php")){
                require_once($a); 
                return true;
            }
            
        },true,true);
    }


    /** 
     * This function was made to address the need of the conditional statements
     * in the construct and destruct methods. 
     * This way is likely more future oriented with the ideas of the project
     * but for the time being, `type` may as well really be a boolean
     * it was made this way to make the code more understandable, however. 
     * so hopefully you're reading this with appreciation rather than disgust. 
     */

    protected function setResponseType(){
        if(str_contains(Request::$accepts, "json")){
            $this->type = "json"; 
        } else {
            $this->type = "full"; 
        }
        
    }

    protected function defineBasePaths(){
        if(!$this->viewPath){  
            $this -> viewPath = ZERO_ROOT."app/frontend/global_views/"; 
        } 
    }

    public function __call($func, $args)
    {
        // At this point, we know there's no method to handle the request. 
        // So, we're going to see if there's a view file to use::

        // -- Maybe in the module's view folder? 
        if (file_exists($view = MODULE_PATH .
                        ucfirst(Request::$module) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
            || file_exists($view = MODULE_PATH . 
                        Request::$module. 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 

        // Or maybe the module has a sub
        // (( I don't think we should cater to this, actually ))
           || file_exists($view = $b = MODULE_PATH . 
                        ucfirst(Request::$module) . 
                        "/views/" . 
                        Request::$endpoint ."/".
                       (Request::$uriArray[2]??"").".php"
                    )
        // And finally, defer to index. This is useful for urls like 
        // site.com/about 
        // since in most cases, it would be silly to set up an "About" module
        // However, if we need further logic from Index in this way, the else 
        // block below covers it. 
           || file_exists($view = $c = MODULE_PATH . 
                            "Index/views/" . 
                            Request::$module . 
                            ".php"
                    )
       ){
            $this->build($view); 
        } else {
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

    protected function render($view)
    {
        if (Request::$accepts == "json") {
            include $view;
        } else {
            $this -> build($view);
        }
    }

    protected function build($view)
    {
        if($this->built){
            return; 
        }
        $this->built = true; 
        $this -> buildHead();
        $this -> buildHeader();
        if(file_exists($view??"")){
            include $view ; 
        } else {
            echo $view; 
        }
        $this -> buildSideNav(); 
        $this -> buildFooter();
    }

// Doing it like this allows individual classed to override the methods
// while still using render. 
// The extra difference on this function was a test in 2024 to continue this^ idea.
    protected function buildHead()
    {
        $this->headIncluded=true;
        if(file_exists($head = $this->viewPath."head.php")){
            include_once $head; 
        } elseif(file_exists($head = VIEW_PATH."head.php")){
            include_once $head; 
        }
    }

    protected function buildHeader()
    {
        $this->headerIncluded=true; 
        include_once $this -> viewPath . "header.php";
    }

    protected function buildSideNav(){

        $this->sideBarIncluded=true; 
        include_once $this -> viewPath . "sideNav.php"; 

    }

    protected function buildFooter()
    {
        $this->footerIncluded=true; 
        include_once $this -> viewPath . "footer.php";
    }

    private function getStylesheets()
    {

        $assetdir = WEB_ROOT."/assets/".Request::$module."/css/"; 
        // TODO: maybe only load certain things by endpoint? meh, write better CSS.
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("css",$assetdir);
        } else {
            echo "<!-- ".WEB_ROOT." css not found, so not loaded. -->";     
        }
    }

    private function getScripts()
    {
        $assetdir = WEB_ROOT."/assets/".Request::$module."/js/"; 
        // TODO: maybe only load certain things by endpoint? meh, write better CSS.
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("js",$assetdir);
        } else {
            echo "<!-- ".WEB_ROOT." js not found, so not loaded. -->";     
        }
    }

    private function loadAssetTypeFromDir($type,$dir){
        $assets = scandir($dir); 
        if($type == "css"){
            $html_asset = '<link rel="stylesheet" type="text/css" href="%s">' ;    
        } else 
        if($type == "js"){
            $html_asset = '<script src="%s"></script>'; 
        }
        $pubdir = str_replace(WEB_ROOT, "", $dir);
        foreach($assets as $asset){
            if($asset[0] == "."){continue;}// murders . and ..  .*swp files
            echo sprintf($html_asset,$pubdir.$asset);  // TODO return? we have buffering.. so.. 
        }
    }

    // JSON API functions... 
    // This will at least standardize our json output without having to refactor *every endpoint individually*
    // Ideally we would have functions rather than the if/elseif check below, but I didn't have much time to
    // do choice today, and I wanted to make sure we could at least standardize it for the app developers.

    // TODO - need a better way to handle these. 

    protected function error($e)
    {
        $this->message = $e;
        $this->status  = "error"; 
        $this->export();
    }

    protected function success($msg="The operation completed successfully"){
        $this->message = $msg;
        $this->export(); 
    }

    protected function export($e=null){
        if (!is_array($e)&&!is_null($e) && empty($this->message)) { 
            $this->message = $e; 
        } else if(empty($this->data)){
            $this->data = $e;    
        }

        $json = array(
                    "status"  => isset($this->status)?"Error":"Success", 
                    "message" => $this->message
                    ); 


        if(!empty($this->data)){
            $json['data'] = $this->data; 
        }

        header("Content-Type: application/json");
        print(json_encode($json, empty(DEVMODE)?0:JSON_PRETTY_PRINT));
    }

}
