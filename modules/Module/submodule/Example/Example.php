<?php
/**
 * A submodule. Module::__call() resolves it when the parent has no matching
 * method for the URL segment. Segments then shift down:
 *   /parent/child/show/5  ->  Child::show('5')
 * Submodules nest infinitely.
 *
 * The namespace is the PARENT module's full name — no "submodule" segment.
 * Assets here symlink to /assets/{parent}/{child}/. Unlike frame/, which is
 * inherited by walking up the directory tree, asset directories are NOT
 * inherited — a submodule needs its own assets/ or its pages load no module CSS.
 * With no frame/ of its own, this inherits the parent's frame/head.php.
 *
 * MKMOD:SKELETON-ONLY-START
 * In the skeleton: Module has no example() method, so /module/example resolves
 * core/modules/Module/submodule/Example/ and dispatches here.
 * MKMOD:SKELETON-ONLY-END
 */
namespace Zero\Core\Module\Module;

class Example extends \Zero\Core\Module {

    public function index() {
        $this->respond($this->viewPath . 'index.php');
    }
}
