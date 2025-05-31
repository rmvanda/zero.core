<?php

namespace Zero\Core; 

class Console {

    public static function log($message,$logfile="/var/log/php-fpm/zero.log"){
        if(is_array($message)||is_object($message)){
            $message = print_r($message,true);     
        }
        file_put_contents($logfile.".log", "[".gmdate("Y-m-d H:i:s", time())." ]: ".$message."\n", FILE_APPEND);     
       // return static; 
    }
    
    public static function __callStatic($loglvl,$msg){
        $mesg = $msg[0]; 
        self::log($mesg,LOG_FILE."_".$loglvl); 
    }

}
