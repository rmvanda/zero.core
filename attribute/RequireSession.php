<?php
namespace Zero\Core\Attribute;
use \Attribute;

#[Attribute] 
class RequireSession{
    public function handler(){
        return session_start(); 
    }
}
