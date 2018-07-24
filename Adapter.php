<?php


namespace Zero\Core; 

use \Memcache as Memcache; 

class Adapter{

    public static $obj; 

    public $pdo;
    public $mem; 

    public function __construct($params=null){ 
        
        $dsn  = "mysql:host=localhost;dbname=poker;port=3306;charset=utf8"; 
        $user = "poker"; 
        $pass = "vHkWhEOuzp2HFpHb"; 
        $this->pdo = new \PDO($dsn, $user, $pass); 
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

        $this->mem = new Memcache(); 
        $this->mem->addServer("localhost"); 

        if($params && isset($this->create)){
            $this->doQuery($this->create,$params);     
        }

    }
    
    public function doQuery($query,$params=null){
        $stmnt = $this->pdo->prepare($query); 
        $stmnt->execute($params); 
        return $stmnt; 
        //return $this->pdo->prepare($query)->execute($params);
    }

    public function __call($func,$args){
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
        return self::$obj->{$func}($args); 
    }


}
