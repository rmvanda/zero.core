<?php

    class Response
    {

        public $aspect;
        public $endpoint, $model, $viewPath, $isAjax;

        public function __construct($standalone = false)
        {
            if ($standalone) {
                $this -> aspect = $standalone;
            }
                new Model; // if the model is already instantiated, the model class handles this. 
                $this -> viewPath = VIEW_PATH;
        }

        public function defineBaseViewPath()
        {
            if (file_exists($vp = ROOT_PATH . "opt/" . get_class($this) . "/views/")) {
                $this -> viewPath = ROOT_PATH . "opt/";
            } elseif (file_exists($vp = ROOT_PATH . "app/modules/" . get_class($this) . "/views/")) {
                $this -> viewPath = $vp;
            } else {
                $this -> viewPath = VIEW_PATH;
            }
        }

        public function __call($func, $args)
        {
            $this->endpoint = strtolower($this->endpoint); // Maybe this should go elsewhere?
            $this->aspect = strtolower($this->aspect); 
            //@f:off
	        if (file_exists($view= $viem = ROOT_PATH . "app/modules/" . ucfirst($this -> aspect) . "/views/" . $this -> endpoint . ".php") 
	        || file_exists($view = ROOT_PATH . "opt/plugins/Zero/" . $this -> aspect . "/" . $this -> endpoint . ".php") 
	        || file_exists($view = VIEW_PATH . $this -> aspect . "/" . $this -> endpoint . ".php")
            || file_exists($view = ROOT_PATH."opt/modules/".ucfirst($this->aspect)."/views/".$this->endpoint.".php")) {
	            $this -> render($view);
                //Someone mentioned "Hey, that isn't right! you set $view to 5 different things, how is that supposed to work? 
                // Well, as soon as file_exists returns true, it jumps to this line, and works exactly as expected. 
    	    } else { //@f:on
                die("$viem"); 
                new Error(404, "$func is not a valid thing");
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

        public function __get($prop)
        {
            if ($prop == 'model') {
                if (!$this -> _default_model) {
                    $model_name = get_class($this) . '_Model';
                    $this -> _default_model = new $model_name();
                }
                return $this -> _default_model;
            }
            $model_name = $prop . '_Model';
            $this -> {$prop} = new $model_name();
            //prop__CLASS__;
            $model_name = $prop . '_Model';
            $this -> {$prop} = new $model_name();
            return $this -> {$prop};
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

        /**
         * Both of these functions need better logic, anyway - - - -
         *
         * For instance, in the case of Console - there needs to be a way to load
         * "Console.js"
         * - or some kind of hook, perhaps something like wp's enque_script
         * we'll get there.
         */
        private function getStylesheets()
        {
            if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this -> aspect . ".css")) {
                echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this -> aspect . '.css" />';
            } else {
                echo "<!-- " . $this -> aspect . ".css not found, so not loaded. -->";
            }
            if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this -> endpoint . ".css")) {
                echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this -> endpoint . '.css" />';
            } else {
                echo "<!-- " . $this -> endpoint . ".css not found, so not loaded. -->";
            }

        }

        private function getScripts()
        {
            if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->aspect}.js")) {
                echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this -> endpoint . '.js" ></script>';
            } else {
                echo "<!-- " . $this -> endpoint . ".js not found, so not loaded. -->";
            }
            if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->endpoint}.js")) {
                echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this -> endpoint . '.js" ></script>';
            } else {
                echo "<!-- " . $this -> endpoint . ".js not found, so not loaded. -->";
            }
        }

    }
