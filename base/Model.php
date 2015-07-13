<?php
	/**
	 * Model Class
	 * This class simply establishes a connection to the database, generically.
	 * Any other generic model functions can go here.
	 *
	 * @version 1.0
	 *
	 * */

	class Model
	{

		public function __construct($alt_config=null)
		{
			if (!class_exists("ZXC", false)) {
				$db_config = $alt_config ?: array(
					"HOST" => HOST,
					"NAME" => NAME,
					"USER" => USER,
					"PASS" => PASS
				);
				ZXC::INIT($db_config);
			}
		}

	}
