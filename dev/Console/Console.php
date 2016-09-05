<?php

	/**
	 * @depends on class Table
	 *
	 */
	class Console
	{
		public static $instance;
		public $output, $table, $display;

		public function __call($func, $arg)
		{
			switch($func) {
				case "loading" :
				case "suloaded" :
					if ($arg[1]) {
						$autoloading = array(
							"file" => $arg[0],
							"path" => $arg[1]
						);
						if ($func == "suloaded") {
							$autoloading["suloaded"] = $_SERVER['REMOTE_ADDR'];
						}
						$this -> output["autoloaded"][] = $autoloading;
					}
					break;
				default :
					$this -> output[$func][] = $args;
					return $this;
					break;
			}

		}

		public function getAutoloadList()
		{
			echo "successish";
			foreach ($this->output['autoloading'] as $autoloaded) {
				echo $autoloaded[0];
			}
		}

		public function display()
		{
			if (defined("DEV")) {
				self::$instance -> displayBar();
				//load("ConsoleBar.php");
			}
		}

		public function displayBar()
		{
			require __DIR__ . "/ConsoleBar.php";
		}

		public static function log($type = null)
		{
			if (!self::$instance) {
				self::$instance = new self;
			}
			return self::$instance;
		}

		public static function output()
		{
			print_x(self::$instance);
			// echo '<div id="connnsole">';

			// foreach (self::$instance -> output as $title => $output) {
			// echo "$title : ";
			// echo "<tr>";
			// foreach ($output as $key => $value) {
			// echo "<th>$key</th>";
			// }
			// echo "<tr>";
			// foreach ($output as $key => $value) {
			// echo "";
			// }
			// foreach ($output as $t => $v) {
			// echo "$t: $v";
			// }
			// }
			// echo '</pre></div>';
		}

		public static function table($magic)
		{
			if (!self::$instance) {
				self::$instance = new self;
				self::$instance -> output = new Table();
			}
			if ($magic) {
				echo self::$instance -> output -> auto($magic) -> display();
			} else {
				return self::$instance -> output;
			}
		}

		public static function setRequest($a, $e, $arg)
		{
			self::$instance -> aspect = $a;
			self::$instance -> endpoint = $e;
			self::$instance -> arguments = $e;
		}

		public static function saveAutoLoadLog()
		{
			$history = json_decode(file_get_contents(__DIR__ . "/loaded.json"));
			if (is_null($history)) {
				$history = new stdClass;
			}
			//var_dump($history);

			$luri = self::$instance -> aspect . "_" . self::$instance -> endpoint;
			$history -> {$luri} = self::$instance -> output['autoloaded'];

			var_dump(self::$instance);

			echo json_encode($history, JSON_PRETTY_PRINT);

			file_put_contents(__DIR__ . "/loaded.json", json_encode($history));

			echo $luri;
		}

		public function sdisplay()
		{
			print_x($_SERVER);
			print_x($_SESSION);
			print_x($_REQUEST);
		}

	}
