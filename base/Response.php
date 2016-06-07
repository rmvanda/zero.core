<?php

class Response
{

    protected $aspect;
    protected $endpoint, $model, $viewPath, $isAjax;

    protected $status = true; 
    protected $message; 
    protected $data; 


    public function __construct($altconfig = null)
    {
        new Model($altconfig); 
        $this->defineBaseViewPath(); 
    }

    protected function defineBaseViewPath()
    {
        $this -> viewPath = VIEW_PATH;
    }

    public function __call($func, $args)
    {
        if (file_exists($view= $viem = ROOT_PATH . 
                    "app/modules/" . 
                    ucfirst(Request::$aspect) . 
                    "/views/" . 
                    Request::$endpoint . ".php") 
                || file_exists($view = VIEW_PATH . 
                    Request::$aspect . "/" .
                    Request::$endpoint . 
                    ".php")
           ) {
            $this -> render($view);
        } else { 
            new Err(404, "Failed to find a respose to give for $func");
        }
    }

    protected function render($view)
    {
        if (isAjax()) {
            $this -> getPage($view);
        } else {
            $this -> build($view);
        }
    }

    protected function build($view)
    {
        $this -> buildHead();
        $this -> buildHeader();
        $this -> getPage($view);
        $this -> buildFooter();
    }

// Doing it like this allows individual classed to override the methods
// while still using render. 
    protected function buildHead()
    {
        include $this -> viewPath . "_global/head.php";
    }

    protected function buildHeader()
    {
        include $this -> viewPath . "_global/header.php";
    }

    protected function getPage($page)
    {
        include $page;
    }

    protected function buildFooter()
    {
        include $this -> viewPath . "_global/footer.php";
    }

    private function getStylesheets()
    {
        foreach(array(Request::$aspect, Request::$endpoint) as $resource){
            if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $resource . ".css")) {
                echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $resource . '.css" />';
            } else {
                echo "<!-- " . $resource . ".css not found, so not loaded. -->";
            }

        }
    }

    private function getScripts()
    {
        foreach(array(Request::$aspect, Request::$endpoint) as $resource){
            if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->aspect}.js")) {
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
                    "status"  => $this->status?"success":"error",
                    "message" => $this->message
                    ); 

        if(!empty($this->data)){
            $json['data'] = $this->data; 
        }

        // later, maybe possibly we could refactor all the update/create/delete
        // endpoints to give out messages.
        //if ($e['full'])
        //{
        //    $json = $e['full'];
        //}
        //        {
        //            $json['status'] = "error"; 
        //            $json['message'] = $e['error'];    
        //        }
        //        else if ($e['success'])
        //        {
        //             $json['message'] = $e['success'];
        //        }
        //        else 
        //        {
        //            $json['data'] = $e; 
        //        }

        // This seems to be the issue here with backwards compatibility.
       // unset($e['success']);
        //unset($e['data']);
        //unset($e['message']);

        //         $json = array_merge($json,$e?:Array());        
        // This was causing the size to double.... 
        // 
        header("Content-Type: application/json");
        die(json_encode($json, empty(DEVMODE)?0:JSON_PRETTY_PRINT));

    }
   





}
