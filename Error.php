<?php

namespace Zero\Core; 

/**
 * HTTP Error Codes class... TODO - probably want to rename this HTTPError 
 * Simply generates an error message screen and stops execution. 
 * Note all __desctruct methods will still run after exit();
 * 
 */ 

class Error extends \Zero\Core\Module
{
    private $message = array(				
		400 => "Bad Request",
		401 => "Unauthorized",
		402 => "Payment Required",
		403 => "Forbidden",
		404 => "Not Found",
		405 => "Method Not Allowed",
		406 => "Not Acceptable",
		407 => "Proxy Authentication Required",
		408 => "Request Timeout",
		409 => "Conflict",
		410 => "Gone",
		411 => "Length Required",
		412 => "Precondition Failed",
		413 => "Payload Too Large",
		414 => "Request-URI Too Long",
		415 => "Unsupported Media Type",
		416 => "Request Range Not Satisfiable",
		417 => "Expectation Failed",
		418 => "I’m a teapot",
		419 => "Page Expired",
		420 => "Enhance Your Calm",
		421 => "Misdirected Request",
		422 => "Unprocessable Entity",
		423 => "Locked",
		424 => "Failed Dependency",
		425 => "Too Early",
		426 => "Upgrade Required",
		428 => "Precondition Required",
		429 => "Too Many Requests",
		431 => "Request Header Fields Too Large",
		444 => "No Response",
		450 => "Blocked by Windows Parental Controls",
		451 => "Unavailable For Legal Reasons",
		495 => "SSL Certificate Error",
		496 => "SSL Certificate Required",
		497 => "HTTP Request Sent to HTTPS Port",
		498 => "Token expired/invalid",
		499 => "Client Closed Request",
		500 => "Internal Server Error",
		501 => "Not Implemented",
		502 => "Bad Gateway",
		503 => "Service Unavailable",
		504 => "Gateway Timeout",
		506 => "Variant Also Negotiates",
		507 => "Insufficient Storage",
		508 => "Loop Detected",
		509 => "Bandwidth Limit Exceeded",
		510 => "Not Extended",
		511 => "Network Authentication Required",
		521 => "Web Server Is Down",
		522 => "Connection Timed Out",
		523 => "Origin Is Unreachable",
		525 => "SSL Handshake Failed",
		530 => "Site Frozen",
		599 => "Network Connect Timeout Error"
	); 

    public function __construct(int $code, ?string $err = null) {

        parent::__construct(); 

        header("HTTP/1.1 $code " . ($this->message[$code]?? $err ?? "Unspecified"));

        if(!$err){
            $err = $this->message[$code]; 
        }

        $this -> generateErrorPage($code, $err);

        exit(); 
    }

    private function generateErrorPage(int $code, string $err) {
        
        $this->title = "Error: ".($message=ucwords(strtolower($this->message[$code])));
        
        if(Request::$acceptsJSON){
            $this->export(array("status"=>"error","message"=>$this->message[$code])); 
        } else {
            echo "<h1>$code - $message</h1><hr><h4>$err</h4><br>";     
            if(defined("DEVMODE") && DEVMODE == True){
                xdebug_print_function_stack(); 
            }
        }

    }

}
