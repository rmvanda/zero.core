<?php
/**
 * class for consolidating external requests.. 
 *
 */

namespace Zero\Core; 


class API {

    private $instance; 
    private $curl; 
    public function __construct(){
        $this->curl = curl_init(); 
    }
    
    static public function($service){
        
    }
    
    public function __get(){ // meh, this is a hairbrained idea, anywa... 
        
    }

}
