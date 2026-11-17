<?php
namespace Zero\Core; 

class SQLAdapter extends Adapter{

    private $tablename; 

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
        $getQuery = "SELECT %s FROM %s WHERE %s=:classnameid";
        $params = $this->getParams();
        $query  = sprintf($getQuery, $attr,$this->tablename,$this->tablename."id"); 
        //\Zero\Core\Console::log($query); 
        return $this->doQuery($query,$params)[0][$attr]; 
    }
    
    public function __set($attr,$val){
        $setQuery = "UPDATE %s  SET %s=:val WHERE %s=:classnameid";
        $params = $this->getParams(array("val"=>$val)); 
        $query  = sprintf($setQuery, $this->tablename,$attr,$this->tablename."id"); 
        //var_dump($query); 
        //var_dump($params); 
        return $this->doQuery($query,$params); 
    }

}

