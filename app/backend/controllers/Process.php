<?php

class Process extends Response {

	public function doAction() {
		//echo $this -> App -> request -> endpoint;
		$this -> {$this->App->request->endpoint}();

	}

	public function contactForm() {

	}

	public function signature() {
		Signature::saveSignature(filter_var($_POST['signeeId'], FILTER_VALIDATE_INT), filter_var($_POST['signee'], FILTER_SANITIZE_STRING), $_POST['signature']);
	}

}
