<?php

class Error 
{
    private $message = array(				
            400 => "BAD REQUEST", 				
            402 => "PAYMENT REQUIRED", 				
            401 => "NOT AUTHORIZED", 				
            403 => "FORBIDDEN", 				
            404 => "NOT FOUND", 				
            405 => "METHOD NOT ALLOWED", 				
            406 => "NOT ACCEPTABLE", 				
            407 => "PROXY AUTHENTICATION REQUIRED", 				
            408 => "REQUEST TIMEOUT", 				
            409 => "CONFLICT", 				
            410 => "GONE", 				
            411 => "LENGTH REQUIRED", 				
            412 => "PRECONDITION FAILED", 				
            413 => "REQUEST ENTITY TOO LARGE", 				
            414 => "REQUEST-URI TOO LONG", 				
            415 => "UNSUPPORTED MEDIA TYPE", 				
            416 => "UNSUPPORTED MEDIA TYPE", 				
            417 => "EXPECTATION FAILED", 				
            418 => "I'M A LITTLE TEAPOT", 				
            420 => "ENHANCE YOUR CALM",
            505 => "STOP THAT"
            ); 

    public function __construct($code, $opt = null) {

        header("HTTP/1.1 $code " . $this -> message[$code]);
        
        if(defined(DEV) && DEV !== false && $_GET['shwtrc']) {
            xdebug_print_function_stack();
        }
        $this -> generateErrorPage($code, $opt?:$this -> message[$code]);
        exit(); 
    }

    public static function __callStatic($func, $args) {
        return new Error(trim($func, "_"), $args);
    }

    public static function JSON() {

        switch(json_last_error()) {
            case '' :
                break;
            default :
                die("Something went wrong with the json parsing...");
                break;
        }

    }

    private function generateErrorPage($code, $message) {
        
        $title = "Error: ".ucwords(strtolower($message))." | $code"; 
        include VIEW_PATH . "_global/head.php"; 
        include VIEW_PATH . "_global/header.php";
        if(file_exists($errPg = __DIR__   . "/views/error$code.php")){
            include $errPg;
        }else{
            echo "<h1>$code</h1><br><h2>$message</h2><hr>";     
        }

        include VIEW_PATH . "_global/footer.php";

    }

}
