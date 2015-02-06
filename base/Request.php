<?php
/**
 * Request Class
 *
 * @package Team Zero Framework
 * @author James Pope
 *
 *
 *///@f:on
//namespace Zero\Core;
class Request
{

    /* GOAL is to create public static variables for the application to access -
     * as well as to try and create them in a JIT fashion -
     /**
     * @f:off
     * Given a URL :
     * http://sub.domain.com/aspect/endpoint/arg0/.../argX?get=value&etc
     *|_________________________________________________________________|
     * @var $url,/*
     * @var $sub|
     *|_____|             |_____________________________||_____________|
     *   |                       |                             |
     *   |---------|             |                             |
                                $uri                   $queryString,/*
     * @var  $protocol,/* = ($_SE)"https"? = null; https = "https";
     * @var $subdomain,
     *
     */
    public static $url,$uri,$protocol,$queryString;
    public static  $sub,$subdomain,$domain, $aspect, $endpoint, $args, $uriArray, $accessible,$isAjax,$basePath;
    public static $isElevated; 
    //private static $instance;
 
     // This should be configurable (see: CentOS using /etc/php.fpm.d for example)
    const WHITELIST_PATH = "/etc/php5/fpm/whitelist.lst";
    // well, this is the configuration -- but yeah, it belongs in _config/
    public $authorized;
    public $accessLevel;
    private static $status;
    private static $instance;
 
    public function __construct()
    {

        self::$uri = trim(strtok($_SERVER['REQUEST_URI'], "?"), "/");
        
        if (!self::$uri) {
            self::$uri = 'index/index';
        }
        self::$uriArray = explode("/", self::$uri);
        
        if (count(self::$uriArray) == 1) {
            self::$uri .= "/index";
            self::$uriArray[] = "index";
        }
        self::$aspect = self::$uriArray[0];
        self::$endpoint = self::$uriArray[1] ? : "index";
        self::$protocol = $_SERVER['SERVER_PORT'] == 80 ? "http" : "https";
        self::$domain = $_SERVER['HTTP_HOST'];
        self::$url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        $this -> checkRequest();
    }

    private function iter(&$uri)
    {
        $r = $uri[0];
        array_shift($uri);
        return $r;
    }

    /**
     * This could maybe be shortened...
     * It just takes the HTTP_HOST & REQUEST URI to parse it out -
     * this allows the application to load everything else -- furthermore, we can
     * target some interesting things,
     */

    /*
     */
    public function parseRequest()
    {

        return;

        self::$uri = strtok($_SERVER['REQUEST_URI'], "?") === '/' ? array(
            "index",
            "index"
        ) : explode("/", filter_var($this -> url = trim(((strpos($_SERVER['REQUEST_URI'], '?') !== false) ? strtok($_SERVER['REQUEST_URI'], "?") : $_SERVER['REQUEST_URI']), '/'), FILTER_SANITIZE_URL));
    }

    private function parseSubdomain()
    {
        if (count($domain = explode(".", $_SERVER['HTTP_HOST'])) > 2) {
            self::$subdomain = $domain[0];
            unset($domain);
        };
    }

    private function checkRequest()
    {return; 
        if (filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_IP) || $_SERVER['SERVER_PORT'] == 8080) {
            Request::access();
        } else if (strpos($_SERVER['HTTP_HOST'], "admin") !== false) {
            Request::access() -> elevateRequest("admin");
            if ($subdomain = count(explode(".", $_SERVER['HTTP_HOST'])) > 2) {
                self::$subdomain = $subdomain[0];
            }

        }

    }

    public static function isAccessible()
    {
        return file_exists(self::$accessible = (self::$basePath ? : VIEW_PATH) . Request::$uri . ".php");
    }

    private function filterRequest()
    {
        $this -> runSpamChecks();
        foreach ($_POST as $key => $value) {
            switch($key) {
                case "" :
                    break;
                default :
                    filter_var($_POST[$key], FILTER_SANITIZE_STRING);
                    break;
            }
        }
    }

    public static function getArgs()
    {
        $args = explode("/", trim(self::$uri, "/"));
        array_shift($args);
        array_shift($args);
        return $args;
    }

    public static function isAjax()
    {
        return self::$isAjax ? : (@$_SERVER['HTTP_X_REQUESTED_WITH'] && strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ? true : false;
    }




    public static function access()
    {
        // It's a shame there's no __getStatic(), that would be handy here.

        if (!self::$instance) {
            self::$instance = new self;
        }
        self::$instance -> accessLevel ? : self::$instance -> getAccessClearanceLevel();
        return self::$instance;
    }

    private static function getAccessClearanceLevel()
    {
        // This method doesn't exist............. vvv
        if (self::isOnTheWhiteList() || self::hasEAccessCookie()) {
            return self::$instance -> authorized = true;
        } else {
            return Error::_403();
        }
    }

    public function elevateRequest($accessLevel)
    {
        //@f:off        
        //new Admin();
        
        define("ADMIN_PATH", ROOT_PATH);
        define("ADMIN_VIEW_PATH", ROOT_PATH . "views/");
        require "../Admin.php"; 
        
        Request::$basePath = ADMIN_VIEW_PATH;
        Request::$accessible = ADMIN_VIEW_PATH.Request::$aspect."/".Request::$endpoint.".php";
        
        Request::$isElevated = true;     
        
       // require ADMIN_PATH."base/Page.php"; 
 
    }


    /**
     * Returns true if the IP is on the whitelist specified by
     * self::WHITELIST_PATH
     * Returns false, otherwise.
     */
     
    /* Like I said in the chat.. these functions would be better served as a zero-whitelist driver that extends a general 
     * authentication Interface.. so it would be something like:
     * if ($this->Authentication->granted()) { <do Request stuff here> }
     * Then you could configure what the authentication method is here or ideally have a default here and use a config file to
     * override it.
     * 
     * Yeah, it needs to be organized a bit better - 
     */
    public static function isOnTheWhitelist()
    {
        // Do you mean self::parseWhiteList? .....vv
        if (in_array($_SERVER['REMOTE_ADDR'], parseWhitelist())) {
            return true;
        } elseif ($_COOKIE['acl'] == "ThisIsACookie") {
            return true;
        }

    }

    private function parseWhitelist()
    {
        // $rip = explode("\n",self::getRawWhiteList()); <--- explode always returns an array, so no need to specify it.. Also DRY!!
        // array_walk($rip,'trim'); <-- this trims every IP address
        // return $rip; <-- this could be part of line 2 really but I like having the return separate for various reasons...
        $rip = array();
        foreach (explode("\n", file_get_contents(self::WHITELIST_PATH)) as $ip) {    
            $ip = explode(" ", $ip);
            $rip[] = $ip[0];
        }
        return $rip;
    }

    private static function getWhitelist()
    {
        // parseWhiteList() isn't a static function..
        return self::parseWhitelist();
    }

    private static function getRawWhitelist()
    {
        //return explode("\n", file_get_contents(self::WHITELIST_PATH));
        return file_get_contents(self::WHITELIST_PATH);
    }

    /* I'd argue that this would be better served as:
     * function checkWhiteList($ip) and then pass the remote_addr over into it (setting $this->ip is also okay maybe)
     * Because you might want to check if someone else is on the whitelist someday and have a means of adding them..
     * So with one extra function this new class replaces addMeToTheWhiteList.php
     */
    private static function checkWhitelist()
    {
        return in_array($_SERVER['REMOTE_ADDR'], self::parseWhitelist());
    }
 
    // Where is this being called? It's private but nothing is accessing it...
    
    // A lot of repetition in here.. if you destaticified (?) the Error class you could make a function like:
    /* private function setHeaders($title,$error_code)
       {
     *      if ($title) {
     *          header('WWW-Authenticate: Basic Realm="'.$title.'"');
     *      }
     *      new Error($error_code);
     */
     // That would then take out a BUNCH of code, including extraneous exits() and deaths().
    private static function restrictAccess()
    {
        //$acl =
        //unserialize(ACCESS_CONTROL_LIST_FINAL_FOR_ADMINISTRATIVE_USE_ONLY);

        $acl = array("adminijamester" => "W3r3wq1v3srfdacade!!");

        //A Failsafe in case people really do botch the login.
        if ($_GET['pretty'] == "please") {
            if ($acl[$_SERVER['PHP_AUTH_USER']] == $_SERVER['PHP_AUTH_PW'])
                return;
            header('WWW-Authenticate: Basic realm="Only since you asked nicely…"');
            Error::_401(); 
            die();
        }
        //A kill-all-requests block.
        if ($_SERVER['PHP_AUTH_USER'] == "Fucked") {
           Error::_403();
        }
        // Add this to the elseif chain and then... (see 8D)
        // The actual, first-time login system.
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="Botch this login, and you will be locked out forever"');
            Error::_401();
        } elseif (!key_exists($_SERVER['PHP_AUTH_USER'], $acl)) {
            Error::_403(); 
            exit ;
            // --------------------------------------------------------------(8D) -- This statement is no longer necessary -- (8D)
        } elseif ($acl[$_SERVER['PHP_AUTH_USER']] != $_SERVER['PHP_AUTH_PW'] && $_SERVER['PHP_AUTH_USER'] != "Fucked") {
            header('WWW-Authenticate: Basic realm="OK, you get exactly one more chance."');
            Error:_401(); 

        }
    }
}
