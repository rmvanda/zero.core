<?php

namespace Zero\Core; 
use \Attribute;

// todo, implement AttributeHandle interface or some such. 
#[Attribute] 
class RequireAuthLevel{

    public $approved;

    public function __construct($lvl){
        session_start(); 
    }

    public function handler(){
        if(session_status() == PHP_SESSION_NONE || !$_SESSION['auth_level']){
            return false; 
        }
        $this->approved = $_SESSION['auth_level'] >= $lvl; 
        //if(!$this->approved){
        if($this->approved){
            return new Error(ERROR_CODE_403); 
        }
        return $this->approved; 
    }

}
