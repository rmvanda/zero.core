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
    public static  $sub,$subdomain,$domain, $aspect, $endpoint, $args, $uriArray, $accessible,$isAjax;
    private static $instance, $basePath;
 
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
    {
        if (filter_var($_SERVER['HTTP_HOST'], FILTER_VALIDATE_IP) || $_SERVER['SERVER_PORT'] == 8080) {
            Restricted::access();
        } else if (strpos($_SERVER['HTTP_HOST'], "admin") !== false) {
            Restricted::access() -> elevateRequest("admin");
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

}
