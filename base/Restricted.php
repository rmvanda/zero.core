<?php
//namespace Zero\Core;
class Restricted extends Request
{

    // This should be configurable (see: CentOS using /etc/php.fpm.d for example)
    const WHITELIST_PATH = "/etc/php5/fpm/whitelist.lst";
   // well, this is the configuration -- but yeah, it belongs in _config/ 
    public $authorized;
    public $accessLevel;
    private static $status;
    private static $instance;

    public function __construct()
    {
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
        
        define("ADMIN_PATH", ROOT_PATH."admin/");
        define("ADMIN_VIEW_PATH", ROOT_PATH . "admin/views/");
        require ADMIN_PATH."Admin.php"; 
        
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
     * if ($this->Authentication->granted()) { <do restricted stuff here> }
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
