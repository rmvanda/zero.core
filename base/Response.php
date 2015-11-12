<?php

class Response
{

    public $aspect;
    public $endpoint, $model, $viewPath, $isAjax;

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

}
