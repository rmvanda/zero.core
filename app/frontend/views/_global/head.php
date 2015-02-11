<!DOCTYPE html>
<head>
	<title></title>
	<meta charset="utf-8">
	<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1">
	<meta name="description" content="">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
	<!-- <script src="/assets/js/facebookInit.js"></script> -->
	<!-- <script src="https://platform.twitter.com/widgets.js"></script> -->
	<!-- <script type="text/javascript" src="http://platform.tumblr.com/v1/share.js"></script> -->
	<!-- <link rel="stylesheet" href="/assets/css/global.css" /> -->
	<?php // $this -> getStylesheets(); ?>
	<meta property="og:site_name" content="" /> 
	<?php //if ($GLOBALS['offer_bid']) { //FIXME: 
	/*	if ($_GET['oid']) {
		$oid = filter_var($_GET['oid'], FILTER_VALIDATE_INT);
		$info = ZXC::sel("1bid,1alt,1shortdesc,1desc,2name/offers<bid>biznuses") -> where("oid", "56") -> one();
		$desc = explode(",", $info['desc']);
		$title = str_replace("-",",",$desc[0]);
		//$desc = implode(" ", $desc);
		$ogDesc = str_replace("-",",", trim($desc[1],'.'))." Brought to you by {$info['name']}" ;
		
		//$desc = $desc[3]." ".$desc[4];
		$pic = file_exists(".jpg") ? "" : "";
	*/ 
	  
	 ?>
		<meta property="og:type" content="website" />
		<meta property="og:url" content="http://<?=$_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF'] . '?' . $_SERVER['QUERY_STRING'] ?>" />
		<meta property="og:image" content="<?=$pic ?>" />
		<meta property="og:title" content="<?=$title ?>" />
		<meta property="og:description" content="<?=$ogDesc ?>" />
	<?php
	if(false){
	//	} else { ?>
		<meta property="og:url" content="" />
		<meta property="og:image" content="" />
		<meta property="og:title" content="" />
	<?php } ?>
	<noscript><div class="messageBox error"><p>	We're sorry, but <!--TODO -->  requires JavaScript and Cookies in order to function properly</p><p>Please ensure that JavaScript is enabled in order to have a good experience with LoAff</p></div></noscript>
	<link rel="stylesheet" href="/assets/css/reset.css" />
	<link rel="stylesheet" href="/assets/css/global.css" /> 
</head>