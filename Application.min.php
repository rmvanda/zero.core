<?php
class Application {
	public function __construct() {
		spl_autoload_register ( "self::loads()" );
		$this->run ();
	}
	public function run($aspect, $endpoint, $args) {
		if (in_array ( ($aspect = ucfirst ( $aspect )), get_declared_classes () )) {
			// new Err ( 403 );
			die ( "Err 403" );
		} else {
			if ($this->loads ( $aspect )) {
				$aspect = new $aspect ();
				$aspect->endpoint = $endpoint;
				$aspect->{$endpoint} ( $args );
			}
		}
	}
	public function loads($class) {
		
		// echo $class;
		if (strpos ( $class, "\\" )) {
			$namespace = explode ( "\\", $class );
			$class = array_pop ( $namespace );
		}
		$stdout = exec ( "find " . ROOT_PATH . ' -not -iwholename "*admin*" -type f -name ' . $class . ".php" );
		// Console::log() -> autoloading("Class $class in path: $stdout <br>");
		// echo " $stdout !<br>";
		return (file_exists ( $stdout ) ? require $stdout : false);
	}
}