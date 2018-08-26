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

//    public $pdo;
//    public $mem; 

    public function __construct($params=null){ 
        
        if(is_array($params)){
            foreach ($params as $key => $value) {
                $this->{$key} = $value;     
            }   
        }

    //    if($params && isset($this->create)){
    //        $this->doQuery($this->create,$params);     
    //    }

    }
    
    public function getMemConnection(){
        return Connector::getMemConnection();    
    }

    public function doQuery($query,$params=null){
        $stmnt = Connector::getSqlConnection()->prepare($query); 
        var_dump($query);var_dump($params); 
        $stmnt->execute($params); 
            
        if(strpos($query, "INSERT") !== false){
            return Connector::getSqlConnection()->lastInsertId();            
        } else
        if(strpos($query, "SELECT") !== false){
            return $stmnt->fetchAll(); 
        }
        return $stmnt; 
        //return Connector::getSqlConnection()->prepare($query)->execute($params);
    }

    public function __call($func,$args){
        if(is_array($args) && count($args) == 1){
            $args = $args[0];     
        }
        // this is a cheap hack ~ 
        $queryBank = get_called_class()."Query"; 
        if(class_exists($queryBank, false)){
            return $this->doQuery($queryBank::$$func,$args);     
        }if(isset($this->{$func}  )){
            return $this->doQuery($this->{$func},$args);     
        } else {
            \xdebug_print_function_stack(); 
            die("<h1>Failed for $func</h1>");
            new Error(404);     
        }
        
    }

    public static function __callStatic($func,$args){
    
        if(!isset(self::$obj)){
            self::$obj = new static;    
        } 
        if(property_exists(self::$obj, $func)){
            return self::$obj->{$func}($args[0]); 
        } else {
            return self::$obj->{$func};
        }
    }

    /**
     * This could get messy, but if sub-classes use reasonable namespacing, 
     * then this'll be fine... 
     * 
     */

    public function __get($attr){
        return unserialize(Connector::getMemConnection()->get($attr)); 
    }

    public function __set($attr,$val){
        $this->{$attr} = $val; 
        Console::log("Setting $attr to...");
        Console::log($val); 

        
        Connector::getMemConnection()->set($attr,serialize($val)); 
        $map = json_decode(file_get_contents("/home/james/dev/php/zero/modules/poker/memmap.json"));
        $map->{$attr} = $val; 
        file_put_contents("/home/james/dev/php/zero/modules/poker/memmap.json", json_encode($map, JSON_PRETTY_PRINT)); 
    }

}

class SQLAdapter extends Adapter{

    public $getQuery = "SELECT %s FROM %s WHERE %s=:classnameid"; 
    public $setQuery = "UPDATE %s  SET %s=:val WHERE %s=:classnameid"; 

    public $tablename; 


    public function getParams($attrs=array()){

        $classname = explode("\\",strtolower(get_called_class())); 
        $classname = array_pop($classname); 
        $classidnm = $classname."id"; 

        $this->tablename = $classname; 
        

        $return = array(
            "classnameid"       => $this->{$classidnm} 
        ); 
        return array_merge($return,$attrs);  
    }

    public function __get($attr){
        echo "trying to get $attr"; 
        $params = $this->getParams();
        $query  = sprintf($this->getQuery, $attr,$this->tablename,$this->{$this->tablename."id"}); 
        return $this->doQuery($query,$params); 
    }
    
    public function __set($attr,$val){
        $params = $this->getParams(array("val"=>$val)); 
        $query  = sprintf($this->setQuery, $this->tablename,$attr,$this->tablename."id"); 
        return $this->doQuery($query,$params); 
    }

}

