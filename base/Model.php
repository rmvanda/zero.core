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

    public function __construct($alt_config=null)
    {
        if (!class_exists("ZXC", false)) {
            $db_config = $alt_config ?: array(
                    "HOST" => HOST,
                    "NAME" => NAME,
                    "USER" => USER,
                    "PASS" => PASS
                    );
            ZXC::INIT($db_config);
        }
    }
    /*
       public function __get($prop)
       {
       if ($prop == 'model') {
       if (!$this -> _default_model) {
       $model_name = get_class($this) . '_Model';
       $this -> _default_model = new $model_name();
       }
       return $this -> _default_model;
       }
       $model_name = $prop . '_Model';
       $this -> {$prop} = new $model_name();
    //prop__CLASS__;
    $model_name = $prop . '_Model';
    $this -> {$prop} = new $model_name();
    return $this -> {$prop};
    }
     */
}
