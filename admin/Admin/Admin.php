<?php

//namespace Zero;
//require "../srv/dev/Console.php";
/*
 use \Request as Request;
 use \Application as Application;
 use \Console as Console;
 */
class Admin extends Response//extends Application
{
	public function __construct() {
		new Restricted(Request);
		//echo __DIR__;
		//	$this->viewPath = ROOT_PATH."admin";
		parent::__construct();
		$this -> viewPath = ROOT_PATH . "admin/Admin/view/";

	}

	public function __call($func, $args) {
			
		if (file_exists($pg = ($this -> viewPath . $func . ".php"))) {

			$this -> render($pg);

		} else {
			//echo $pg;
			new $func($args);
			//
		}
		
	}

	public function buildHead() {
		echo "head";
	}

	public function buildHeader() {
		echo "header";
	}

	public function getPage($view) {
		echo "view from $view";
	}

	public function buildFooter() {
		echo "footer";
	}

	public function run() {

		if (Request::$isAjax) {
			$admin = new Request::$aspect;
			$admin -> {Request::$endpoint}();
		} else {
			try {
				AdminPanel::generate() -> header();
			} catch(exception $e) {
				print_x($e);
			}
		}

		$aspect = ucfirst(Request::$aspect);
		$app = new $aspect;
		$app -> {Request::$endpoint}();

		Console::output();
	}

}
