<?php

namespace Zero\Core; 

class Response
{

    protected $aspect;
    protected $endpoint, $model, $viewPath, $isAjax;

    protected $responseType; 

    protected $status; 
    protected $data; 

    protected $headerIncluded,$headIncluded,$footerIncluded; 

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

            $a = array_pop(explode("\\",get_called_class())) ; 
            if(file_exists($a."/"."$class.php")){
                require_once($a); 
                return true;
            }
            
        },false,true);
        


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

        if(Request::$isAjax){ 
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
        if(!defined($this->viewPath)){  
            $this -> viewPath = ZERO_ROOT."app/frontend/global_views/"; 
        } 
//        $this -> viewPath  = VIEW_PATH; // what the fuck? 
        define("ASPECT_PATH", MODULE_PATH.Request::$Aspect."/");  // why is this not the same as MOdule_Path ? 
    }

    public function __call($func, $args)
    {
        // At this point, we know there's no method to handle the request. 
        // So, we're going to see if there's a view file to use::

        // -- Maybe in the module's view folder? 
        if (file_exists($view = $a = MODULE_PATH .
                        ucfirst(Request::$aspect) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                    ) 
            || file_exists($view = $d = MODULE_PATH . // XXX better way to handle Aspect vs aspect ?? 
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
                        Request::$uriArray[2].".php"
                    )
        // And finally, defer to index. This is useful for urls like 
        // site.com/about 
        // since in most cases, it would be silly to set up an "About" module
           || file_exists($view = $c = MODULE_PATH . 
                            "Index/views/" . 
                            Request::$aspect . 
                            ".php"
                    )
       ){
         //   $this -> render($view); // whoa, wait, really? When the hell did I do dhat?? 
         include $view; 
        } else {
            
            //echo "$a<br>$b<br>$c"; 

            new Error(404, "Failed to find a respose to give for $func");
        
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
        $this -> buildFooter();
    }

// Doing it like this allows individual classed to override the methods
// while still using render. 
    protected function buildHead()
    {
        $this->headIncluded=true;
        include_once $this -> viewPath . "head.php";
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
        if(file_exists(WEB_ROOT.($css="/zero/assets/".Request::$aspect."/css/".Request::$aspect.".css"))){
            echo '<link rel="stylesheet" type="text/css" href="'.$css.'">' ;    
        } else {
            echo "<!-- $css not found, so not loaded. -->";     
        }
    }

    private function getScripts()
    {
        foreach(array(Request::$aspect, Request::$endpoint) as $resource){
            if(file_exists(WEB_ROOT.($js="/zero/assets/".Request::$aspect."/js/".$resource.".js"))){
                echo '<script src="'.$js.'"></script>'; 
            } else {
                echo "<!-- $resource not found, so not loaded. -->"; 
            }
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

	private $xml_data; 

	private function toXML($data){
		if(empty($xml_data)){
			$xml_data = new SimpleXMLElement('<?xml version="1.0"?><data></data>');
		}

		// function defination to convert array to xml
		foreach( $data as $key => $value ) {
			if( is_numeric($key) ){
				$key = 'item'.$key; //dealing with <0/>..<n/> issues
			}
			if( is_array($value) ) {
				$subnode = $xml_data->addChild($key);
				array_to_xml($value, $subnode);
			} else {
				$xml_data->addChild("$key",htmlspecialchars("$value"));
			}
		}
	}

}
