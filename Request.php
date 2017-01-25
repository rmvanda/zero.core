<?php
/**
 * Request Class
 *
 * @author James Pope
 */

Namespace Zero\Core; 

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

    private $safeCharacters = array('-',".","/"); 

    public function __construct(){

        self::$uri      = trim(
                strtok(
                    $_SERVER['REQUEST_URI'],
                    "?"),
                "/")
            ?:"index/index";

        if(!$this->isValid(self::$uri)){
            new Err(403);     
        }

        $this->convertJSONtoPOST(); 
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

        self::$aspect   = strtolower(self::$uriArray[0]); 
        self::$endpoint = strtolower(self::$uriArray[1]); 

        if(strpos(self::$aspect,"-")!==false){
            self::$aspect = $this->friendlyURLConverter(self::$aspect); 
        }
        if(strpos(self::$endpoint,"-")!==false){
            self::$endpoint = $this->friendlyURLConverter(self::$endpoint); 
        }

        self::$args     = array_slice(self::$uriArray, 2);

        self::$method   = (empty($_POST) && count($_POST) === 0 )?"GET":"POST";
        self::$isAjax   = isAjax(); // cheating.
    }

    public static function test(){

        print_x(self::$uri);
        print_x(self::$uriArray);
        print_x(self::$protocol);
        print_x(self::$sub);
        print_x(self::$domain);
        print_x(self::$tld);
        print_x(self::$aspect);
        print_x(self::$endpoint);
        print_x(self::$args);
        print_x(self::$method);
    }

    private function isValid($uri){
        $test = $uri; 
        
        if(substr_count($uri,".") > 1){
            return false; 
        }

        foreach($this->safeCharacters as $bad){
        
            $test = str_replace($bad,"",$test); 
            
        }

        if(ctype_alnum($test)){
            return true; 
        }
        
    }

    private function convertJSONtoPOST(){

        if(!$_POST && 
           !empty($xdata = file_get_contents("php://input"))
        ){
            $_POST = json_decode($xdata, true);
        } else if(!empty($jerr = json_last_error_msg())){
        /* The above function will literally say "no error" as its error message >_> */
            if (json_last_error() !== 0) 
            {
                exit(json_encode(
                            array(
                                "status"=>"error", 
                                "code"=>json_last_error(),
                                "msg"=>$jerr
                                )
                            )
                    );
            }    
        }
        if(isset($xdata)){
            unset($xdata); 
        }
    }


/**
 * @function friendlyURLConverter
 * Converts requests-with-dashes to 
 *          requestWithCamelCase
 */
    private function friendlyURLConverter($url,$class=null)
    {
        $friendly = str_replace("-", " ", $url); 
        $urlwords = ucwords($friendly); 
        $friendly = str_replace(" ", "", $urlwords); 
        if($class === null){
            $friendly = lcfirst($friendly); 
        }
        return $friendly; 
    }




}
