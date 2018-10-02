<?php

	session_start();
	date_default_timezone_set('Europe/London');
	if(!isset($_REQUEST["id"])) error("No id specified");
	if(!isset($_REQUEST["type"])) error("No type specified");
	$id = $_REQUEST["id"];
	$type = $_REQUEST["type"];
	
	$use_cache = true;

	if($use_cache && $type == "thumbnail" && isset($_SESSION["thumbCache"][$id])) {
		$img = $_SESSION["thumbCache"][$id];
		header('Content-Type: image/jpeg');
		print $img;
		die();
	}
	require("config.php");

	$curlObj = curl_init();
	curl_setopt($curlObj, CURLOPT_FAILONERROR, true);
	curl_setopt($curlObj, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curlObj, CURLOPT_ENCODING , 'gzip, deflate');
	curl_setopt($curlObj, CURLOPT_SSL_VERIFYPEER, 0);
	curl_setopt($curlObj, CURLOPT_USERAGENT, $_SERVER["HTTP_USER_AGENT"]);
	if(isset($_SESSION["auth"])) {
		curl_setopt($curlObj, CURLOPT_USERPWD, base64_decode($_SESSION["auth"]));
	} else {
		curl_setopt($curlObj, CURLOPT_USERPWD, $user.":".$pass);
	}

	if($type == "thumbnail") {
		$url = "http://{$wn_server}/webnative/portalDI?action=getimage&filetype=small&pixels=300&fileid=".$id;
		curl_setopt($curlObj, CURLOPT_URL,$url);
		curl_setopt($curlObj, CURLOPT_HTTPGET,true);
		curl_setopt($curlObj, CURLOPT_RETURNTRANSFER,true);
		curl_setopt($curlObj, CURLOPT_TIMEOUT, 60);
		$img = curl_exec($curlObj);
		if(strlen($img) > 0) {
			$_SESSION["thumbCache"][$id] = $img;
		}
		header('Content-Type: image/jpeg');
		print $img;
		die();
	} else if($type == "large") {
		//$img = $pdi->getImageStream($id,"large");
		//header('Content-Type: image/jpeg');
		//print $img;
		//die();
		$url = "http://{$wn_server}/webnative/portalDI?action=getimage&filetype=large&fileid=".$id;
		curl_setopt($curlObj, CURLOPT_URL,$url);
		curl_setopt($curlObj, CURLOPT_HTTPGET,true);
		curl_setopt($curlObj, CURLOPT_RETURNTRANSFER,true);
		curl_setopt($curlObj, CURLOPT_TIMEOUT, 60);
		$img = curl_exec($curlObj);
		//$_SESSION["thumbCache"][$id] = $img;
		header('Content-Type: image/jpeg');
		print $img;
		die();
	} else {
		error("Requested image type is invalid");
	}
?>
	
