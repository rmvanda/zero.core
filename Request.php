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
        $sub, $subdomain, 
        $domain,
        $tld, 
        $aspect, $endpoint, $args, 
        $Aspect,
        $uriArray, 
        $isAjax, 
        $accepts;

    private $safeCharacters = array('-',".","/"); 

    public function __construct(){

        self::$uri      = trim(
                                explode("?",
                                    $_SERVER['REQUEST_URI']
                                )[0], 
                                "/")
                            ?:"index/index";


        self::$accepts  = explode(".",self::$uri)[1]; 
        if(!$this->isValidType(self::$accepts)){
           new Error(404, "Not sure where that is...");    
        } 
        self::$uri      = explode(".",self::$uri)[0]; 

        if(!$this->isValid(self::$uri)){
            new Error(403);     
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

        self::$domain   = $_SERVER['HTTP_HOST'];  // FIXME - Should be a better way... 

        self::$tld      = array_slice(explode(".", self::$domain), -1); 

        self::$aspect   = strtolower(self::$uriArray[0]); // to normalize /BATSHIT/ReQuEsTs
        self::$endpoint = strtolower(self::$uriArray[1]); 

        self::$Aspect   = ucfirst(self::$aspect); 

        if(strpos(self::$aspect,"-")!==false){
            self::$aspect = $this->friendlyURLConverter(self::$aspect); 
        }
        if(strpos(self::$endpoint,"-")!==false){
            self::$endpoint = $this->friendlyURLConverter(self::$endpoint); 
        }

        self::$args     = array_slice(self::$uriArray, 2);
        self::$args     = count(self::$args) !== 1 ? self::$args : self::$args[0]; 

        self::$method   = (empty($_POST) && count($_POST) === 0 )?"GET":"POST";
        self::$isAjax   = self::isAjax(); 

	}

    public static function redirect($url){
        header("Location: ".$url) ; 
        ob_clean_end(); 
        exit(); 
    }

	public static function isAjax()
	{
		return 
			(
						@$_SERVER['HTTP_X_REQUESTED_WITH'] &&	
			 strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest'
			) 
			? true : false;
	}

    private function isValid($uri){

        $test = $uri; 
        
        if(substr_count($uri,".") > 1){
            return false; 
        }

        foreach($this->safeCharacters as $safe){
            $test = str_replace($safe,"",$test); 
            
        }

        if(ctype_alnum($test)){
            return true; 
        }
        
    }

    private function isValidType($type){

        if(empty($type)   ||
           $type == "json"||
           $type == "xml" ){
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
