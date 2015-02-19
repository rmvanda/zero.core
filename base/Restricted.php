<?php
	//namespace Zero\Core;
	/**
	 * ACCESS CONTROL
	 *
	 *
	 */
	class Restricted//extends Request
	{
		public static $authorized;
		public function __construct()
		{
			//echo "recieved";
			//Console::output();"
			if ($_SERVER['REMOTE_ADDR'] === "192.168.1.77" || $_SERVER['REMOTE_ADDR'] == "50.199.113.222") {
				self::$authorized = true;
			} else {
				//  echo $_SERVER['REMOTE_ADDR'];
				new Error(403);
			}
		}

		// public static function access()
		// {
		// // It's a shame there's no __getStatic(), that would be handy here.
		//
		// if (!self::$instance) {
		// self::$instance = new self;
		// }
		// self::$instance -> accessLevel ? : self::$instance ->
		// getAccessClearanceLevel();
		// return self::$instance;
		// }

		private static function getAccessClearanceLevel()
		{
			// This method doesn't exist............. vvv
			if (self::isOnTheWhiteList() || self::hasEAccessCookie()) {
				return self::$instance -> authorized = true;
			} else {
				return new Error(403);
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
            new Error(401); 
            die();
        }
        //A kill-all-requests block.
        if ($_SERVER['PHP_AUTH_USER'] == "Fucked") {
           new Error(403);
        }
        // Add this to the elseif chain and then... (see 8D)
        // The actual, first-time login system.
        if (!isset($_SERVER['PHP_AUTH_USER'])) {
            header('WWW-Authenticate: Basic realm="Botch this login, and you will be locked out forever"');
            new Error(401);
        } elseif (!key_exists($_SERVER['PHP_AUTH_USER'], $acl)) {
            new Error(403); 
            exit ;
            // --------------------------------------------------------------(8D) -- This statement is no longer necessary -- (8D)
        } elseif ($acl[$_SERVER['PHP_AUTH_USER']] != $_SERVER['PHP_AUTH_PW'] && $_SERVER['PHP_AUTH_USER'] != "Fucked") {
            header('WWW-Authenticate: Basic realm="OK, you get exactly one more chance."');
            new Error(401); 

        }
    }
}
