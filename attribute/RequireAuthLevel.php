<?php

namespace Zero\Core; 
use \Attribute;

// todo, implement AttributeHandle interface or some such. 
#[Attribute] 
class RequireAuthLevel{

    public $approved;
    public $level; 
    public function __construct($lvl){
        session_start(); 
        $this->level = $lvl;
    }

    public function handler(){
        $this->approved = $_SESSION['auth_level'] >= $this->lvl; 
        if( session_status() == PHP_SESSION_NONE 
            || !$_SESSION['auth_level']
            ||  $_SESSION['auth_level'] < $this->lvl
        ){
            return new Error(ERROR_CODE_403); 
        }
        return $this->approved = true; 
    }

}
