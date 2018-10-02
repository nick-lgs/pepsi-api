<?php

	session_start();
	
	date_default_timezone_set('Europe/London');
	
	require("config.php");
	
	function error($msg, $code=1) {
		$ret_array = array(
			"ERROR" => $code,
			"ERROR_MSG" => $msg
		);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		die();
	}
	
	function success($msg, $code=200) {
		$ret_array = array(
			"SUCCESS" => $code,
			"SUCCESS_MSG" => $msg
		);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		exit();
	}

	function StartsWith($Haystack, $Needle){
    	return strpos($Haystack, $Needle) === 0;
	}

	function human_filesize($bytes, $decimals = 2) {
		$size = array('B','kB','MB','GB','TB','PB','EB','ZB','YB');
		$factor = floor((strlen($bytes) - 1) / 3);
		return sprintf("%.{$decimals}f", $bytes / pow(1024, $factor)) . @$size[$factor];
	}

	if(!isset($_REQUEST["method"])) {
		error("No method found", 400);
	}

	function reverseBitmask($bitmask) {
		$bin = decbin($bitmask);
		$total = strlen($bin);
		$stock = array();
		for ($i = 0; $i < $total; $i++) {
			if ($bin{$i} != 0) {
				$bin_2 = str_pad($bin{$i}, $total - $i, 0);
				array_push($stock, bindec($bin_2));
			}
		}
		return $stock;
	}
	
	require_once("classes/portalDIConnector.php");
	$pdi = new PortalDI_Connector($wn_server);

	$method = $_REQUEST["method"];
	
	if($method == "auth") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "login") {
			if(!isset($_REQUEST["user"])) error("No user specified", 400);
			if(!isset($_REQUEST["password"])) error("No password specified", 400);
			$user = $_REQUEST["user"];
			$password = $_REQUEST["password"];
			$pdi->setCredentials(base64_encode($user.":".$password));
			$user_info = $pdi->checkAuth();
			if(!isset($user_info["VOLUME_INFO"])) {
				$_SESSION["authorised"] = 0;
				$_SESSION["auth"] = "";
				session_destroy();
				$ret_array = array(
					"logged_in" => false
				);
			} else {
				$_SESSION["authorised"] = 1;
				$_SESSION["user"] = $user;
				$_SESSION["auth"] = base64_encode($user.":".$password);
				$_SESSION["last_action_time"] = time();
				$ret_array = array(
					"logged_in" => true
				);
			}
		} else if($action == "prelogin") {
			if(!isset($_REQUEST["user"])) error("No user specified", 400);
			if(!isset($_REQUEST["password"])) error("No password specified", 400);
			$user = $_REQUEST["user"];
			$password = $_REQUEST["password"];
			$pdi->setCredentials(base64_encode($user.":".$password));
			$user_info = $pdi->checkAuth();
			$valid_auth = false;
			$user_locked = false;
			$must_agree_terms = false;
			$can_login = true;
			$terms_html = "";
			$reason = "";

			if(isset($user_info["VOLUME_INFO"])) {
				$valid_auth = true;
			}

			//used for inavlid login and t&cs modules
			$connection = mysql_connect($wn_server, "lgs", "lgsrlz") or error(mysql_error(), 409);
			mysql_select_db("lgs_invalidlogin") or error('Unable to select database!', 409);
			$username = $user;
			
			if(!$valid_auth) {
			
				$can_login = false;

				//INVALID LOGIN MODULE				
				$query = "SELECT count FROM usercount WHERE username = '$username'";
				$result = mysql_query($query) or error(mysql_error(), 409);
				$failedCount = 0;
				$userExists = false;
				$oldPrivs = "";
				if (mysql_num_rows($result) > 0) {
					$userExists = true;
					while ($row = mysql_fetch_array($result)) {
						$failedCount = $row["count"];
					}
				}
				
				if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
					$user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
				} else {
					$user_ip = $_SERVER['REMOTE_ADDR'];
				}
				if(!$userExists) {
					$query = "INSERT INTO usercount (username, count, last_date, last_ip) VALUES ('$username', 1, NOW(), '$user_ip')";
				} else {
					$failedCount++;
					$query = "UPDATE usercount SET count = $failedCount, last_date = NOW(), last_ip = '$user_ip' WHERE username = '$username'";
				}
				$result = mysql_query($query) or error(mysql_error(), 409);

				mysql_select_db("webnative") or error('Unable to select database!', 409);
				$wnUser = false;
				$query = "SELECT Privileges FROM user WHERE username = '$username'";
				$result = mysql_query($query) or error(mysql_error(), 409);
				$oldPrivs = "";
				if (mysql_num_rows($result) > 0) {
					$wnUser = true;
					while ($row = mysql_fetch_array($result)) {
						$oldPrivs = $row["Privileges"];
					}
				}
				if($wnUser) {
					$oldPrivsArray = reverseBitmask($oldPrivs);
					$isLocked = false;
					foreach($oldPrivsArray as $op) {
						if($op == 4) {
							$isLocked = true;
						}
					}
				}
				
				if($failedCount >= $invalid_login_max_attempts) {
					if($wnUser) {
						$user_type = "Xinet";
						$oldPrivsArray = reverseBitmask($oldPrivs);
						$isLocked = false;
						foreach($oldPrivsArray as $op) {
							if($op == 4) {
								$isLocked = true;
							}
						}
			
						if($isLocked) {
							//do nothing
						} else {
							$newPrivs = $oldPrivs + 4;
							$query = "UPDATE user SET Privileges = $newPrivs WHERE username = '$username'";
							$result = mysql_query($query) or error(mysql_error(), 409);
						}
					} else {
						$user_type = "non Xinet";
					}
					
					if($failedCount == $invalid_login_max_attempts) {
						$email_template = "/usr/etc/LGS/modules/InvalidLogin/email/default.html";
						if(isset($invalid_login_email_template)) {
							if(is_readable("/usr/etc/LGS/modules/InvalidLogin/email/".$invalid_login_email_template)) {
								if(is_file("/usr/etc/LGS/modules/InvalidLogin/email/".$invalid_login_email_template)) {
									$email_template = "/usr/etc/LGS/modules/InvalidLogin/email/".$invalid_login_email_template;
								}
							}
						}
						$email_contents = file_get_contents($email_template);
						$email_contents = strtr($email_contents, array('${username}' => $username, '${user_type}' => $user_type, '${lock_date}' => date("Y-m-d H:i"), '${user_ip}' => $user_ip, '${attempts}' => $failedCount));

						$headers = "From: ".$invalid_login_email_from."\r\n";
						$headers .= 'X-Mailer: PHP/' . phpversion();
						$headers .= "MIME-Version: 1.0\r\n";
						$headers .= "Content-Type: text/html; charset=iso-8859-1\r\n";
						mail($invalid_login_email_to, $invalid_login_email_subject, $email_contents, $headers);
					}
				}
				if($wnUser) {
					if($isLocked) {
						$can_login = false;
						$user_locked = true;
						$reason = "User account locked";
					} else {
						$can_login = false;
						$reason = "Incorrect username or password";
					}
				} else {
					$can_login = false;
					$reason = "Incorrect username or password";
				}
			} else {
				$query = "UPDATE usercount SET count = 0 WHERE username = '$username'";
				$result = mysql_query($query) or error(mysql_error(), 409);			
			}
			if($can_login) {
				//check for terms
				$has_agreed_terms = false;
				mysql_select_db("lgs_agreeterms") or error('Unable to select database!', 409);

				if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
					$user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
				} else {
					$user_ip = $_SERVER['REMOTE_ADDR'];
				}

				$query = "SELECT agree_date FROM agreelog WHERE username = '$username' AND agreed=1 AND user_ip='$user_ip' AND (agree_date BETWEEN timestamp(DATE_SUB(NOW(), INTERVAL 1 MINUTE)) AND timestamp(NOW()))";
				$result = mysql_query($query) or error(mysql_error(), 409);
				if (mysql_num_rows($result) > 0) {
					$has_agreed_terms = true;
				}
				if(!$has_agreed_terms) {
					$must_agree_terms = true;
					$can_login = false;
					$reason = "Must agree terms";

					$userinfo = $pdi->getUserSettings();
	
					if(!isset($userinfo["LANGUAGE"])) {
						$ret_arr = array(
							"validlogin" => false,
						);
						print json_encode($ret_arr);
						die();
					}
	
					$lang = $userinfo["LANGUAGE"];
	
					if(is_file("/usr/etc/LGS/modules/AgreeTerms/terms/$lang.html")) {
						$terms_file = "/usr/etc/LGS/modules/AgreeTerms/terms/$lang.html";
					} else {
						$terms_file = "/usr/etc/LGS/modules/AgreeTerms/terms/default.html";
					}
	
					$terms_html = base64_encode(file_get_contents($terms_file));
				}
			}
			$ret_array = array(
				"can_login" => $can_login,
				"valid_auth" => $valid_auth,
				"user_locked" => $user_locked,
				"must_agree_terms" => $must_agree_terms,
				"reason" => $reason,
				"terms_html" => $terms_html
			);
		} else if($action == "logout") {
			$_SESSION["authorised"] = 0;
			$_SESSION["auth"] = "";
			session_destroy();
			$ret_array = array(
				"logged_in" => false
			);
		} else if($action == "check") {
			if(isset($_SESSION["authorised"]) && $_SESSION["authorised"] == 1) {
				$_SESSION["last_action_time"] = time();
				$ret_array = array(
					"logged_in" => true
				);
			} else {
				$ret_array = array(
					"logged_in" => false
				);
			}
		} else {
			error("Invalid action", 400);
		}
		success($ret_array);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		die();
	} else if($method == "terms") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		if(!isset($_REQUEST["user"])) error("No user specified", 400);
		$action = $_REQUEST["action"];
		$user = $_REQUEST["user"];
		if($action == "agree") {
			$agreed = "1";
		} else {
			$agreed = "0";
		}
		if(isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
			$user_ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
		} else {
			$user_ip = $_SERVER['REMOTE_ADDR'];
		}

		$portal_site = $lgs_modules_site_name;

		$connection = mysql_connect($wn_server, "lgs", "lgsrlz") or error(mysql_error(), 409);
		mysql_select_db("lgs_agreeterms") or error('Unable to select database!', 409);

		$query = "INSERT INTO agreelog (username, agree_date, user_ip, agreed, portal_site) VALUES ('$user', NOW(), '$user_ip', '$agreed', '$portal_site')";
		$result = mysql_query($query) or error(mysql_error(), 409);
		success("Terms ".$action."d");
	} else if($method == "inalias") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "form") {
			//$inalias_form = $pdi->getInAliasForm($inalias_teamname);
			$form = array();
			$fields = array();
			$fields[] = array("name" => "email", "type" => "text", "display" => "Email address", "required" => true);
			$fields[] = array("name" => "password1", "type" => "password", "display" => "Password", "required" => true);
			$fields[] = array("name" => "password2", "type" => "password", "display" => "Repeat password", "required" => true);
			$fields[] = array("name" => "usertype", "type" => "select", "display" => "Account type", "values" => array(array("value" => "usertype0", "display" => "Pepsi Admin"),array("value" => "usertype1", "display" => "Pepsi Marketing"),array("value" => "usertype2", "display" => "Pepsi Agency"),array("value" => "usertype2", "display" => "Pepsi Production")), "required" => true);
//			$fields[] = array("name" => "metadata0", "type" => "text", "display" => "Forename", "required" => true);
//			$fields[] = array("name" => "metadata1", "type" => "text", "display" => "Surname", "required" => true);
			$form["fields"] = $fields;
			$rules = array();
			$rules[] = array("name" => "min_char", "value" => 8, "active" => true);
			$rules[] = array("name" => "min_num", "value" => 0, "active" => false);
			$rules[] = array("name" => "min_lower", "value" => 0, "active" => true);
			$rules[] = array("name" => "min_upper", "value" => 0, "active" => true);
			$rules[] = array("name" => "min_special", "value" => 0, "active" => true);
			$rules[] = array("name" => "max_repeat", "value" => 0, "active" => true);
			$form["rules"] = $rules;
			success($form);
//			print $inalias_form;
		} else if($action == "register") {
//			if(!isset($_REQUEST["userid"])) error("No userid specified", 400);
//			if(!isset($_REQUEST["name"])) error("No name specified", 400);
			if(!isset($_REQUEST["email"])) error("No email specified", 400);
			if(!isset($_REQUEST["password1"])) error("No password specified", 400);
			if(!isset($_REQUEST["password2"])) error("No password specified", 400);
//			$userid = $_REQUEST["userid"];
//			$name = $_REQUEST["name"];
//			$email = $_REQUEST["email"];
//			$pass = $_REQUEST["password"];
//			$inalias_reg = $pdi->registerInAliasUser($inalias_teamname, $email, $pass);
			if($inalias_reg = json_decode($pdi->registerInAliasUser($inalias_teamname, $_REQUEST), true)) {
//				print_r($inalias_reg);
				if(isset($inalias_reg["Status"])) {
					if($inalias_reg["Status"] == "ERROR") {
						error($inalias_reg["Message"], 429);
					}
				}
			}
//			print_r($inalias_reg);
			success("Registration request being processed");
//			exit();
//			$inalias_xml = simplexml_load_string($inalias_reg);
//			print_r($inalias_xml);
//			exit();
		} else {
			error("action invalid", 400);
		}
	} else if($method == "sitesettings") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "cookie") {
			$cookie_popup = file_get_contents("cookies.html");
			$cookie_info = file_get_contents("cookies_info.html");
			$ret_array = array(
				"cookie_popup" => base64_encode($cookie_popup),
				"cookie_info" => base64_encode($cookie_info)
			);
		} else if($action == "invalid_chars") {
			$invalid_chars = $restricted_characters;
			$ret_array = array(
				"invalid_chars" => $invalid_chars
			);
		} else if($action == "invalid_files") {
			$invalid_files = $invalid_files;
			$ret_array = array(
				"invalid_files" => $invalid_files
			);
		} else if($action == "max_upload") {
			$max_upload = $upload_max_size_bytes;
			$ret_array = array(
				"max_upload" => $max_upload
			);
		} else if($action == "marketing_asset_keyword_id") {
			$marketing_asset_keyword_id = $marketing_asset_keyword_id;
			$ret_array = array(
				"marketing_asset_keyword_id" => $marketing_asset_keyword_id
			);
		} else if($action == "style") {
			$ret_array = array(
				"colours" => array(
					"primary" => $site_primary_colour
				)
			);
		} else if($action == "all") {
			$cookie_popup = file_get_contents("cookies.html");
			$cookie_info = file_get_contents("cookies_info.html");
			$invalid_chars = $restricted_characters;
			$invalid_files = $invalid_files;
			$max_upload = $upload_max_size_bytes;
			$marketing_asset_keyword_id = $marketing_asset_keyword_id;
			$style = array(
				"colours" => array(
					"primary" => $site_primary_colour
				)
			);
			$ret_array = array(
				"cookie_popup" => base64_encode($cookie_popup),
				"cookie_info" => base64_encode($cookie_info),
				"invalid_chars" => $invalid_chars,
				"invalid_files" => $invalid_files,
				"max_upload" => $max_upload,
				"marketing_asset_keyword_id" => $marketing_asset_keyword_id,
				"style" => $style
			);
		}
		success($ret_array);
//		$ret_json = json_encode($ret_array);
//		print $ret_json;
//		exit();
	} else if($method == "passwordreset") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "form") {
			$form = array();
			$fields = array();
			$fields[] = array("name" => "resetuser", "type" => "text", "display" => "Email address", "required" => true);
			$fields[] = array("name" => "pwraction", "type" => "hidden", "value" => "user", "required" => true);
			$form["fields"] = $fields;
			success($form);
		} else if($action == "post") {
			if(!isset($_REQUEST["pwraction"])) error("No password reset action specified", 400);
			$pwraction = $_REQUEST["pwraction"];
			$form_data = $_REQUEST;
			foreach(array_keys($form_data) as $f) {
				if($f == "action") unset($form_data[$f]);
				if($f == "method") unset($form_data[$f]);
			}
			if($pwraction == "user") {
				if($change_pass = json_decode($pdi->changePassword($inalias_teamname, $_REQUEST), true)) {
					if(isset($change_pass["Status"])) {
						if($change_pass["Status"] == "ERROR") {
							error($change_pass["Message"], 429);
						} else if($change_pass["Status"] == "OK") {
							$form = array();
							$fields = array();
							$fields[] = array("name" => "validationcode", "type" => "text", "display" => "Validation code", "required" => true);
							$fields[] = array("name" => "pwraction", "type" => "hidden", "value" => "validate", "required" => true);
							$form["fields"] = $fields;
							success($form);
						}
					}
				}
			} else if($pwraction == "validate") {
				if($change_pass = json_decode($pdi->changePasswordValidate($inalias_teamname, $_REQUEST), true)) {
					if(isset($change_pass["Status"])) {
						if($change_pass["Status"] == "ERROR") {
							error($change_pass["Message"], 429);
						} else if($change_pass["Status"] == "OK") {
							$form = array();
							$fields = array();
							$fields[] = array("name" => "password1", "type" => "password", "display" => "Password", "required" => true);
							$fields[] = array("name" => "password2", "type" => "password", "display" => "Repeat Password", "required" => true);
							$fields[] = array("name" => "pwraction", "type" => "hidden", "value" => "password", "required" => true);
							$fields[] = array("name" => "id", "type" => "hidden", "value" => $_REQUEST["validationcode"], "required" => true);
							$form["fields"] = $fields;
							success($form);
						}
					}
				}
			} else if($pwraction == "password") {
				$change_response = $pdi->changePasswordChange($inalias_teamname, $_REQUEST);
				if($change_pass = json_decode($change_response, true)) {
					if(isset($change_pass["Status"])) {
						if($change_pass["Status"] == "ERROR") {
							error($change_pass["Message"], 429);
						} else if($change_pass["Status"] == "OK") {
							success("Password changed successfully", 200);
						}
					}
				}
			} else {
				error("Invalid password reset action", 400);
			}
			error("Invalid request", 400);
		}
	}

	if(!isset($_SESSION["authorised"]) || $_SESSION["authorised"] != 1) {
		error("You must login to proceed", 403);
	}
	if($timeout != 0 && $_SESSION["last_action_time"] < time()-$timeout) {
		$_SESSION["authorised"] = 0;
		$_SESSION["auth"] = "";
		session_destroy();
		error("Session has timed out", 403);
	}
	$_SESSION["last_action_time"] = time();

	$pdi->setCredentials($_SESSION["auth"]);

	$metadata_override = true;
		
	function process_basket_info($basket_info) {
		$files = array();
		foreach($basket_info as $f) {
			if(strlen($f["FILE_NAME"]) > 20) {
				$fname = substr($f["FILE_NAME"], 0, 18)."...";
			} else {
				$fname = $f["FILE_NAME"];
			}
			$files[] = array(
				"name" => $fname,
				"long_name" => $f["FILE_NAME"],
				"id" => $f["FILE_ID"],
				"is_image" => $f["FILE_ISIMAGE"]
			);
		}
		$info = array(
			"file_count" => sizeof($files)
		);
		$ret_array = array(
			"files" => $files,
			"info" => $info
		);
		success($ret_array);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		die();
	}
		

	function process_files_info($files_info, $facet_info=false, $info_info=array()) {

		global $metadata_info;
		global $metadata_ids;
		global $pdi;

		$files = array();
		$folders = array();
		$filters = array();
		$info = $info_info;
		
		foreach($files_info as $f) {
			if($f["FILE_ISADIR"] == 0) {
				$metadata = array();
				$file_info = array(
					"dates" => array(
						"created" => array(
							"display" => date("d-m-Y H:i:s", $f["FILE_CDATE"]),
							"sort" => $f["FILE_CDATE"]
						),
						"modified" => array(
							"display" => date("d-m-Y H:i:s", $f["FILE_MDATE"]),
							"sort" => $f["FILE_MDATE"]
						),
						"accessed" => array(
							"display" => date("d-m-Y H:i:s", $f["FILE_ADATE"]),
							"sort" => $f["FILE_ADATE"]
						),
					),
					"filesize" => array(
						"display" => human_filesize($f["FILE_LENGTH"]),
						"sort" => $f["FILE_LENGTH"]
					)
				);
				foreach(array_keys($f["KEYWORD_INFO"]) as $kwik) {
					$filter_name = $metadata_info[$kwik]["name"];
					$filter_id = $kwik;
					$filter_value = $f["KEYWORD_INFO"][$kwik]["KW_VALUE"];
					$metadata[] = array(
						"id" => $filter_id,
						"value" => $filter_value
					);
					if($metadata_info[$kwik]["filter"] && $filter_value != "") {
						if(isset($filters[$filter_id][$filter_value])) {
							$filters[$filter_id][$filter_value]++;
						} else {
							$filters[$filter_id][$filter_value] = 1;
						}
					}
				}
				if(strlen($f["FILE_NAME"]) > 20) {
					$fname = substr($f["FILE_NAME"], 0, 18)."...";
				} else {
					$fname = $f["FILE_NAME"];
				}
				$files[] = array(
					"name" => $fname,
					"long_name" => $f["FILE_NAME"],
					"id" => $f["FILE_ID"],
					"is_image" => $f["FILE_ISIMAGE"],
					"metadata" => $metadata,
					"info" => $file_info
				);
			} else {
				if(isset($f["foldercount"]) && $f["foldercount"] != 0) {
					$hasSub = true;
				} else {
					$hasSub = false;
				}
				$folders[] = array(
					"name" => $f["FILE_NAME"],
					"id" => $f["FILE_ID"],
					"hasSub" => $hasSub
				);
			}
		}
		
		if(!$facet_info) {
			$filter_list = array();
			foreach(array_keys($filters) as $fnk) {
				$value_list = array();
				foreach(array_keys($filters[$fnk]) as $fvk) {
					$value_list[] = array(
						"value" => (string)$fvk,
						"count" => $filters[$fnk][$fvk]
					);
				}
				$filter_list[] = array(
					"id" => $fnk,
					"values" => $value_list
				);
			}
		} else {
			$filter_list = $facet_info;
		}
		
		$info["file_count"] = sizeof($files);
		$info["folder_count"] = sizeof($folders);

		$ret_array = array(
			"files" => $files,
			"folders" => $folders,
			"filters" => $filter_list,
			"info" => $info
		);
		success($ret_array);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		die();
	}
	
	if(!isset($_SESSION["metadata_info"]) || $metadata_override) {
		$metadata_info = array();
		$metadata_ids = array();
		$keywords_info = $pdi->getKeywords();
//		print_r($keywords_info);
//		die();
		foreach($keywords_info as $kw) {
			if(trim($kw["KW_DESC"]) != "") {
				$kw_name = trim($kw["KW_DESC"]);
			} else {
				$kw_name = trim($kw["KW_NAME"]);
			}
			$metadata_ids[$kw_name] = $kw["KW_ID"];
			$kw_type = "text";
			if($kw["KW_TYPE"] == 253) {
				if($kw["KW_LIMITED"] == 1) {
					$kw_type = "select";
				} else {
					$kw_type = "text";
				}
			}
			if($kw["KW_TYPE"] == 100) {
				$kw_type = "boolean";
			}
			$kw_values = array();
			if(isset($kw["KW_VALUES"])) {
				foreach($kw["KW_VALUES"] as $kwv) {
					$kw_values[] = $kwv["KW_VALUE"];
				}
			}
			if($kw["KW_FACET"] == -1) {
				$filter = true;
			} else {
				$filter = false;
			}
			if($kw["KW_UPLOAD"] == 1) {
				$upload = true;
			} else {
				$upload = false;
			}
			if($kw["KW_UPLOADREQUIRED"] == 1) {
				$upload_required = true;
			} else {
				$upload_required = false;
			}
			$metadata_info[$kw["KW_ID"]] = array(
				"id" => $kw["KW_ID"],
				"name" => $kw_name,
				"type" => $kw_type,
				"values" => $kw_values,
				"filter" => $filter,
				"upload" => $upload,
				"upload_required" => $upload_required
			);
		}
		$_SESSION["metadata_info"] = $metadata_info;
		$_SESSION["metadata_ids"] = $metadata_ids;
	} else {
		$metadata_info = $_SESSION["metadata_info"];
		$metadata_ids = $_SESSION["metadata_ids"];
	}

	if($method == "home") {
		$volumes_info = $pdi->getVolumes();
		$user_info = $pdi->getUserInfo();
		$user_groups = array();
		foreach($user_info["GROUPS"] as $g) {
			$user_groups[] = $g["NAME"];
		}
		$folders = array();
		foreach($volumes_info as $vi) {
			$folders[] = array(
				"name" => $vi["FILE_NAME"],
				"id" => $vi["FILE_ID"]
			);
		}
		$info = array(
			"folder_count" => sizeof($folders)
		);
		
		$quicklinks = array();
		if(is_readable("quicklinks.xml")) {
			$quicklinks_xml = simplexml_load_file("quicklinks.xml");
			foreach($quicklinks_xml->search as $search) {
				$links = array();
				foreach($search->links->link as $link) {
					$links[] = $link;
				}
				$quicklinks[] = array(
					"title" => (string)$search->title,
					"metadata_id" => (string)$search->metadata_id,
					"links" => $links
				);
			}
		} else {
			$quicklinks = false;
		}

		$browselinks = array();
		if(is_readable("browselinks.xml")) {
			$browselinks_xml = simplexml_load_file("browselinks.xml");
			foreach($browselinks_xml->link as $link) {
				$add_link = false;
				foreach($link->groups->group as $group) {
					if(in_array($group, $user_groups)) {
						$add_link = true;
					}
				}
				if($add_link) {
					$filters = array();
					foreach($link->filters->filter as $filter) {
						$filters[] = array(
							"solr_name" => (string)$filter->solr_name,
							"metadata_id" => (int)$filter->metadata_id,
							"search_term" => (string)$filter->search_term
						);
					}
					$path_id = "";
					if((string)$link->path_id == "") {
						$tmp_vol_name = (string)$link->volume_name;
						foreach($volumes_info as $vi) {
							if($vi["FILE_NAME"] == $tmp_vol_name) {
								$path_id = $vi["FILE_ID"];
							}
						}
					} else {
						$path_id = (string)$link->path_id;
					}
					if($path_id != "") {
						$tmp_finfo = $pdi->getFileInfoId($path_id);
						$f_name = "";
						foreach($volumes_info as $vi) {
							if($vi["FILE_ID"] == $path_id) {
								$f_name = $vi["FILE_NAME"];
							}
						}
						if($f_name == "") {
							$f_name = $tmp_finfo["FILES_INFO"][0]["FILE_NAME"];
						}
						$browselinks[] = array(
							"title" => (string)$link->title,
							"folder_name" => $f_name,
							"path_id" => $path_id,
							"filters" => $filters
						);
					}
				}
			}
		} else {
			$browselinks = false;
		}
		$ret_array = array(
			"folders" => $folders,
			"info" => $info,
			"quicklinks" => $quicklinks,
			"browselinks" => $browselinks
		);
		success($ret_array);
		$ret_json = json_encode($ret_array);
		print $ret_json;
		die();
	} else if($method == "browse") {
		if(!isset($_REQUEST["id"])) error("No id specified", 400);
		$sort_criteria = $_REQUEST["sortcriteria"] ? $_REQUEST["sortcriteria"] : "filename";
		if($sort_criteria == "filename") $sort_criteria = "utf8name_sort";
		$sort_order = $_REQUEST["sortorder"] ? $_REQUEST["sortorder"] : "asc";
		if(isset($_REQUEST["id"])) {
			if(isset($_REQUEST["filters"])) {
				$page = $_REQUEST["page"] ? $_REQUEST["page"] : 1;
				$json_str = $_REQUEST["filters"];
				$filters = json_decode($json_str, true);
				foreach($filters as $f) {
					if(!isset($f["name"])) error("Filters must contain a name key", 400);
					if(!isset($f["values"])) error("Filters must contain a values key", 400);
				}
				$browse_info = $pdi->getBrowseFilter($_REQUEST["id"], false, $items_per_page, $page, $filters, true, $sort_criteria, $sort_order);
				$files_info = $browse_info["files"];
				$facet_info = $browse_info["facets"];
				$info_info = $browse_info["info"];
			} else {
				$page = $_REQUEST["page"] ? $_REQUEST["page"] : 1;
				$browse_info = $pdi->getBrowse($_REQUEST["id"], false, $items_per_page, $page, true, $sort_criteria, $sort_order);
				$files_info = $browse_info["files"];
				$facet_info = $browse_info["facets"];
				$info_info = $browse_info["info"];
			}
		}
		process_files_info($files_info, $facet_info, $info_info);
	} else if($method == "fileinfo") {
		if(!isset($_REQUEST["id"])) error("No id specified", 400);
		$tmp_finfo = $pdi->getFileInfoId($_REQUEST["id"]);
		print_r($tmp_finfo);
		die();
	} else if($method == "search") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		$sort_criteria = $_REQUEST["sortcriteria"] ? $_REQUEST["sortcriteria"] : false;
		if($sort_criteria == "filename") $sort_criteria = "utf8name_sort";
		if($sort_criteria == "relevance") $sort_criteria = false;
		$sort_order = $_REQUEST["sortorder"] ? $_REQUEST["sortorder"] : "asc";
		
		if($action == "new") {
			if(!isset($_REQUEST["target"])) error("No target specified", 400);
			$target = $_REQUEST["target"];
			$page = $_REQUEST["page"] ? $_REQUEST["page"] : 1;
			$_SESSION["search_target"] = $target;
			if($target == "all") {
				if(!isset($_REQUEST["term"])) error("No search term specified", 400);
				$term = $_REQUEST["term"];
				if(strlen($term) < 2)  error("Search term less than 2 characters", 400);
				$search_info = $pdi->quickSearch($term, 1000, false, $items_per_page, $page, $sort_criteria, $sort_order);
				$_SESSION["search_term"] = $term;
				$files_info = $search_info["files"];
				$facet_info = $search_info["facets"];
				$info_info = $search_info["info"];
				process_files_info($files_info, $facet_info, $info_info);
			} else if($target == "metadata") {
				if(!isset($_REQUEST["filters"])) error("No filters specified", 400);
				$json_str = $_REQUEST["filters"];
				$filters = json_decode($json_str, true);
				foreach($filters as $f) {
					if(!isset($f["name"])) error("Filters must contain a name key", 400);
					if(!isset($f["values"])) error("Filters must contain a values key", 400);
				}
				$files_info = $pdi->filterPath(false, $filters, true, false, false, "", true, false);
//				$_SESSION["search_cache"] = $files_info;
				$_SESSION["search_filters"] = $filters;
				process_files_info($files_info);
			} else {
				error("Search target is invalid", 400);
			}
		} else if($action == "filter") {
			if(!isset($_REQUEST["filters"])) error("No filters specified", 400);
			if(!isset($_SESSION["search_target"]))  error("No previous search critera found", 400);
			$page = $_REQUEST["page"] ? $_REQUEST["page"] : 1;
			$json_str = $_REQUEST["filters"];
			foreach($filters as $f) {
				if(!isset($f["name"])) error("Filters must contain a name key", 400);
				if(!isset($f["values"])) error("Filters must contain a values key", 400);
			}
			$filters = json_decode($json_str, true);
			if($_SESSION["search_target"] == "all") {
				$search_info = $pdi->quickSearchFilter($_SESSION["search_term"], 1000, false, $items_per_page, $page, $filters, $sort_criteria, $sort_order);
				$files_info = $search_info["files"];
				$facet_info = $search_info["facets"];
				$info_info = $search_info["info"];
				process_files_info($files_info, $facet_info, $info_info);
			} else if($_SESSION["search_target"] == "metadata") {
				$files_info = $pdi->filterPath(false, $_SESSION["search_filters"], true, false, false, "", true, false);
			} else {
				error("Unknown search target cache", 400);
			}
		} else {
			error("Search action is invalid", 400);
		}
	} else if($method == "image") {
		if(!isset($_REQUEST["id"])) error("No id specified", 400);
		if(!isset($_REQUEST["type"])) error("No type specified", 400);
		$id = $_REQUEST["id"];
		$type = $_REQUEST["type"];
		if($type == "thumbnail") {
			$img = $pdi->getImageStream($id,"small");
			header('Content-Type: image/jpeg');
			print $img;
			die();
		} else if($type == "large") {
			$img = $pdi->getImageStream($id,"large");
			header('Content-Type: image/jpeg');
			print $img;
			die();
		} else {
			error("Requested image type is invalid", 400);
		}

	} else if($method == "metadata") {
		success($metadata_info);
		$ret_json = json_encode($metadata_info);
		print $ret_json;
		die();
	} else if($method == "download") {
		if(!isset($_REQUEST["id"])) error("No id specified", 400);
		$id = $_REQUEST["id"];
		$pdi->downloadFile($id, "", "browser", "", false);
	} else if($method == "basket") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "add") {
			if(!isset($_REQUEST["id"])) error("No id specified", 400);
			$id = $_REQUEST["id"];
			$basket_info = $pdi->addBasket($id);
			if(!isset($basket_info)) error("Invalid file id", 400);
			process_basket_info($basket_info["BASKET_INFO"]);
		} else if($action == "remove") {
			if(!isset($_REQUEST["id"])) error("No id specified", 400);
			$id = $_REQUEST["id"];
			$basket_info = $pdi->removeBasket($id);
			if(!isset($basket_info)) error("Invalid file id", 400);
			process_basket_info($basket_info["BASKET_INFO"]);
		} else if($action == "view") {
			$basket_info = $pdi->viewBasket();
			process_basket_info($basket_info["BASKET_INFO"]);
		} else if($action == "clear") {
			$basket_info = $pdi->clearBasket();
			process_basket_info($basket_info["BASKET_INFO"]);
		} else if($action == "download") {
			$plugins_info = $pdi->downloadBasket($basket_file_name);
			//print_r($plugins_info);
		} else if($action == "customorder") {
			if(!isset($_REQUEST["options"])) error("No options specified", 400);
			$options = json_decode($_REQUEST["options"], true);
			$plugins_info = $pdi->customOrderBasket($options);
		} else if($action == "manage") {
			$manage_str = $pdi->manageBasket();
//			print "BASKET";
			print $manage_str;
		}
	} else if($method == "logout") {
		$_SESSION["authorised"] = 0;
		$_SESSION["auth"] = "";
		session_destroy();
		success("Logged out OK");
	} else if($method == "upload") {
		foreach(array_keys($_FILES["uploads"]["name"]) as $k) {
			$f_name = $_FILES["uploads"]["name"][$k];
			$f_tmppath = $_FILES["uploads"]["tmp_name"][$k];
			move_uploaded_file($f_tmppath, "/usr/etc/LGS/tmp/$f_name");
			if(isset($_REQUEST["metadata"])) {
				$metadata = json_decode($_REQUEST["metadata"], true);
			} else {
				//$metadata_json = '[{"id": 177, "value": "Harrods"},{"id": 166, "value": "Iris"},{"id": 170, "value": "Great"}]';
				//$metadata = json_decode($metadata_json, true);
				$metadata = array();
			}
			$metadata_replace = array();
			foreach($metadata as $md) {
				$metadata_replace["{".$md["id"]."}"] = $md["value"];
			}
			$upload_path = strtr($upload_path, $metadata_replace);
			$pdi->uploadFile(urlencode($upload_path), "/usr/etc/LGS/tmp/$f_name", $_SESSION["auth"], $metadata);
			unlink("/usr/etc/LGS/tmp/$f_name");
		}
		success("Files uploaded OK");
	} else if($method == "customorder") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "preview" || $action == "download") {
			if(!isset($_REQUEST["id"])) error("No id specified", 400);
			$id = $_REQUEST["id"];
			if(!isset($_REQUEST["options"])) error("No options specified", 400);
			$options = json_decode($_REQUEST["options"], true);
		}
		if($action == "preview") {
			$options[] = array(
				"name" => "webready",
				"value" => "true"
			);
			header('Content-Type: image/jpeg');
			$img = $pdi->customOrder($id, $options, false);
			print $img;
			die();
		} else if($action == "download") {
			header("Content-Type: application/x-zip-compressed");
			header("Content-Disposition: attachment; filename=customorder.zip");
			$img = $pdi->customOrder($id, $options, false);
			print $img;
			die();
		} else if($action == "form") {
			$form = array();
			$fields = array(
				array("name" => "colorspace", "type" => "select", "display" => "Colorspace", "required" => false, "values" => array(array("value" => "Grey", "display" => "Grey"),array("value" => "RGB", "display" => "RGB"),array("value" => "LAB", "display" => "LAB"),array("value" => "CMYK", "display" => "CMYK"))),
				array("name" => "format", "type" => "select", "display" => "Format", "required" => false, "values" => array(array("value" => "jpg", "display" => "JPG"),array("value" => "gif", "display" => "GIF"),array("value" => "png", "display" => "PNG"),array("value" => "tif", "display" => "TIFF"),array("value" => "bmp", "display" => "BMP"),array("value" => "eps", "display" => "EPS"),array("value" => "web", "display" => "web")))
			);
			$form["fields"] = $fields;
			success($form);
		} else {
			error("Action is invalid", 400);
		}
	} else if($method == "marketingasset") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		if(!isset($_REQUEST["id"])) error("No id specified", 400);
		$action = $_REQUEST["action"];
		$id = $_REQUEST["id"];
		if($action == "promote") {
			$keywords = array(
				"keyword".$marketing_asset_keyword_id => "1"
			);
			$pdi->setKeywordsId($keywords,$id, false);
			$file_info = $pdi->getFileInfoId($id, true);
			process_files_info($file_info["FILES_INFO"]);
			success("Asset promoted");
		} else if($action == "demote") {
			$keywords = array(
				"keyword".$marketing_asset_keyword_id => "0"
			);
			$pdi->setKeywordsId($keywords,$id, false);
			$file_info = $pdi->getFileInfoId($id, true);
			process_files_info($file_info["FILES_INFO"]);
			success("Asset demoted");
		} else {
			error("Action is invalid", 400);
		}
	} else if($method == "sharebasketX") {
		$user_info = $pdi->getUserInfo();
		$basketname = $user_info["BASKETFILE"];
		$share_basket = $pdi->interactAssetLink($basketname, $_REQUEST);
		print $share_basket;
	} else if($method == "contactus") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "form") {
			$contactus_form = simplexml_load_file("contactus.xml");
			$form = array();
			$fields = array();
			foreach($contactus_form->fields->field as $field) {
				$fields[] = array(
					"name" => (string)$field->name, "type" => (string)$field->type, "display" => (string)$field->display, "required" => (string)$field->required
				);
			}
			$form["fields"] = $fields;
			success($form);
		} else if($action == "post") {
			$form_data = $_REQUEST;
			foreach(array_keys($form_data) as $f) {
				if($f == "action") unset($form_data[$f]);
				if($f == "method") unset($form_data[$f]);
			}
			$contactus_form = simplexml_load_file("contactus.xml");
			$fieldnames = array();
			
			foreach($contactus_form->fields->field as $field) {
				$fieldnames[(string)$field->name] = (string)$field->display;
				if((boolean)$field->required == true) {
					$tmpname = (string)$field->name;
					if(!isset($form_data[$tmpname])) {
						error("No ".$tmpname." found");
					}
				}
				$email_to = (string)$contactus_form->settings->email_to;
				$email_from = (string)$contactus_form->settings->email_from;
				$subject = (string)$contactus_form->settings->subject;
				$content = "You have received a message from ".$_SESSION["user"].".<br /><br />";
				foreach(array_keys($form_data) as $k) {
					$content .= "<b>".$fieldnames[$k]."</b><br />".$form_data[$k]."<br /><br />";
				}
			}

			$headers = "From: $email_from\r\n";
			$headers .= 'X-Mailer: PHP/' . phpversion();
			$headers .= "MIME-Version: 1.0\r\n";
			$headers .= "Content-Type: text/html; charset=utf-8\r\n";
			if(mail($email_to, $subject, $content, $headers)) {
				success("Your request is being processed");
			} else {
				error("Your request could not be processed", 400);
			}
		} else {
			error("action invalid", 400);
		}
	} else if($method == "campaigncreate") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "form") {
			$form = array();
			$fields = array();
			//TODO - get userlist, agencies etc list from wn
			$campaigncreate_xml = simplexml_load_file("campaigncreate.xml");
			$user_values = array();
			foreach($campaigncreate_xml->userlists->users->user as $user) {
				$user_values[] = array("value" => (string)$user, "display" => (string)$user);
			}
			foreach($campaigncreate_xml->userlists->agencies->agency as $agency) {
				$agency_values[] = array("value" => (string)$agency, "display" => (string)$agency);
			}
			foreach($campaigncreate_xml->userlists->operators->operator as $operator) {
				$operator_values[] = array("value" => (string)$operator, "display" => (string)$operator);
			}
			foreach($campaigncreate_xml->userlists->retailers->retailer as $retailer) {
				$retailer_values[] = array("value" => (string)$retailer, "display" => (string)$retailer);
			}
			foreach($campaigncreate_xml->userlists->distributors->distributor as $distributor) {
				$distributor_values[] = array("value" => (string)$distributor, "display" => (string)$distributor);
			}
			$fields[] = array("name" => "users", "type" => "multiselect", "display" => "Campaign Users", "required" => true, "values" => $user_values);
			$fields[] = array("name" => "campaign_name", "type" => "text", "display" => "Campaign Name", "required" => true);
			$fields[] = array("name" => "agencies", "type" => "multiselect", "display" => "Agencies", "values" => $agency_values, "required" => false);
			$fields[] = array("name" => "operators", "type" => "multiselect", "display" => "Operators", "values" => $operator_values, "required" => false);
			$fields[] = array("name" => "retailers", "type" => "multiselect", "display" => "Retailers", "values" => $retailer_values, "required" => false);
			$fields[] = array("name" => "distributors", "type" => "multiselect", "display" => "Distributors", "values" => $distributor_values, "required" => false);
			$form["fields"] = $fields;
			success($form);
		} else if($action == "create") {
			if(!isset($_REQUEST["users"])) error("No users specified", 400);
			if(!isset($_REQUEST["campaign_name"])) error("No campaign name specified", 400);

			$campaigncreate_xml = simplexml_load_file("campaigncreate.xml");

			$connection = mysql_connect($wn_server, "lgs", "lgsrlz") or error(mysql_error(), 409);
			mysql_select_db("lgs_campaigncreate") or error('Unable to select database!', 409);

			$campaign_name = mysql_real_escape_string($_REQUEST["campaign_name"]);

			$query = "SELECT campaignID FROM campaign WHERE name = '$campaign_name'";
			$result = mysql_query($query) or error(mysql_error(), 409);
			if (mysql_num_rows($result) > 0) {
				error("Campaign $campaign_name already exists", 500);
			}

			if(is_array($_REQUEST["users"])) {
				$users = $_REQUEST["users"];
			} else if(json_decode($_REQUEST["users"], true)) {
				$users = json_decode($_REQUEST["users"], true);
			} else {
				error("users not in valid format", 400);
			}

			$paths_to_create = array();
			$metadata_to_add = array();

			if(isset($_REQUEST["agencies"])) {
				if(is_array($_REQUEST["agencies"])) {
					$agencies = $_REQUEST["agencies"];
				} else if(json_decode($_REQUEST["agencies"], true)) {
					$agencies = json_decode($_REQUEST["agencies"], true);
				} else {
					error("agencies not in valid format", 400);
				}
				foreach($agencies as $agency) {
					$metadata_to_add["agencies"][] = $agency;
					foreach($campaigncreate_xml->paths->agencies->path as $path) {
						$path = strtr($path, array("{AGENCY}" => $agency, "{CAMPAIGN_NAME}" => $campaign_name));
						$paths_to_create[] = $path;
//						print "Create $path<br />";
					}
				}
			}
			if(isset($_REQUEST["operators"])) {
				if(is_array($_REQUEST["operators"])) {
					$operators = $_REQUEST["operators"];
				} else if(json_decode($_REQUEST["operators"], true)) {
					$operators = json_decode($_REQUEST["operators"], true);
				} else {
					error("operators not in valid format", 400);
				}
				foreach($operators as $operator) {
					$metadata_to_add["operator"][] = $operator;
					foreach($campaigncreate_xml->paths->operators->path as $path) {
						$path = strtr($path, array("{OPERATOR}" => $operator, "{CAMPAIGN_NAME}" => $campaign_name));
						$paths_to_create[] = $path;
//						print "Create $path<br />";
					}
				}
			}
			if(isset($_REQUEST["retailers"])) {
				if(is_array($_REQUEST["retailers"])) {
					$retailers = $_REQUEST["retailers"];
				} else if(json_decode($_REQUEST["retailers"], true)) {
					$retailers = json_decode($_REQUEST["retailers"], true);
				} else {
					error("retailers not in valid format", 400);
				}
				foreach($retailers as $retailer) {
					$metadata_to_add["retailer"][] = $retailer;
					foreach($campaigncreate_xml->paths->retailers->path as $path) {
						$path = strtr($path, array("{RETAILER}" => $retailer, "{CAMPAIGN_NAME}" => $campaign_name));
						$paths_to_create[] = $path;
//						print "Create $path<br />";
					}
				}
			}
			if(isset($_REQUEST["distributors"])) {
				if(is_array($_REQUEST["distributors"])) {
					$distributors = $_REQUEST["distributors"];
				} else if(json_decode($_REQUEST["distributors"], true)) {
					$distributors = json_decode($_REQUEST["distributors"], true);
				} else {
					error("distributors not in valid format", 400);
				}
				foreach($distributors as $distributor) {
					$metadata_to_add["distributor"][] = $distributor;
					foreach($campaigncreate_xml->paths->distributors->path as $path) {
						$path = strtr($path, array("{DISTRIBUTOR}" => $distributor, "{CAMPAIGN_NAME}" => $campaign_name));
						$paths_to_create[] = $path;
//						print "Create $path<br />";
					}
				}
			}
			
			$users_to_add = array();
			mysql_select_db("webnative") or error('Unable to select database!', 409);
			foreach($users as $u) {
				$query = "SELECT userid FROM user WHERE username = '$u'";
				$result = mysql_query($query) or error(mysql_error(), 409);
				if (mysql_num_rows($result) > 0) {
					while ($row = mysql_fetch_array($result)) {
						$users_to_add[] = $row["userid"];
					}
				}
			}
			
//			print_r($users_to_add);

			mysql_select_db("lgs_campaigncreate") or error('Unable to select database!', 409);

			$query = "INSERT INTO campaign (name) VALUES ('$campaign_name')";
			$result = mysql_query($query) or error(mysql_error(), 409);
			
			$campaignID = mysql_insert_id();
			
			foreach($paths_to_create as $p) {
				$createpath_success = $pdi->createFullPath($p);
				if(!$createpath_success) error("Could not create path", 500);

				$tmppath = mysql_real_escape_string($p);
				$pdi->createFullPath($tmppath);
				$query = "INSERT INTO campaignpath (campaignID, path) VALUES ('$campaignID', '$tmppath')";
				$result = mysql_query($query) or error(mysql_error(), 409);
			}

			foreach($users_to_add as $uid) {
				$query = "INSERT INTO campaignuser (campaignID, userID) VALUES ('$campaignID', $uid)";
				$result = mysql_query($query) or error(mysql_error(), 409);
			}
			
			foreach(array_keys($metadata_to_add) as $md_name) {
				foreach($metadata_to_add[$md_name] as $md_val) {
					$tmpval = mysql_real_escape_string($md_val);
					$query = "INSERT INTO metadata (campaignID, name, value) VALUES ('$campaignID', '$md_name', '$tmpval')";
					$result = mysql_query($query) or error(mysql_error(), 409);
				}
			}
			success("Campaign created", 200);			
		} else {
			error("action invalid", 400);
		}
	} else if($method == "usersettings") {
		$ret_array = array(
			"username" => $_SESSION["user"],
			"displayname" => $_SESSION["user"]
		);
		success($ret_array, 200);
	} else if($method == "help") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "html") {
			$help_xml = simplexml_load_file("help/help.xml");
			$ret_arr = array();
			foreach($help_xml->sections->section as $section) {
				$ret_arr[] = array(
					"title" => (string)$section->title,
					"content" => base64_encode(file_get_contents("help/".(string)$section->file))
				);
			}
			success($ret_arr, 200);
		} else if($action == "download") {
			$help_xml = simplexml_load_file("help/help.xml");
			$help_pdf_filename = $help_xml->settings->pdf;
			header('Content-Type: application/pdf');
			readfile("help/".$help_pdf_filename);
			//print $help_pdf_contents;
		} else {
			error("action invalid", 400);
		}
	} else if($method == "sharebasket") {
		if(!isset($_REQUEST["action"])) error("No action specified", 400);
		$action = $_REQUEST["action"];
		if($action == "form") {
			$form = array();
			$fields = array();
			$fields[] = array("name" => "ACTARG6", "type" => "text", "display" => "Email to", "required" => true);
			$fields[] = array("name" => "ACTARG1", "type" => "text", "display" => "Expiry time (hours)", "required" => true);
			$fields[] = array("name" => "ACTARG4", "type" => "select", "display" => "Archive type", "values" => array(array("value" => "SIT", "display" => "Stuff It"),array("value" => "ZIP", "display" => "PC ZIP"),array("value" => "MACZIP", "display" => "Mac ZIP"),array("value" => "UZIP", "display" => "Uncompressed PC ZIP"),array("value" => "UMACZIP", "display" => "Uncompressed Mac ZIP")), "required" => true);
			$form["fields"] = $fields;
			success($form);
		} else if($action == "post") {
			if($share_basket = json_decode($pdi->shareBasket($_REQUEST), true)) {
//				print_r($inalias_reg);
				if(isset($share_basket["Status"])) {
					if($share_basket["Status"] == "ERROR") {
						error($share_basket["Message"], 429);
					}
				}
			}
			success("Share basket request being processed", 200);
		}
	} else {
		error("Method is invalid", 400);
	}

?>
