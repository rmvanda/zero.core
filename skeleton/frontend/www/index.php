<?php
// Zero Framework Bootstrap
// ZERO_ROOT is the folder three levels above www/ (app/frontend/www inside the
// zero root), whatever the site folder is called.
define("ZERO_ROOT", dirname(__DIR__, 3) . "/");
define("ROOT_PATH", dirname(ZERO_ROOT) . "/");
define("VIEW_PATH", ZERO_ROOT . "app/frontend/frame/");
define("MODULE_PATH", ZERO_ROOT . "modules/");

require ZERO_ROOT . "core/Application.php";

$app = new Zero\Core\Application();

$app->registerAutoloaders();
$app->parseRequest();
$app->defineConstants();

$app->run(
    \Zero\Core\Request::$module,
    \Zero\Core\Request::$endpoint,
    \Zero\Core\Request::$args
);
