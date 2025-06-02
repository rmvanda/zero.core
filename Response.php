<?php
namespace Zero\Core; 

function head($str){ echo "<h1>".$str."</h1>";  } 
class Response
{

    protected $aspect;
    protected $endpoint, $model, $viewPath, $isAjax;

    protected $responseType; 

    protected $status; 
    protected $data; 

    protected $headerIncluded,$headIncluded,$sideBarIncluded,$footerIncluded; 

    public $title; 

    protected $response; 

    protected $sideNavBefore; 
    protected $sideNavAfter; 

    public function __construct($altconfig = null)
    {
        $this->defineBasePaths(); 
        $this->setResponseType(); 
        $this->registerAutoloader(); 

        if($this->responseType == "full"){
            $this->buildHead(); 
            $this->buildHeader(); 
        }
    }

    public function __destruct(){
        if($this->responseType == "full"){
            $this->buildSideNav(); 
            $this->buildFooter();  
        }
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
     * but for the time being, `responseType` may as well really be a boolean
     * it was made this way to make the code more understandable, however. 
     * so hopefully you're reading this with appreciation rather than disgust. 
     */

    protected function setResponseType(){

        if(Request::$isAjax){ // yeah this is dated...  TODO 
            $this->responseType = "html"; // hackish XXX
        }
        else if(isset(Request::$accepts)){
            $this->responseType = Request::$accepts;    
        }
        else{
            $this->responseType = "full"; 
        }


    }

    protected function defineBasePaths()
    {
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
                        ucfirst(Request::$aspect) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
            || file_exists($view = MODULE_PATH . 
                        Request::$aspect. 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 

        // Or maybe the module has a sub
        // (( I don't think we should cater to this, actually ))
           || file_exists($view = $b = MODULE_PATH . 
                        ucfirst(Request::$aspect) . 
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
                            Request::$aspect . 
                            ".php"
                    )
       ){
            include $view; 
        } else {
            $fallback = new \Zero\Module\Index(); 
            if(method_exists($fallback, Request::$aspect)){
                // TODO: should be Request::$args here? 
                // this is a half baked fallback, so not very worried. 
                $fallback -> {Request::$aspect}(Request::$endpoint); 
            } else {
                new Error(404, "Failed to find a respose to give for $func");
            }
        }
    }


    protected function render($view)
    {
        if (!isset($this->viewPath)){
            $this->viewPath = VIEW_PATH; 
        }

        if (Request::$isAjax) {
            include $view;
            //$this -> getPage($view);
        } else {
            $this -> build($view);
        }
    }

    protected function build($view)
    {
        $this -> buildHead();
        $this -> buildHeader();
        include $view ; //$this -> getPage($view);
        $this -> buildSideNav(); // TODO: why didn't i do this previously? 
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

        $assetdir = WEB_ROOT."/assets/".Request::$aspect."/css/"; 
        // TODO: maybe only load certain things by endpoint? meh, write better CSS.
        if(is_dir($assetdir)){
            $this->loadAssetTypeFromDir("css",$assetdir);
        } else {
            echo "<!-- ".WEB_ROOT." css not found, so not loaded. -->";     
        }
    }

    private function getScripts()
    {
        $assetdir = WEB_ROOT."/assets/".Request::$aspect."/js/"; 
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

    protected function export($e=null)
    {
        if (!is_array($e)&&!is_null($e) && empty($this->message)) { 
            $this->message = $e; 
        } else if(empty($this->data)){
            $this->data = $e;    
        }

        // For endpoints used both on the APP and 
        if ($this->no_json) { 
            die("Not implemented"); 
            $this->render($e); 
        } 
    
        $json = array(
                    "status"  => isset($this->status)?"Error":"Success", 
                    "message" => $this->message
                    ); 


        if(!empty($this->data)){
            $json['data'] = $this->data; 
        }

        if(Request::$accepts == 'json'){
            header("Content-Type: application/json");
            print(json_encode($json, empty(DEVMODE)?0:JSON_PRETTY_PRINT));
        } else {
            header("Content-Type: application/json");
            print(json_encode($json, empty(DEVMODE)?0:JSON_PRETTY_PRINT));
            
        }
    }

}
