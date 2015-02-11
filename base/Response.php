<?php
/**
 *
 */
//namespace Zero\Core;
class Response
{

    private $aspect;
    public $endpoint;

    public $model;

    public $viewPath;

    public $isAjax;

    //public $config = __CLASS__;

    public function __construct()
    {
        require ROOT_PATH . "skeleton/_configs/ResponseConfig.php";
        $this -> viewPath = VIEW_PATH;
        $this -> aspect = strtolower(get_class($this));
    }

    public function __call($func, $args)
    {

        //
        // if (method_exists($this -> model, $func)) {
        // return $this -> model -> {$func}(count($args) > 1 ? $args : $args[0]);
        // // The above portion will likely be @deprecated soon
        // } else
        if (file_exists($view = $this -> viewPath . $this -> aspect . "/" . $this -> endpoint . ".php")) {
            $this -> render($view);
        } else {
            new Error(404, "$func is not a valid thing");
        }

    }

    public function isAjax()
    {
        return $this -> isAjax ? : $this -> isAjax = (@$_SERVER['HTTP_X_REQUESTED_WITH'] && strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ? true : false;
    }

    public function render($view)
    {
        if ($this -> isAjax()) {
            $this -> getPage($view);
        } else {
            $this -> build($view);
        }
    }

    public function __get($prop)// Nah, cool.
    {
        // Optimized for your viewing pleasure
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
    }// Dude, go ahead and write this up in the git !  we'll tweak it a bit more, later -

    // This is the kind of thing where Zero would benefit from Origami.
    //
    // Yeah, you're right !
    //
    public function build($view)
    {
        $this -> buildHead();
        $this -> buildHeader();
        $this -> getPage($view);
        $this -> buildFooter();
    }

    public function buildHead()
    {
        include VIEW_PATH . "_global/head.php";
    }

    public function buildHeader()
    {
        include VIEW_PATH . "_global/header.php";
    }

    public function getPage($page)
    {
        include $page;
    }

    public function buildFooter()
    {
        include VIEW_PATH . "_global/footer.php";
    }

    public function load($aspect)
    {

    }

    public function loadModel($aspect)
    {
        // There's still a major issue with having one model per response for
        // anything other than really small projects (and even then..)
        // We can discuss this another time, however.
        //
        // In this version, Application serves as the controller -
        // and everything else
        if (file_exists(($name = ucfirst($this -> aspect) . "Model") . ".php")) {
            $this -> model = new $name;
        } else {//@f:off
            if (!defined(JIT)) {//TODO :: JIT = "Just In Time" - aka- load the model, manually. not implemented, currently
                // This looks promising. I'd be interested to see what you're thinking here.
                // it was - a patch fix for being able to reuse database functions -  
                //  sort of a kludge, really - 
                //$this -> model = new _GlobalModel();
            }
        }
    }
    


    // public function __construct($request)
    // {
        // parent::__construct();
        // $this -> render($request);
    // }

    /**
     * Recursively makes a menu with submenus.
     * based on
     * @param $nav array( $aTag => $href, $subMenu => array(//etc));
     *
     */
    // public function navMenu($nav)
    // {
        // $return .= "\n<ul>";
        // foreach ($nav as $a => $href) {
            // $return .= "\n\t<li>";
            // if (is_array($href)) {// vv mkNav has been renamed, yo
                // $return .= $this -> mkNav($href);
            // } else {
                // $return .= '\n\t\t<a href="' . $href . '">' . $a . '</a>';
            // }
            // $return .= "\n\t<li>";
        // }
        // return $this -> nav = $return .= "</ul>";
    // }


    public function adminFrame()
    {
       // AdminPanel::generate() -> header() -> footer();
    }

/**
 * Both of these functions need better logic, anyway - - - - 
 * 
 * 
 * For instance, in the case of Console - there needs to be a way to load "Console.js" 
 * - or some kind of hook, perhaps something like wp's enque_script
 * we'll get there.
 */
    // You should DRY yourself by the FIRE (Fucking Initialize a Refactoring
    // Engine)
    private function getStylesheets()
    {
        if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this->aspect . ".css")) {
            echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this->aspect . '.css" />';
        } else {
            echo "<!-- " . $this->aspect . ".css not found, so not loaded. -->";
        }
        if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this->endpoint . ".css")) {
            echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this->endpoint . '.css" />';
        } else {
            echo "<!-- " . $this->endpoint . ".css not found, so not loaded. -->";
        }

    }

    // I can definitely help you rewrite this so the Request class is more
    // loosely coupled, but you need to make a flowchart.
    // Nah, this is precisely what the Request class is for -!-
    // and yeah, we'll write a dependency map -

    // and Composer is likely going to come into play -
    private function getScripts()
    {
        if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->aspect}.js")) {
            echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this->endpoint . '.js" ></script>';
        } else {
            echo "<!-- " . $this->endpoint . ".js not found, so not loaded. -->";
        }
        if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->endpoint}.js")) {
            echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this->endpoint . '.js" ></script>';
        } else {
            echo "<!-- " . $this->endpoint . ".js not found, so not loaded. -->";
        }
    }

}
