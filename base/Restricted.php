<?php
//namespace Zero\Core;
/**
 * ACCESS CONTROL
 *
 *
 */
class Restricted//extends Request
{
    public static $authorized;
    public function __construct()
    {
        //echo "recieved";
        //Console::output();"
        if ($_SERVER['REMOTE_ADDR'] === "192.168.1.77" || $_SERVER['REMOTE_ADDR'] == "50.199.113.222") {
            self::$authorized = true;
        } else {
            //  echo $_SERVER['REMOTE_ADDR'];
            new Error(403);
        }
    }

}
