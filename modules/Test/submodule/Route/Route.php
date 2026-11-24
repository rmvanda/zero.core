<?php

namespace Zero\Core\Module\Test;

class Route extends \Zero\Core\Module {

    public function index() {
        $this->respond($this->viewPath . 'index.php');
    }
}
