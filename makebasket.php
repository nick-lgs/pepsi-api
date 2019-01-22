<?php

	$sid = $argv[1];
	$basket_id = $argv[2];
	$basket_file_name = $argv[3];
	
	session_save_path("/var/lib/php/session");

	session_id($sid);
	session_start();

	date_default_timezone_set('Europe/London');

	include("config.php");
	require_once("classes/portalDIConnector.php");
	$pdi = new PortalDI_Connector($wn_server);
	$pdi->setCredentials($_SESSION["auth"]);
	session_write_close();
	
	$pdi->downloadBasketRequest($basket_file_name, $basket_id);

?>