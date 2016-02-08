<?php

class Response
{

    public $aspect;
    public $endpoint, $model, $viewPath, $isAjax;

    public $data; 

    public function __construct($altconfig = null)
    {
        new Model($altconfig); 
        $this->defineBaseViewPath(); 
    }

    public function defineBaseViewPath()
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
            new Error(404, "Failed to find a respose to give");
        }
    }

    public function render($view)
    {
        if (isAjax()) {
            $this -> getPage($view);
        } else {
            $this -> build($view);
        }
    }

    public function build($view)
    {
        $this -> buildHead();
        $this -> buildHeader();
        $this -> getPage($view);
        $this -> buildFooter();
    }

    public function buildHead()
    {
        include $this -> viewPath . "_global/head.php";
    }

    public function buildHeader()
    {
        include $this -> viewPath . "_global/header.php";
    }

    public function getPage($page)
    {
        include $page;
    }

    public function buildFooter()
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
    
    public function error($e)
    {
        return $this->export(Array('error' => $e));
    }
    public function export($e)
    {
        if (!is_array($e)) { $e = Array($e); }
        $e = array_change_key_case($e); 
        
        // For endpoints used both on the APP and 
        if ($this->no_json == 1) { return $e; } 
        
        $json = Array(
            'data' => $this->data?:'',
            'success' => true,
            'message' => null
        );
        
        // later, maybe possibly we could refactor all the update/create/delete endpoints to 
        // give out messages.
        if ($e['full'])
        {
            $json = $e['full'];
        }
        else if ($e['error'])
        {
            $json['success'] = false;
            $json['message'] = $e['error'];    
        }
        else if ($e['success'])
        {
            $json['message'] = $e['success'];
        }
        else 
        {
            $json['data'] = $e; 
        }
        
        // This seems to be the issue here with backwards compatibility.
        unset($e['success']);
        unset($e['data']);
        unset($e['message']);

        $json = array_merge($json,$e?:Array());        
        header("Content-Type: application/json");
        echo json_encode($json, JSON_PRETTY_PRINT);
        die;
    }






}
