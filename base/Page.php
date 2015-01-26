<?php
/**
 * Page Class
 *
 * This class is responsible for rendering pages, and the like.
 *
 */
//namespace Zero\Core;
//use Zero\Core\Client as Client;
//use Zero\Core\Request as Request;
//namespace Zero\Core;
class Page extends Response
{

    //	use GeneralFunctions;

    public $nav;
    public $model;
    /**
     * @deprecated
     *
     * Things like this should be handled in JavaScript..
     * Or, loaded via json....
     */
    public $navigation;

    public function __construct($request)
    {
        parent::__construct();
        $this -> render($request);
    }

    /**
     * Recursively makes a menu with submenus.
     * based on
     * @param $nav array( $aTag => $href, $subMenu => array(//etc));
     *
     */
    public function navMenu($nav)
    {
        $return .= "\n<ul>";
        foreach ($nav as $a => $href) {
            $return .= "\n\t<li>";
            if (is_array($href)) {// vv mkNav has been renamed, yo
                $return .= $this -> mkNav($href);
            } else {
                $return .= '\n\t\t<a href="' . $href . '">' . $a . '</a>';
            }
            $return .= "\n\t<li>";
        }
        return $this -> nav = $return .= "</ul>";
    }

    public function render($view)
    {
        if (Request::isAjax()) {
            include $view;
        } else {
            $this -> build($view);
        }
    }

    public function adminFrame()
    {
        AdminPanel::generate() -> header() -> footer();
    }

    // This is the kind of thing where Zero would benefit from Origami.
    //
    // Yeah, no, I hate this class. 
    //
    public function build($view)
    {
        include VIEW_PATH . "_global/head.php";
        include VIEW_PATH . "_global/header.php";
        include $view;
        include VIEW_PATH . "_global/footer.php";
    }

    // You should DRY yourself by the FIRE (Fucking Initialize a Refactoring
    // Engine)
    private function getStylesheets()
    {
        if (file_exists(WEB_PATH . "assets/css/pg-specific/" . Request::$aspect . ".css")) {
            echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . Request::$aspect . '.css" />';
        } else {
            echo "<!-- " . Request::$aspect . ".css not found, so not loaded. -->";
        }
        if (file_exists(WEB_PATH . "assets/css/pg-specific/" . Request::$endpoint . ".css")) {
            echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . Request::$endpoint . '.css" />';
        } else {
            echo "<!-- " . Request::$endpoint . ".css not found, so not loaded. -->";
        }

    }

    // I can definitely help you rewrite this so the Request class is more
    // loosely coupled, but you need to make a flowchart.
    // Nah, this is precisely what the Request class is for -!-
    // and yeah, we'll write a dependency map -

    // and Composer is likely going to come into play -
    private function getScripts()
    {
        if (file_exists(WEB_PATH . "assets/js/pg-specific/{Request::$aspect}.js")) {
            echo '<script type="text/javascript" src="/assets/js/pg-specific/' . Request::$endpoint . '.js" ></script>';
        } else {
            echo "<!-- " . Request::$endpoint . ".js not found, so not loaded. -->";
        }
        if (file_exists(WEB_PATH . "assets/js/pg-specific/{Request::$endpoint}.js")) {
            echo '<script type="text/javascript" src="/assets/js/pg-specific/' . Request::$endpoint . '.js" ></script>';
        } else {
            echo "<!-- " . Request::$endpoint . ".js not found, so not loaded. -->";
        }
    }

}
