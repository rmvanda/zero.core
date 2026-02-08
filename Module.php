<?php

Namespace Zero\Core; 

use \Zero\Core\Request as Request; 
class Module extends Response { 
// Yes, this class is secretly a way to realias Response to Module.
// Used to just have things extend Response, so this way we can have both..
// At some point, I thought there may be a meaningful distinction between the two
// and maybe that's still the case. Not today, however.
// 
// Also at this point, we _know_ we at least have an associated module that's about
// to be run. So that means we can create symlinks on the fly. 
// Which TODO - make this part of some admin install/uninstall thing, instead of 
// just creating symlinks for any given _existing_ module. 
// OTOH, not like this creates much of a performance hit. 
    public function __construct($altconfig = null){

        //var_dump(get_defined_constants(true)['user']);
        //var_dump($this);
        //$class = new \ReflectionClass('\Zero\Core\Request');
        //$staticProperties = $class->getStaticProperties();
        $module = Request::$module;
        $target = MODULE_PATH.ucfirst($module)."/assets";
        $linknm = WEB_ROOT."/assets/".$module;
        if(is_dir($target) && !is_dir($linknm)){
               $results = symlink($target,$linknm);
        }
        parent::__construct($altconfig);
    }

    /**
     * Override __call to check for submodules before falling back to Response::__call
     * (view file lookup). Naturally recursive — submodules extend Module, so their
     * own __call does the same check, enabling infinite nesting.
     */
    public function __call($func, $args) {
        $submoduleName = ucfirst($func);
        $moduleDir = dirname((new \ReflectionClass(get_class($this)))->getFileName());
        $submodulePath = $moduleDir . '/submodule/' . $submoduleName . '/' . $submoduleName . '.php';

        if (file_exists($submodulePath)) {
            require_once $submodulePath;

            // Class name mirrors namespace: Zero\Module\Ttrpg + \Characters, etc.
            $subClass = get_class($this) . '\\' . $submoduleName;
            $submodule = new $subClass();

            // $args[0] is whatever was passed to the missing method
            $subArgs = $args[0] ?? [];
            if (!is_array($subArgs)) {
                $subArgs = [$subArgs];
            }

            // Shift first arg as the new endpoint, default to index
            $subEndpoint = array_shift($subArgs) ?: 'index';

            $submodule->{$subEndpoint}($subArgs);
            return;
        }

        parent::__call($func, $args);
    }
}



