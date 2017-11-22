<?php

Namespace Zero\Core; 

class Error extends Module
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
            500 => "SERVER ERROR", 
            501 => "NOT IMPLEMENTED",
            502 => "BAD GATEWAY", 
            503 => "SERVICE UNAVAILABLE", 
            505 => "HTTP VERSION NOT SUPPORTED",
            506 => "VARIANT ALSO NEGOTIATES",
            509 => "BANDWIDTH LIMIT EXCEEDED",
            510 => "NOT EXTENDED",
            598 => "NETWORK READ TIMEOUT ERROR", 

            ); 

    public function __construct($code, $err = null) {

        header("HTTP/1.1 $code " . $this -> message[$code]);

        //xdebug_print_function_stack(); 

        parent::__construct(); 
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
        
        //if(file_exists($errPg = __DIR__   . "/views/_$code.php")){
        //    include $errPg;
        //}else{
        if(!Request::$accepts){
            echo "<h1>$code - $message</h1><hr><h4>$err</h4><br>";     
        } else {
            $this->export(array("status"=>"error","message"=>$this->message[$code])); 
        }
        //}
        

    }

}
