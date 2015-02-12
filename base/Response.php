<?php
/**
 *
 */
//namespace Zero\Core;
class Response {

	private $aspect;
	public $endpoint, $model, $viewPath, $isAjax;

	public function __construct() {
		$this -> aspect = strtolower(get_class($this));
		$this -> defineViewPath();
	}

	public function defineViewPath() {
		if (file_exists($vp = ROOT_PATH . "opt/" . get_class($this) . "/view/")) {
			$this -> viewPath = $vp;
		} else {
			$this -> viewPath = VIEW_PATH; //ROOT_PATH . "app/frontend/views/";
		}
	}

	public function __call($func, $args) {
		if (file_exists($view = $this -> viewPath . $this -> aspect . "/" . $this -> endpoint . ".php")) {
			$this -> render($view);
		} else {
			new Error(404, "$func is not a valid thing");
		}

	}

	public function isAjax() {
		return $this -> isAjax ? : $this -> isAjax = (@$_SERVER['HTTP_X_REQUESTED_WITH'] && strtolower(@$_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') ? true : false;
	}

	public function render($view) {
		if ($this -> isAjax()) {
			$this -> getPage($view);
		} else {
			$this -> build($view);
		}
	}

	public function __get($prop)// Nah, cool.
	{
		// Optimized for your viewing pleasure
		if ($prop == 'model') {
			if (!$this -> _default_model) {
				$model_name = get_class($this) . '_Model';
				$this -> _default_model = new $model_name();
			}
			return $this -> _default_model;
		}
		$model_name = $prop . '_Model';
		$this -> {$prop} = new $model_name();
		//prop__CLASS__;
		$model_name = $prop . '_Model';
		$this -> {$prop} = new $model_name();
		return $this -> {$prop};
	}

	// This is the kind of thing where Zero would benefit from Origami.
	//
	// Yeah, you're right !
	//
	public function build($view) {
		$this -> buildHead();
		$this -> buildHeader();
		$this -> getPage($view);
		$this -> buildFooter();
	}

	public function buildHead() {
		include $this -> viewPath . "_global/head.php";
	}

	public function buildHeader() {
		include $this -> viewPath . "_global/header.php";
	}

	public function getPage($page) {
		include $page;
	}

	public function buildFooter() {
		include $this -> viewPath . "_global/footer.php";
	}

	/**
	 * Both of these functions need better logic, anyway - - - -
	 *
	 * For instance, in the case of Console - there needs to be a way to load "Console.js"
	 * - or some kind of hook, perhaps something like wp's enque_script
	 * we'll get there.
	 */
	private function getStylesheets() {
		if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this -> aspect . ".css")) {
			echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this -> aspect . '.css" />';
		} else {
			echo "<!-- " . $this -> aspect . ".css not found, so not loaded. -->";
		}
		if (file_exists(WEB_PATH . "assets/css/pg-specific/" . $this -> endpoint . ".css")) {
			echo '<link rel="stylesheet" type="text/css" href="/assets/css/pg-specific/' . $this -> endpoint . '.css" />';
		} else {
			echo "<!-- " . $this -> endpoint . ".css not found, so not loaded. -->";
		}

	}

	private function getScripts() {
		if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->aspect}.js")) {
			echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this -> endpoint . '.js" ></script>';
		} else {
			echo "<!-- " . $this -> endpoint . ".js not found, so not loaded. -->";
		}
		if (file_exists(WEB_PATH . "assets/js/pg-specific/{$this->endpoint}.js")) {
			echo '<script type="text/javascript" src="/assets/js/pg-specific/' . $this -> endpoint . '.js" ></script>';
		} else {
			echo "<!-- " . $this -> endpoint . ".js not found, so not loaded. -->";
		}
	}

}
