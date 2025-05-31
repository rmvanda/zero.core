<?php

Namespace Zero\Core; 

use \Zero\Core\Request as Request; 
class Module extends Response { 
// Yes, this class is secretly a way to realias Response to Module.
// Why do it this way? To maintain backward compatability until a more
// appropriate distinction between the two is made.
// 2024: 
// Also at this point, we _know_ we at least have an associated module that's about
// to be run. So that means we can do this: 
    public function __construct($altconfig = null){
        //var_dump(get_defined_constants(true)['user']);
        //var_dump($this);
        //$class = new \ReflectionClass('\Zero\Core\Request');
        //$staticProperties = $class->getStaticProperties();
        $aspect = Request::$aspect; 
        $target = MODULE_PATH.ucfirst($aspect)."/assets";
        $linknm = WEB_ROOT."/assets/".$aspect; // XXX Trailing slash shame...
        if(is_dir($target) && !is_dir($linknm)){
               //echo "Attempting to link $target to $linknm <br>"; 
               $results = symlink($target,$linknm);
               //echo "Link created!";
               var_dump($results); 
        } else {
            //echo "Nothing to do.";
        }

        // TODO: better logging here. 

        /*is_dir(WEB_ROOT."/assets/")
        foreach ($staticProperties as $propertyName => $value) {
            echo "<pre> $propertyName => $value </pre>"; 
        }
        */
        parent::__construct($altconfig); 
    }
}



