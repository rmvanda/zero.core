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

//    public $fetchAsObject = array();  // That was almost properly clever
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
        Console::log("About to run query:");
        Console::log($query); 
        Console::log("With parameters:");
        Console::log($params);
        Console::log("Params before conversion:");
        Console::log($params); 
        Console::log("Params after conversion:"); 
/*        if(is_array($params) 
        && count($params) ==1 
        && isset($params[0]) 
        && is_array($params[0])){
            $params = $params[0]; 
        } // XXX should handle on the static method...
*/
        Console::log($query); 
        Console::log($params); 
        $stmnt = Connector::getSqlConnection()->prepare($query); 
        $stmnt->execute($params); 
          
        if(strpos($query, "INSERT") !== false){
            return Connector::getSqlConnection()->lastInsertId();            
        } else
        if(strpos($query, "SELECT") !== false){
            // so clever, yet so very stupid...
           /* foreach($this->fetchAsObject as $object){
                if(strpos($query,$object)!==false){
                    // unfortunately, this trick was too cheap 
                    return $stmnt; //$stmnt->fetchObject(ucfirst($object));    
                    
                }    
            }*/
            return $stmnt->fetchAll(); 
        }
        return $stmnt; 
        //return Connector::getSqlConnection()->prepare($query)->execute($params);
    }

    public function __call($func,$args){
        /*
        if(is_array($args) && count($args) == 1){
            $args = $args[0];     
        }*/
        // this is a cheap hack ~ 
        Console::log("Preparing to run $func, using parameters");
        Console::log($args); 

        $queryBank = get_called_class()."Query"; 
        if(class_exists($queryBank, false)){
            Console::log("Found query in query bank"); 
            return $this->doQuery($queryBank::$$func,$args[0]);     
        }if(isset($this->{$func}  )){
            Console::log("Found Query as property."); 
            return $this->doQuery($this->{$func},$args[0]);     
        } else {
            \xdebug_print_function_stack(); 
            die("<h1>Failed for $func</h1>");
            new Error(404);     
        }
        
    }

    public static function __callStatic($func,$args){
    
        Console::log("Statically caled $func using parameters:"); 
        Console::log($args); 
        if(!isset(self::$obj)){
            self::$obj = new static;    
        } 
/*
HEAD
        if(property_exists(self::$obj, $func)){
            return self::$obj->{$func}($args[0]); 
        } else {
            return self::$obj->{$func};
        }
=======
*/
//        if(property_exists(self::$obj, $func)){
        $return = self::$obj->{$func}($args[0]); 
        Console::log("came back with");
        Console::log($query); 
        return $return; 
  //      } else {
    //        Console::log("WARNING: This method of accessing properties - like $func - is soon to be deprecated..."); 
      //      return self::$obj->{$func};
       // }
//>>>>>>> 
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

