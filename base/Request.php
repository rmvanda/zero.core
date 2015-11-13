<?php
/**
 * Request Class
 *
 * @author James Pope
 */
class Request
{
    /*
     * Given a URL :
     * http://sub.domain.tld/aspect/endpoint/arg[0]/arg[1]/...argi[X]?query=string&etc
     * Parse it into useful bits so we can use them later, as needed.
     */
    public static 
        $uri, $url, 
        $protocol,
        $method,
        $queryString, 
        $sub, $subdomain, 
        $domain,
        $tld, 
        $aspect, $endpoint, $args, 
        $uriArray, 
        $isAjax, 
        $basePath;

    public function __construct(){

        self::$uri      = trim(
                            strtok(
                                $_SERVER['REQUEST_URI'],
                            "?"),
                          "/")
                        ?:"index/index";

        self::$uriArray = explode("/", self::$uri); 

        self::$uri      = "/".self::$uri; 
        
        if(count(self::$uriArray) === 1){
            self::$uri       .= "/index"; 
            self::$uriArray[] = "index"; 
        }

        self::$protocol = $_SERVER['SERVER_PORT'] == 80 ? "http" : "https";

        self::$sub      = self::$subdomain = implode(".", 
                            array_reverse(
                                array_slice(
                                    array_reverse(
                                        explode(".", $_SERVER['HTTP_HOST'])), 
                                    2)));

        self::$domain   = $_SERVER['HTTP_HOST']; 

        self::$tld      = array_slice(explode(".", self::$domain), -1); 

        self::$aspect   = self::$uriArray[0]; 
        self::$endpoint = self::$uriArray[1]; 
        self::$args     = array_slice(self::$uriArray, 2);

        self::$method   = (empty($_POST) && count($_POST) === 0 )?"GET":"POST";
        self::$isAjax   = isAjax(); // cheating.
    }

    public static function test(){
        print_x(self::$uri);
        print_x(self::$uriArray);
        print_x(self::$uri);
        print_x(self::$protocol);
        print_x(self::$sub);
        print_x(self::$domain);
        print_x(self::$tld);
        print_x(self::$aspect);
        print_x(self::$endpoint);
        print_x(self::$args);
        print_x(self::$method);
    }

}
