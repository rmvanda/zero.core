<?php

class Err extends Module
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

    public function __construct($code, $err = null) {

        parent::__construct(); 
        header("HTTP/1.1 $code " . $this -> message[$code]);
        
        if(defined(DEVMODE) && DEV !== false) {
        //    xdebug_print_function_stack();
        }
        $this -> generateErrorPage($code, $err);
        exit(); 
    }

    public static function __callStatic($func, $args) {
        return new Err(trim($func, "_"), $args);
    }

    public static function JSON() {

        switch($jsonerr=json_last_error()) {
            case '' :
                break;
            default :
                echo $jsonerr; 
                die("Something went wrong with the json parsing...");
                break;
        }

    }

    private function generateErrorPage($code, $err) {
        
        $this->title = "Error: ".($message=ucwords(strtolower($this->message[$code])));
        
        $this->buildHead(); 
        $this->buildHeader(); 
        
        //if(file_exists($errPg = __DIR__   . "/views/_$code.php")){
        //    include $errPg;
        //}else{
        echo "<h1>$code - $message</h1><h4>$err</h4><br><hr>";     
        //}
        
        $this->buildFooter(); 

    }

}
