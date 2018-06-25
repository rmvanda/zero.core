<?php

namespace Zero\Core; 

class Console {

    private static $tabs; 

    public static function log($message){
        if(is_array($message)){
            $message = print_r($message,true);     
        }
        file_put_contents(LOG_FILE, "[".gmdate("Y-m-d H:i:s", time())." ]: ".$message."\n",FILE_APPEND);     
       // return static; 
    }
    
}
