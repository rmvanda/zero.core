<?php
/**
 * Application class
 *
 * @version 3.0.1
 */
class Application {

	private $aspect;
	private $endpoint;

	private $subdomain = array();

	public function __construct() {
		//@f:off
		$this -> defineConstants() -> fetchUtilities() -> registerAutoloaders() -> parseRequest() -> finalizeRoute() -> run(Request::$aspect, Request::$endpoint, Request::$args);
		Console::log() -> maxMemory(xdebug_peak_memory_usage()) -> totalExecutionTime(xdebug_time_index()) -> display();
		//@f:on
	}

	public function modprobe(array $modprobe) {
		foreach ($modprobe as $module) {
			define(strtoupper($module), true);
		}
		return $this;
	}

	public function parseRequest() {
		new Request();
		return $this;
	}

	public function run($aspect, $endpoint, $args) {
		if (in_array(($aspect = ucfirst($aspect)), get_declared_classes()) && !defined("DEV")) {
			new Error(403);
		} else {
			if (loads($aspect)) {
				$aspect = new $aspect();
			} else {
				$aspect = new Response();
			}
			$aspect -> endpoint = $endpoint;
			$aspect -> $endpoint($args);
		}
	}

	private function friendlyURLConverter($url) {
		return lcfirst(str_replace(" ", "", ucwords(str_replace("-", ' ', $url))));
	}

	public function load($filename, $path = null) {
		$stdout = exec("find " . ROOT_PATH . "base/ -type f -name " . $filename . ".php");
		Console::log() -> autoloader("<pre><p>stdout:<p></pre><pre> For $filename: $stdout<pre>");
		return (file_exists($stdout) ?
		require $stdout : false);
	}

	public function suload($filename) {
		$stdout = exec("find " . ROOT_PATH . " -type f -name " . $filename . ".php");
		return (file_exists($stdout) ?
		require $stdout : false);
	}

	public function registerAutoloaders($autoloader = null) {
		// for Composer + PSR compatability
		if (file_exists($file = ROOT_PATH . "vendor/autoload.php")) {
			require $file;
		}
		// if you want to add external autoloaders
		if ($autoloader) {
			if (is_callable($autoloader)) {
				spl_autoload_register($autoloader);
			} elseif (is_array($autoloader)) {
				foreach ($autoloader as $al) {
					spl_autoload_register($al);
				}
			}
		}
		/**
		 * Maximally Underwritten Fast As Shit Autoloader Array
		 * MUFASA - !
		 * @version 0.8.2
		 */

		spl_autoload_register(function($class) {

			//  echo $class;
			if (strpos($class, "\\")) {
				$namespace = explode("\\", $class);
				$class = array_pop($namespace);
			}
			$stdout = exec("find " . ROOT_PATH . " -path admin -prune -o -type f -name " . $class . ".php");
			Console::log() -> autoloading("Class $class in path: $stdout <br>");
			//echo " $stdout !<br>";
			return (file_exists($stdout) ?
			require $stdout : false);
		});

		spl_autoload_register("self::errorHandler");
		return $this;
	}

	public function fetchUtilities($utilities = null) {
		require ROOT_PATH . "admin/dev/Console/Console.php";
		$this -> load("Extensions");
		$this -> load("Error");

		if ($utilities) {
			if (file_exists($utilities)) {
				require $utilities;
			} elseif (is_array($utilities)) {
				foreach ($utilities as $utility) {
					require $utility;
				}
			}
		}
		return $this;
	}

	public function defineConstants(array $key = null) {
		if (($_SERVER['REMOTE_ADDR'] == "192.168.1.77") || $_SERVER['REMOTE_ADDR'] == "50.199.113.222") {
			define("DEV", true);
		}
		if (!defined("ROOT_PATH")) {
			define("URL", "http://" . $_SERVER['HTTP_HOST'] . "/");
			define("ROOT_PATH", "/" . trim($_SERVER['DOCUMENT_ROOT'], "app/frontend/www") . "/");
			//^fuck FIXME
		}
		define("VIEW_PATH", ROOT_PATH . "app/frontend/views/");
		if ($key) {
			foreach ($key as $k => $v) {
				define($k, $v);
			}
		}
		return $this;
	}

	// TODO
	// ACL HOOK?
	public function finalizeRoute() {
		return $this;
	}

	public function errorHandler($class) {
		if (defined("DEV")) {
			xdebug_print_function_stack();
			Console::log() -> error($class);
			die("<h1 style='color:red'>Can't load $class</h1>");

		}
		new Error(404, $class);
	}

}
