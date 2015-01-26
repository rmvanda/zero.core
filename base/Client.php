<?php
/**
 * Client Class
 *
 * @author James Pope
 *
 * @version 0.8
 * This class should be used to parse out details about the client that are
 * useful to the application
 *
 */
//namespace Zero\Core;
class Client
{
    public static $isAdmin;

    private $ip, $userAgent, $deviceTye;
    private static $guessedLocation;
    public static $instance;

    public function __construct()
    {
        session_start();
        if (!Client::$instance)
            Client::$instance = $this;

    }

    public static function findLocation()
    {
        if (!self::$instance) {
            self::$instance = new self;
        }
        //TODO - City, State, Zip, etc.
        return self::$instance -> getLocation();

    }

    private function getLocation($output = 'json')
    {
        //@f:off 
        // FIXME ROUTING HACK for cestus - 
        $ip = $_SERVER['REMOTE_ADDR'] == "192.168.1.77" ? '99.135.100.109' : $_SERVER['REMOTE_ADDR']; 
           return  
            json_decode(
                file_curl_contents(
                    "http://ip-api.com/json/$ip")
                );
        //http://freegeoip.net/json/
        //http://ip-api.com/json/208.80.152.201
        //@f:on
    }

    public static function getGravatarImageUrl($email, $attrs = array(), $s = 50, $d = 'mm', $r = 'g')
    {
        $url = 'http://www.gravatar.com/avatar/';
        $url .= md5(strtolower(trim($email)));
        $url .= "?s=$s&d=$d&r=$r&f=y";
        // the image url (on gravatarr servers), will return in something like
        // http://www.gravatar.com/avatar/205e460b479e2e5b48aec07710c08d50?s=80&d=mm&r=g
        // note: the url does NOT have something like .jpg
        self::$user_gravatar_image_url = $url;
        // build img tag around
        $url = '<img src="' . $url . '"';
        foreach ($atts as $key => $val)
            $url .= ' ' . $key . '="' . $val . '"';
        $url .= ' />';
        // the image url like above but with an additional <img src .. /> around
        return self::$user_gravatar_image_tag = $url;
    }

}
