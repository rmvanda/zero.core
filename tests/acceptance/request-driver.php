<?php
/**
 * One request to a site, in its own PHP process, as a web server would make it.
 *
 * Run by BareSiteTest as `php request-driver.php <spec.json>`. The spec names the
 * site, the request (method, uri, post, accept, ip), the session id to continue
 * and where to put the answer. The page goes to stdout; what a web server would
 * have sent besides the body (status, headers, the session id the request ended
 * with) and the name of the database the request's own connection is on go to
 * the `meta` file the spec names.
 *
 * With a `script` in the spec no page is served: the site is bootstrapped as
 * index.php does it and the script runs in that site (a test grants a capability
 * this way, in the site's own database).
 */

$spec = json_decode(file_get_contents($argv[1]), true);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', $spec['errorLog']);
// The session files belong to the test, not to the system or the live site.
ini_set('session.save_path', $spec['sessions']);
// the log file of a live server is not writable here: keep the request's log in the temp folder
require $spec['site'] . '/core/Console.php';
\Zero\Core\Console::$defaultLogFile = $spec['consoleLog'];

$query = (string) parse_url($spec['uri'], PHP_URL_QUERY);
parse_str($query, $get);

$_SERVER['REQUEST_URI']    = $spec['uri'];
$_SERVER['REQUEST_METHOD'] = $spec['method'];
$_SERVER['HTTP_HOST']      = $spec['host'];
$_SERVER['SERVER_NAME']    = $spec['host'];
$_SERVER['REMOTE_ADDR']    = $spec['ip'];
$_SERVER['HTTP_ACCEPT']    = $spec['accept'];
$_SERVER['SCRIPT_NAME']    = '/index.php';
$_GET     = $get;
$_POST    = $spec['post'];
$_REQUEST = $get + $spec['post'];

if ($spec['sid'] !== null) {
    // Continue the session the way the browser's cookie would.
    session_id($spec['sid']);
}

register_shutdown_function(static function () use ($spec): void {
    $database = null;
    // The probe: ask the connection the request itself used which database it is on.
    if (class_exists(\Zero\Core\Database::class, false) && isset(\Zero\Core\Database::$connection)) {
        $database = \Zero\Core\Database::$connection->query('SELECT DATABASE()')->fetchColumn();
    }
    file_put_contents($spec['meta'], json_encode([
        'status'   => http_response_code() ?: 200,
        'headers'  => headers_list(),
        'sid'      => session_id(),
        'database' => $database,
    ]));
});

$root = $spec['site'] . '/';

if (isset($spec['script'])) {
    define('ZERO_ROOT', $root);
    define('ROOT_PATH', dirname(ZERO_ROOT) . '/');
    define('VIEW_PATH', ZERO_ROOT . 'app/frontend/frame/');
    define('MODULE_PATH', ZERO_ROOT . 'modules/');
    require ZERO_ROOT . 'core/Application.php';
    $app = new Zero\Core\Application();
    $app->registerAutoloaders();
    $app->parseRequest();
    $app->defineConstants();
    // The script is PHP the test itself wrote into the spec; nothing from a
    // request or from outside the test reaches this call.
    eval($spec['script']);
    exit;
}

include $root . 'app/frontend/www/index.php';
