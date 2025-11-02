<?php

namespace Zero\Core\Attribute; 
use \Attribute;
use \Zero\Core\Console; 

#[Attribute] 
class RequireAuthLevel{

    public $approved;
    public $level; 
    public function __construct($lvl){
        session_start(); 
        $this->level = $lvl;
    }

    public function handler(){
        $this->approved = $_SESSION['auth_level'] >= $this->level;
        if( session_status() == PHP_SESSION_NONE
            || !$_SESSION['auth_level']
            ||  $_SESSION['auth_level'] < $this->level
        ){
            $userLevel = $_SESSION['auth_level'] ?? 'none';
            Console::warn("RequireAuthLevel attribute blocked request: required level {$this->level}, user level {$userLevel}");
            return new Error(ERROR_CODE_403);
        }
        Console::debug("RequireAuthLevel attribute passed: user level {$_SESSION['auth_level']} >= required level {$this->level}");
        return $this->approved = true;
    }

}
