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

        // Derive URL path from class namespace for symlink
        // Zero\Module\Ttrpg -> ttrpg
        // Zero\Module\Ttrpg\Characters -> ttrpg/characters
        $fullClass = get_class($this);
        $modulePath = substr($fullClass, strlen('Zero\\Module\\'));
        $urlPath = strtolower(str_replace('\\', '/', $modulePath));

        $classDir = dirname((new \ReflectionClass($this))->getFileName());
        $target = $classDir . "/assets";
        $linknm = WEB_ROOT . "/assets/" . $urlPath;

        if(is_dir($target) && !is_link($linknm) && !is_dir($linknm)){
            // Create parent directories if needed for nested submodules
            $parentDir = dirname($linknm);
            if (!is_dir($parentDir)) {
                mkdir($parentDir, 0755, true);
            }
            symlink($target, $linknm);
        }

        parent::__construct($altconfig);
    }

    /**
     * Override defineBasePaths to support frame inheritance for submodules
     * Submodules inherit their parent's frame if they don't have their own,
     * walking up the directory tree for proper recursive inheritance
     */
    protected function defineBasePaths(){
        $class_info = new \ReflectionClass(get_class($this));
        $dirname = dirname($class_info->getFileName())."/";

        foreach($this->paths as $path){
            $pathString = $path."Path";

            if($this->$pathString) {
                continue; // Already set
            }

            // Check if this module/submodule has its own
            if(is_dir($dirname.$path)){
                $this->$pathString = $dirname.$path."/";
                continue;
            }

            // For frame, walk up directory tree to find parent frame
            if ($path === 'frame') {
                $currentDir = rtrim($dirname, '/');
                $modulePathBase = rtrim(MODULE_PATH, '/');

                while ($currentDir && strlen($currentDir) > strlen($modulePathBase)) {
                    $parentDir = dirname($currentDir);

                    // Stop if we've gone above MODULE_PATH
                    if (strlen($parentDir) < strlen($modulePathBase)) {
                        break;
                    }

                    if (is_dir($parentDir . '/frame/')) {
                        $this->$pathString = $parentDir . '/frame/';
                        break;
                    }

                    $currentDir = $parentDir;
                }

                // If found a frame, continue to next path
                if ($this->$pathString) {
                    continue;
                }
            }

            // Fall back to global default
            $this->$pathString = ZERO_ROOT."app/frontend/".$path."/";
            // and reset this if we didn't set it to something interesting. 
        }

        $_SESSION['framePath'] = $this->framePath; 
    
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



