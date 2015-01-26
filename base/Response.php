<?php
/**
 *
 */
//namespace Zero\Core;
class Response
{

    private $aspect;
    public $model;

    public function __construct()
    {
        // There's still a major issue with having one model per response for anything other than really small projects (and even then..)
        // We can discuss this another time, however.
        if (file_exists(MODEL_PATH . ($name = ucfirst(Request::$aspect) . "Model") . ".php")) {
            $this -> model = new $name;
        } else {//@f:off
            if (!defined(JIT)) {//TODO :: JIT = "Just In Time" - aka- load the model, manually. not implemented, currently
                // This looks promising. I'd be interested to see what you're thinking here.
                // it was - a patch fix for being able to reuse database functions -  
                //  sort of a kludge, really - 
                $this -> model = new _GlobalModel();
            }
        }
    }

    public function __call($func, $args)
    {
        if (method_exists($this -> model, $func)) {
            $this -> model -> {$func}($args);
        } elseif (method_exists($this -> model, $func)) {
            return $this -> model -> {$func}(count($args) > 1 ? $args : $args[0]);
        } else {
            Error::_404("$func is not a valid thing");
        }

    }

    public function __get($prop)
    {
    }

    public function __set($prop, $val)
    {
    }

    private function load($aspect)
    {

    }

}
