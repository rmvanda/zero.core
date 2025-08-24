<?php

namespace Zero\Core; 
use \Attribute;

#[Attribute] 
class RequireAuthLevel{

    public $approved;

    public function __construct($lvl){

        /*
        echo "<h1> Status is: </h1>"; 
        echo "--> ".session_status()."<-- "; 

        if(session_status() == PHP_SESSION_DISABLED){
            echo "<h1>Session disabled</h1>";
        }
        if(session_status() == PHP_SESSION_NONE){
            echo "<h1>Session NONE</h1>";
        }
        if(session_status() == PHP_SESSION_ACTIVE){
            echo "<h1>Session ACTIVE</h1>";
        }
        */
        if(session_status() == PHP_SESSION_NONE || !$_SESSION['auth_level']){
            return false; 
        }
        $this->approved = $_SESSION['auth_level'] >= $lvl; 
        return $this->approved; 

    }

}
