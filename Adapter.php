<?php


namespace Zero\Core; 


class Adapter{

    public $pdo;

    public function __construct(){ 
        
        $dsn  = "mysql:host=localhost;dbname=poker;port=3306;charset=utf8"; 
        $user = "poker"; 
        $pass = "vHkWhEOuzp2HFpHb"; 
        $this->pdo = new \PDO($dsn, $user, $pass); 
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

    }
    
    public function doQuery($query,$params){
    
        return $this->pdo->prepare($query)->execute($params);
        
    }

}
