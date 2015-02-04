<?php
/**
 * Model Class
 * This class simply establishes a connection to the database, generically.
 * Any other generic model functions can go here.
 *
 * @version 1.0
 *
 * */

class Model
{

    public function __construct()
    {
        if (!class_exists("ZXC")) {
            require ZXC;
            $db_config = array(
                "HOST" => HOST,
                "NAME" => NAME,
                "USER" => USER,
                "PASS" => PASS
            );
            ZXC::INIT($db_config);
        }
    }

}
