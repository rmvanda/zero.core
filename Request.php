<?php
/**
 * Request Class
 *
 * @author James Pope
 */

namespace Zero\Core; 

class Request
{
    /*
     * Given a URL :
     * http://sub.domain.tld/module/endpoint/arg[0]/arg[1]/...argi[X]?query=string&etc
     * Parse it into useful bits so we can use them later, as needed.
     */
    public static 
        $uri, $url, 
        $protocol,
        $method,
        $sub, $subdomain, 
        $domain,
        $tld, 
        $module, $endpoint, $args,
        $moduleOrig, $endpointOrig,
        $Module,
        $uriArray, 
        $accepts, $acceptsJSON; 

//    private $safeCharacters = array('-',".","/"); 

    public function __construct(){

        self::$uri      = trim(
                                explode("?",
                                    $_SERVER['REQUEST_URI']
                                )[0], 
                                "/")
                            ?:"index/index";

        self::$accepts  = $_SERVER['HTTP_ACCEPT'] ?? null; 
        self::$acceptsJSON = false; 
        if(self::$accepts){
            $step = explode(",", self::$accepts); 
            foreach($step as $accept){
                $type = explode("/", $accept)[1]; 
                if($type == "json"){
                    self::$acceptsJSON = true;   
                }
            }
        }
        
        function p($msg){echo "====\n\n$msg\n\n=====";}


        //self::$uri      = explode(".",self::$uri)[0]; 


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

        self::$domain   = $_SERVER['HTTP_HOST'];  // FIXME - Should be a better way... 

        self::$tld      = array_slice(explode(".", self::$domain), -1); 

        self::$module   = strtolower(self::$uriArray[0]); // to normalize /BATSHIT/ReQuEsTs
        self::$endpoint = strtolower(self::$uriArray[1]); 

        self::$Module   = ucfirst(self::$module); 

        if(strpos(self::$module,"-")!==false){
            self::$moduleOrig = self::$module;
            self::$module = $this->friendlyURLConverter(self::$module); 
        }

        if(strpos(self::$endpoint,"-")!==false){
            self::$endpointOrig = self::$endpoint;
            self::$endpoint = $this->friendlyURLConverter(self::$endpoint); 
        }

        self::$args     = array_slice(self::$uriArray, 2);
        self::$args     = count(self::$args) !== 1 ? self::$args : self::$args[0]; 

        self::$method   = (empty($_POST) && count($_POST??[]) === 0 )?"GET":"POST";

	}

    public static function redirect($url){
        header("Location: ".$url) ;
        ob_clean_end();
        exit();
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
