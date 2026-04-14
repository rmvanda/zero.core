<?php
// Zero Framework Bootstrap
// Locates the zero/ directory by splitting on $SiteName in the path.
// The www/ directory must live inside zero/app/frontend/www/
$SiteName = "zero";
$root_path = explode($SiteName, __DIR__);
if (($c = count($root_path)) == 1 || $c > 2) {
    trigger_error(
        "Cannot find zero libraries from index directory." .
        " If this is your preferred setup, rename index.alt.php" .
        " to index.php and specify paths manually." .
        " Otherwise, revisit the setup portion of the documentation."
    );
}

define("ZERO_ROOT", $root_path[0] . $SiteName . "/");
define("ROOT_PATH", $root_path[0]);
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
