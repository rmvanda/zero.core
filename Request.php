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
        $uri,
        $protocol,
        $method,
        $sub, $subdomain, 
        $domain,
        $tld, 
        $module, $endpoint, $args,
        $moduleOrig, $endpointOrig,
        $Module,
        $uriArray, 
        $accepts, $acceptsJSON, 
        $madeWithAJAX;


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
        
        //self::$uri      = explode(".",self::$uri)[0];

        $this->convertJSONtoPOST();
        self::$uriArray = explode("/", self::$uri); 

        self::$uri      = "/".self::$uri; 

        if(count(self::$uriArray) === 1){
            self::$uri       .= "/index"; 
            self::$uriArray[] = "index"; 
        }

        self::$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";

        self::$sub      = self::$subdomain = implode(".", 
                array_reverse(
                    array_slice(
                        array_reverse(
                            explode(".", $_SERVER['HTTP_HOST'])), 
                        2)));

        self::$domain   = $_SERVER['HTTP_HOST'];  // FIXME - Should be a better way... 

        self::$tld      = array_slice(explode(".", self::$domain), -1)[0];

        self::$module   = strtolower(self::$uriArray[0]); // to normalize /BATSHIT/ReQuEsTs
        self::$endpoint = strtolower(self::$uriArray[1]); 

        self::$Module   = ucfirst(self::$module);

        self::$moduleOrig = self::$module;
        if(strpos(self::$module,"-")!==false){
            self::$module = $this->friendlyURLConverter(self::$module);
            self::$Module = ucfirst(self::$module);
        }

        self::$endpointOrig = self::$endpoint;
        if(strpos(self::$endpoint,"-")!==false){
            self::$endpoint = $this->friendlyURLConverter(self::$endpoint); 
        }

        self::$args     = array_slice(self::$uriArray, 2);
        //self::$args     = count(self::$args) !== 1 ? self::$args : self::$args[0]; 

        self::$method   = $_SERVER['REQUEST_METHOD'];

        self::$madeWithAJAX = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

	}

    public static function redirect($url){
        header("Location: ".$url) ;
        ob_clean_end();
        exit();
    }

    private function convertJSONtoPOST(){

        // Only auto-decode bodies that declare themselves as JSON. Raw binary
        // uploads (image/jpeg, application/octet-stream, …) must pass through
        // untouched so endpoints can read php://input themselves — decoding
        // them as JSON throws JSON_ERROR_UTF8 on perfectly valid bytes.
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        if (stripos($contentType, "application/json") === false) {
            return;
        }

        if(!$_POST &&
           !empty($xdata = file_get_contents("php://input"))
        ){
            $_POST = json_decode($xdata, true);
            /* json_last_error_msg() will literally say "no error" as its error message >_> */
            if (json_last_error() !== 0)
            {
                // A malformed JSON body is a client error — respond 400, not the
                // implicit 200 that exit() alone would send.
                http_response_code(400);
                header("Content-Type: application/json");
                exit(json_encode(
                            array(
                                "status"=>"error",
                                "code"=>400,
                                "msg"=>"Malformed JSON body: ".json_last_error_msg()
                                )
                            )
                    );
            }
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
