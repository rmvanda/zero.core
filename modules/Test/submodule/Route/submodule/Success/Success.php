<?php

namespace Zero\Core\Module\Test\Route;

class Success extends \Zero\Core\Module {

    public function index() {
        $this->respond($this->viewPath . 'index.php');
    }
}
