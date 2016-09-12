<?php
/**
 * Model Class
 * This class simply establishes a connection to the database, generically.
 * Any other generic model functions can go here.
 *
 * @version 0.8
 *
 * */

class Model{  } // XXX
/*
    public function __construct($alt_config=null){
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
 }

// Sneaky trick/ hack : 
// If you know of a better place to put this, I'm all ears. 

function fetchColumnsFrom($table, $formatType=null){

    $a = ZXC::RAW("SHOW COLUMNS FROM ".$table)->go(); 
    $return = array(); 
    if(empty($formatType)){

        foreach($a as $column){
            $return[] = $column['Field'];
        }

        return $return; 

    } else {
        
        switch($formatType){ 
            case 1:
            case "raw":
                $return = $a;
            break;
            case 2:
            case "valueType":
                
                foreach($a as $column){
                
                    $type = $column['Type'] == "varchar"?$column['Type']: "string"; 
                    $return[$column['Field']] = $column['Type'];
                
                }
                
            break;
        }
    }

    return $return; 

}
*/
