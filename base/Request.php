<?php
/**
 * Request Class
 *
 * @package Team Zero Framework
 * @author James Pope
 */
//namespace Zero\Core;
class Request
{
    /*
     * Given a URL :
     * http://sub.domain.com/aspect/endpoint/arg0/.../argX?query=string&etc
     */
    public $uri;
    public static $guri;
    private $url, 
            $protocol, 
            $queryString, 
            $sub, $subdomain, 
            $domain, 
            $aspect, $endpoint, $args, 
            $uriArray, 
            $isAjax, 
            $basePath;

    
    public $isElevated;
    public $authorized;
    public $accessLevel;

    private $status;
    private static $instance;

    public function __construct()

    {
        Request::$instance = $this; 
        Request::$guri = $this -> uri = trim(
                 strtok($_SERVER['REQUEST_URI'], "?"), "/");

        if (!$this -> uri) {
            $this -> uri = 'index/index';
        }
        $this -> uriArray = explode("/", $this -> uri);

        if (count($this -> uriArray) == 1) {
            $this -> uri .= "/index";
            $this -> uriArray[] = "index";
        }
        self::$instance = $this;
    }

    public static function __callStatic($func, $args ){     
        return self::$instance->{$func}; 
    }


    public static function get($prop)
    {
        return self::$instance -> __get($prop);
    }

    public function __get($prop)
    {
        switch($prop) {
            case 'aspect' :
                return $this -> aspect ? :
                       $this -> aspect = $this -> uriArray[0];
                break;
            case 'endpoint' :
                return $this -> endpoint ? : 
                       $this -> endpoint = $this -> uriArray[1] ? : "index";
                break;
            case 'protocol' :
                return $this -> protocol ? :
                       $this -> protocol = $_SERVER['SERVER_PORT'] == 80 ? 
                       "http" : "https";
                break;
            case 'domain' :
                return $this -> domain ? : 
                       $this -> domain = $_SERVER['HTTP_HOST'];
                break;
            case 'url' :
                return $this -> url ? : 
                       $this -> url = $_SERVER['HTTP_HOST'] . $this -> uri;
                break;
            case "args" :
                return $this -> args ? : 
                       $this -> args = array_slice($this -> uriArray, 2);
                break;
            case "uri" :
                return $this -> uri;
                break;
            case "subdomain" :
                return implode(".", 
                        array_reverse(
                            array_slice(
                                array_reverse(
                                    explode(".", $_SERVER['HTTP_HOST'])), 2)));
                //array_slice(explode(".",$_SERVER['HTTP_HOST'],0,-2))
                // This portion does the hokeyPokey instead of the 1 line above
                // In order to accomodate instances of subdomains like: 
                // some.stupid.long.cdn.directory.chain.whatever.domain.com 
                // in which case, the module leveraging that can be aware of
                // the .'s imbetween - and act accordingly. 
                break;
            case "method" : 
                return (empty($_POST) && count($_POST) === 0 ) ? 
                       "GET" : "POST";
                break;
            case "isAccessible" :
            case "access" :
                return $this -> isAccessible ? : 
                       $this -> isAccessible = new Restricted(); // REVIEW
                break;
        }
    }

    private function parseSubdomain()
    {
        if (count($domain = explode(".", $_SERVER['HTTP_HOST'])) > 2) {
            $this -> subdomain = $domain[0];
            unset($domain);
        };
    }

    /*
       public function isAccessible()
       {
       return file_exists($this -> accessible = ($this -> basePath ? :
       VIEW_PATH) .
       Request::$uri . ".php");
       }
     */
    public function getArgs()
    {
        $args = explode("/", trim($this -> uri, "/"));
        array_shift($args);
        array_shift($args);
        return $args;
    }

}
