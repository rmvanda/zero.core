<?php

namespace Zero\Core; 

class Response
{

    protected $aspect;
    protected $endpoint, $model, $viewPath, $isAjax;

    protected $status = true; 
    protected $data; 

    protected $headerIncluded,$headIncluded,$footerIncluded; 

    public $title; 

    protected $response; 

    public function __construct($altconfig = null)
    {
        $this->defineBaseViewPath(); 

        if(!isset(Request::$accepts)){
            $this->buildHead(); 
            $this->buildHeader(); 
        }
        // new Model($altconfig); 
    }

    public function __destruct(){
        if(!isset(Request::$accepts)){
            @$this->buildFooter(); 
        }
    } 

    protected function defineBaseViewPath()
    {
        if(!defined($this->viewPath)){  
            $this -> viewPath = ZERO_ROOT."app/frontend/global_views/"; 
        } 
        $this -> viewPath = VIEW_PATH;
    }

    public function __call($func, $args)
    {
        if (file_exists($view = $a = MODULE_PATH .
                        ucfirst(Request::$aspect) . 
                        "/views/" . 
                        Request::$endpoint . 
                        ".php"
                       ) 
         || file_exists($view = $b = MODULE_PATH . 
                        ucfirst(Request::$aspect) . 
                        "/views/" . 
                        Request::$endpoint ."/".
                        Request::$uriArray[2].".php"
                    )
         || file_exists($view = VIEW_PATH  . // I'd like to dprecate this block  <-- 
                        Request::$aspect   . "/" .
                        Request::$endpoint . 
                        ".php")
           ) {
         //   $this -> render($view);
         include $view; 
        } else {

            echo "<h1>$b</h1>"; die(); 
            //xdebug_print_function_stack(); 
            new Error(404, "Failed to find a respose to give for $func");
        }
    }


    protected function render($view)
    {
        if (!isset($this->viewPath)){
            $this->viewPath = VIEW_PATH; 
        }

        if (isAjax()) {
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

   // protected function getPage($page) // who the fuck? 
   // {
   //     include $page;
   // }

    protected function buildFooter()
    {
        $this->footerIncluded=true; 
        include_once $this -> viewPath . "footer.php";
    }

    private function getStylesheets()
    {
        foreach(array(Request::$aspect, Request::$endpoint) as $resource){
            if (file_exists(WEB_PATH . "assets/pg-specific/".Request::$aspect."/css/".$resource.".css")) {
                echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $resource . '.css" />';
            } else {
                echo "<!-- " . $resource . ".css not found, so not loaded. -->";
            }
        }
    }

    private function getScripts()
    {
        foreach(array(Request::$aspect, Request::$endpoint) as $resource){
            if (file_exists(WEB_PATH . "assets/pg-specific/".Request::$aspect."/js/$resource.js")) {
                echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $resource . '.js" ></script>';
            } else {
                echo "<!-- " . $resource . ".js not found, so not loaded. -->";
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
        $this->status  = false; 
        $this->export();
    }

    protected function success($msg="The operation completed successfully"){
        $this->message = $msg;
        $this->export(); 
    }

    protected function export($e=null)
    {
        if (!is_array($e)&&!is_null($e)) { 
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
                    "status"  => $this->status?"error":"success",
                    "message" => $this->message
                    ); 

        if(!empty($this->data)){
            $json['data'] = $this->data; 
        }

        if(Request::$accepts == 'json'){
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


