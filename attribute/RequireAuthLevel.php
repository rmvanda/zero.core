<?php

namespace Zero\Core\Attribute;
use \Attribute;
use \Zero\Core\Console;
use \Zero\Core\Error;

#[Attribute]
class RequireAuthLevel{

    public $approved; // never actually get used, but is there if needed. 

    public function __construct(public $level = 9){}

    public function handler(){
        if( session_status() == PHP_SESSION_NONE
            || !$_SESSION['auth_level']
            ||  $_SESSION['auth_level'] < $this->level
        ){
            $userLevel = $_SESSION['auth_level'] ?? 'none';
            Console::warn("RequireAuthLevel attribute blocked request: required level {$this->level}, user level: {$userLevel}");
            return new Error(401, "Authentication required");
        }
        Console::debug("RequireAuthLevel attribute passed: user level {$_SESSION['auth_level']} >= required level {$this->level}");
        return $this->approved = true;
    }

}
