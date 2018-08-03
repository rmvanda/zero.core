<?php


namespace Zero\Core; 

use \Memcache as Memcache; 
use \Zero\Core\Console as Console; 

class Connector{

    public static $dbo; 
    public static $mem;

    public static function getSqlConnection(){
        if(!isset(self::$dbo)){
            $dsn  = "mysql:host=localhost;dbname=poker;port=3306;charset=utf8"; 
            $user = "poker"; 
            $pass = "vHkWhEOuzp2HFpHb"; 
            self::$dbo = new \PDO($dsn, $user, $pass); 
            self::$dbo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            self::$dbo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        }
        return self::$dbo; 
    }

    public static function getMemConnection(){
        if(!isset(self::$mem)){ 
            self::$mem = new Memcache(); 
            self::$mem->addServer("localhost"); 
        }
        return self::$mem;
    }

}

class Adapter{

    public static $obj; 

    public $pdo;
    public $mem; 

    public function __construct($params=null){ 
        
        $this->pdo = Connector::getSqlConnection();
        $this->mem = Connector::getMemConnection(); 

        if(is_array($params)){
            foreach ($params as $key => $value) {
                $this->{$key} = $value;     
            }   
        }

        if($params && isset($this->create)){
            $this->doQuery($this->create,$params);     
        }

    }
    
    public function doQuery($query,$params=null){
        $stmnt = $this->pdo->prepare($query); 
        $stmnt->execute($params); 
        if(strpos($query, "INSERT") !== false){
            return $this->pdo->lastInsertId();            
        } else
        if(strpos($query, "SELECT") !== false){
            return $stmnt->fetchAll(); 
        }
        return $stmnt; 
        //return $this->pdo->prepare($query)->execute($params);
    }

    public function __call($func,$args){
        if(is_arraY($args) && count($args) == 1){
            $args = $args[0];     
        }
        if(isset($this->{$func})){
            $a = $this->doQuery($this->{$func},$args[0]);     
            return $a; 
        } else {
            new Error(404);     
        }
        
    }

    public static function __callStatic($func,$args){
    
        if(!isset(self::$obj)){
            self::$obj = new static;    
        } 
        Console::log("Called Statically..."); 
        if(property_exists(self::$obj, $func)){
            Console::log("Property $func exists...?");
            return self::$obj->{$func}($args); 
        } else {
            Console::log("Property $func does NOT exist...?");
            return self::$obj->{$func};
        }
    }

    /**
     * This could get messy, but if sub-classes use reasonable namespacing, 
     * then this'll be fine... 
     * 
     */

    public function __get($attr){
        return unserialize($this->mem->get($attr)); 
    }

    public function __set($attr,$val){
        $this->{$attr} = $val; 
        $this->mem->set($attr,serialize($val)); 
    }

}
