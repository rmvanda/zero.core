<?php

namespace Zero\Core; 

class Console {

    public static $loglvls = [
        "DEBUG",
        "INFO",
        "NOTICE",
        "WARN",
        "ERROR",
        "CRITICAL",
        "ALERT",
        "EMERGENCY",
    ];

    /**
     * 
     *
     */
    public static function log($message, 
                string|int|null $loglvl = null, 
                   string|null $logfile = null){

        /*
        if(!str_contains($_SERVER['REMOTE_ADDR'], DEV_SUBNET)){
            return; // TODO. 
        }
        */

        if(!defined("ZERO_LOG_LEVEL")){
            define("ZERO_LOG_LEVEL", "INFO"); 
        }
        define("ZERO_LOG_LEVEL_INT", 0); // TODO; put in a config or something. 
        if(defined("ZERO_LOG_LEVEL_INT")){
            $logthreshold = ZERO_LOG_LEVEL_INT;  
        } else {
            $logthreshold = array_search(ZERO_LOG_LEVEL, self::$loglvls); 
        }

        if(!$loglvl){
            $loglvl = 0; // DEBUG by default.. 
        }

        if(!is_numeric($loglvl)){
            $loglvlstring = $loglvl; 
            $loglvl = array_search($loglvl, self::$loglvls); 
        } else {
            $loglvlstring = self::$loglvls[$loglvl]; 
        }

        if($loglvl < $logthreshold){
            return; 
        }

        if(!$logfile){
            $logfile = "/var/log/php-fpm/zero.log"; 
        }

        if(is_array($message)||is_object($message)){
            $message = print_r($message,true);     
        }
        

        //$day  = gmdate("Y-m-d", time()); 
        $date = "[".gmdate("Y-m-d H:i:s", time())."] ";
        $ip     = "[{$_SERVER['REMOTE_ADDR']}] "; 
        $loglvlstring = "[$loglvlstring]: "; 

        file_put_contents($logfile, $date.$ip.$loglvlstring.$message."\n", FILE_APPEND);     

    }
    
    public static function __callStatic($loglvl,$msg){
        $mesg = $msg[0]; 
        self::log($mesg, strtoupper($loglvl)); 
    }

}
