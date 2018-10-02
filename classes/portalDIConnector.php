<?php

if (file_exists(dirname(__FILE__)."/curl_http_client.php"))
{
	include_once(dirname(__FILE__)."/curl_http_client.php");
} else {
	die ("<b>[Error]: </b><i><u>curl_http_client.php</u></i> is missing. Please make sure that the file is present inside the ".dirname(__FILE__)." directory.");
}

class PortalDI_Connector
{
	
	private $wnServer;
	private $agent;
	private $curlObj;
	
	private function StartsWith($Haystack, $Needle){
    	return strpos($Haystack, $Needle) === 0;
	}

	
	/*
	 * Constructor
	 */
	public function PortalDI_Connector ($wnServer="")
	{
		
		//Check if the session has been previously initialized. If not, i will start it.
		if (!session_id()) session_start();
		
		// Initialize the curl obj, needed for url call
		$this->curlObj = new Curl_HTTP_Client(false);
		
		// Set the user agent
		$this->curlObj->set_user_agent($_SERVER["HTTP_USER_AGENT"]);
		$this->curlObj->store_cookies("/usr/etc/LGS/tmp/cookies.txt");
		
		//set forwarded for address???
		//$this->curlObj->set_forwarded_for();
		
		// Set webnative Server
		if(!empty($wnServer)) $this->wnServer = $wnServer;
		else {
			if (isset($_SESSION["CONFIG"]["WNHOSTNAME"]))
			{
				list($this->wnServer) = split(":",$_SESSION["CONFIG"]["WNHOSTNAME"]);
			}
		}
		
		// When the class is initialized, i am setting the user for http authentification.
		// This method can be called with different credentials to be able to retrieve infos
		// of different users; 
	}
	
	/*
	 * Set the user which the cURL will user to pull information out
	 */
	public function setCredentials($b64_user="")
	{	
		// It is possible to retrieve information form any user specified in here.
		// If no user is specified, the system will take the current one.
		if (empty($b64_user))
		{
			if (isset($_SESSION["USER"]))
			{
				$this->curlObj->set_credentials($_SESSION["USER"]);
			}
		} else {
			$this->curlObj->set_credentials($b64_user);
		}
	}
	
	/*
	 * Get portalDI version
	 */
	public function getVersion() 
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=version";
		return $this->curlObj->fetch_url($url);
	}
	
	/*
	 * Get volumes information
	 * 
	 * [PARAMETERS]:
	 * $path        => (optional) if provided, it will return only information about that particular volume
	 * $credentials => (optional) username and password divided by column symbol (Ex. demo:demo)
	 * $custom      => (optional) Additional parameters can be passed to the portalDI cgi
	 * 
	 * If the second arguments is needed to be used without specifying the credentials, a 'null' value
	 * can be passed as first param.
	 * Ex.
	 * $obj->getVolumesInfo(null,"&showastxt=true&eebug=true");
	 * 
	 */
	public function getVolumes($path="", $credentials="",$custom="")
	{
		// Change credentials to the given user
		if (!empty($credentials)) $this->setCredentials(base64_encode($credentials));
		
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showvols{$custom}";
		$result = json_decode($this->curlObj->fetch_url($url),true);

		if (!empty($path))
		{
			// boolean which tell me if the volume specified by the user has been found
			$found = false;
			
			foreach($result["VOLUME_INFO"] AS $key => $value)
			{
				if ($value["FILE_PATH"] == $path)
				{
					$found = true;
					$volumeInfo = $value;
					break;
				}
			}
			if (!$found) $volumeInfo = "Volume not found";
		}
		
		// Rollback to the default logged in user
		if (!empty($credentials)) $this->setCredentials();
		
		if (!empty($path)) return $volumeInfo;
		else return (isset($result["VOLUME_INFO"]) ? $result["VOLUME_INFO"] : $result);
	
	}	
	
	/*
	 * Get user settings
	 */
	public function checkAuth()
	{		
		$url = "http://{$this->wnServer}/webnative/portalDI";
		$authinfo = json_decode($this->curlObj->fetch_url($url),true);
		return $authinfo;
	}

	public function getUserInfo($path="")
	{		
		$userinfo = array();
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showusersettings";
		$userinfo = json_decode($this->curlObj->fetch_url($url),true);
		
		if (!empty($path))
		{
			$path    = rawurlencode(urldecode($path));
			$volumes = $this->getVolumes();
			
			if (count($volumes) > 1)
			{
				foreach($volumes AS $key => $value)
				{
					$filePath = rawurlencode(urldecode($value["FILE_PATH"]));
					if (preg_match("|{$filePath}|i",$path))
					{
						$userinfo["PERMISSIONS"] = $value["USERINFO"];
					}
				}
			}
		}
		
		return $userinfo;
		
	}
	
	public function getFileInfoId($id, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="")
	{
		if (empty($id)) return "Error: the \$id argument is missing";
		else {
			
			$fileID  = $id;
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?action=fileinfo&fileid=".$fileID.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
			return $result;

		}
	
	}
	
	public function getBrowse($fileID, $showDirs=false, $itemsPerPage=50, $page=1, $getSubItems=false, $sort_criteria=false, $sort_order="asc") {
	
		if (empty($fileID)) {
			return "Error: the fileID argument is missing";
		} else {

			$files = array();
			$info = array();
			$facets = array();

			//get facet info
			$url = "http://{$this->wnServer}/webnative/portalDI?";
			$url .= "fileid=".$fileID;
			$url .= "&showkywds=true";
			$url .= "&showfileinfo=false";
			$url .= "&showfacetinfo=true";
			$url .= "&recursive=true";
			
			$result = json_decode($this->curlObj->fetch_url($url),true);
			
			if (isset($result["ERROR_MSG"])) {
				return $result["ERROR_MSG"];
			} else {
				foreach($result["FACET_INFO"] as $fi) {
					if($this->StartsWith($fi["NAME"], "field")) {
						$fi_name_array = explode("field", $fi["NAME"]);
						$fi_name_array2 = explode("_str", $fi_name_array[1]);
						$fi_id = (int)$fi_name_array2[0];
						$value_list = array();
						foreach($fi["VALUES"] as $fv) {
							if($fv["MATCHES"] > 0) {
								$value_list[] = array(
									"value" => $fv["VALUE"],
									"count" => $fv["MATCHES"]
								);
							}
						}
						$facets[] = array(
							"solrname" => $fi["NAME"],
							"id" => $fi_id,
							"values" => $value_list
						);
					}
				}

				//get file info
				$pageStr = "&page=$page";
				$itemsPerPageStr = "&itemsperpage=$itemsPerPage";

				if($showDirs) {
					$showDirsStr = "";
					$perPageStr = "&itemsperpage=".$itemsPerPage;
					$pageStr = "&page=".$page;
				} else {
					$showDirsStr = "&showdirs=false&showfiles=true";
					$perPageStr = "&filesperpage=".$itemsPerPage;
					$pageStr = "&filepage=".$page;
				}

				$url = "http://{$this->wnServer}/webnative/portalDI?";
				$url .= "fileid=".$fileID;
				$url .= "&showkywds=true";
				$url .= "&showfileinfo=true";
				$url .= "&showfacetinfo=false";
				$url .= $showDirsStr;
				$url .= $perPageStr;
				$url .= $pageStr;

				if($sort_criteria) {
					$url .= "&sort=$sort_criteria+$sort_order";
				}
			

				$result = json_decode($this->curlObj->fetch_url($url),true);

				if (isset($result["ERROR_MSG"])) {
					return $result["ERROR_MSG"];
				} else {
					$info["file_count"] = $result["DIRECTORY_INFO"]["TOTALMATCHES"];
					$info["page_count"] = ceil($result["DIRECTORY_INFO"]["TOTALMATCHES"] / $itemsPerPage);

					$files_info = $result["FILES_INFO"];
					foreach($files_info AS $k => $value) {
						array_push($files,$value);
					}

					//get nav info
					$url = "http://{$this->wnServer}/webnative/portalDI?";
					$url .= "action=navigator";
					$url .= "&fileid=".$fileID;
					$result = json_decode($this->curlObj->fetch_url($url),true);

					if (isset($result["ERROR_MSG"])) {
						return $result["ERROR_MSG"];
					} else {
						$nav_info = $result["NAVIGATOR_INFO"];

						if($getSubItems) {
							foreach(array_keys($nav_info) as $k) {
								$url = "http://{$this->wnServer}/webnative/portalDI?";
								$url .= "action=navigator";
								$url .= "&fileid=".$nav_info[$k]["FILE_ID"];
								$result = json_decode($this->curlObj->fetch_url($url),true);
								if(sizeof($result["NAVIGATOR_INFO"][0]) == 0) {
									$nav_info[$k]["foldercount"] = 0;
								} else {
									$nav_info[$k]["foldercount"] = sizeof($result["NAVIGATOR_INFO"]);
								}
							}
						}
						foreach($nav_info AS $k => $value) {
							array_push($files,$value);
						}
						
						return array("files" => $files, "facets" => $facets, "info" => $info);
					}
				}
			}
		}
	}

	public function getBrowseFilter($fileID, $showDirs=false, $itemsPerPage=50, $page=1, $filters=array(), $getSubItems=false, $sort_criteria=false, $sort_order="asc") {
	
		if (empty($fileID)) {
			return "Error: the fileID argument is missing";
		} else {

			$files = array();
			$info = array();
			$facets = array();

			if($showDirs) {
				$showDirsStr = "";
				$perPageStr = "&itemsperpage=".$itemsPerPage;
				$pageStr = "&page=".$page;
			} else {
				$showDirsStr = "&showdirs=false&showfiles=true";
				$perPageStr = "&filesperpage=".$itemsPerPage;
				$pageStr = "&filepage=".$page;
			}
		
			
			//get file and facet info
			$url = "http://{$this->wnServer}/webnative/portalDI?";
			$url .= "action=search";
			$url .= "&fileid=".$fileID;
			$url .= "&searchsubdirs=false";
			$url .= "&showfileinfo=true";
			$url .= "&showkywds=true";
			$url .= "&showfacetinfo=true";
			$url .= "&recursive=true";
			$url .= $showDirsStr;
			$url .= $perPageStr;
			$url .= $pageStr;

			if($sort_criteria) {
				$url .= "&sort=$sort_criteria+$sort_order";
			}
			
			
			$filter_count = 0;
			$applied_filters = array();
			foreach($filters as $filter) {
				foreach($filter["values"] as $value) {
					$filter_count++;
					$url .= "&facet_solrname_0_$filter_count=".$filter["name"];
					$url .= "&facet_0_$filter_count=".urlencode($value);
					$applied_filters[$filter["name"]][$value] = 1;
				}
			}
			
			$result = json_decode($this->curlObj->fetch_url($url),true);

			if (isset($result["ERROR_MSG"])) {
				return $result["ERROR_MSG"];
			} else {
				foreach($result["FACET_INFO"] as $fi) {
					if($this->StartsWith($fi["NAME"], "field")) {
						$fi_name_array = explode("field", $fi["NAME"]);
						$fi_name_array2 = explode("_str", $fi_name_array[1]);
						$fi_id = (int)$fi_name_array2[0];
						$value_list = array();
						foreach($fi["VALUES"] as $fv) {
							if($fv["MATCHES"] > 0 || isset($applied_filters[$fi["NAME"]][$fv["VALUE"]])) {
								$value_list[] = array(
									"value" => $fv["VALUE"],
									"count" => $fv["MATCHES"]
								);
							}
						}
						$facets[] = array(
							"solrname" => $fi["NAME"],
							"id" => $fi_id,
							"values" => $value_list
						);
					}
				}

				$info["file_count"] = $result["DIRECTORY_INFO"]["TOTALMATCHES"];
				$info["page_count"] = ceil($result["DIRECTORY_INFO"]["TOTALMATCHES"] / $itemsPerPage);

				$files_info = $result["FILES_INFO"];
				foreach($files_info AS $k => $value)
				{
					array_push($files,$value);
				}

				//get nav info
				$url = "http://{$this->wnServer}/webnative/portalDI?";
				$url .= "action=navigator";
				$url .= "&fileid=".$fileID;
				$result = json_decode($this->curlObj->fetch_url($url),true);

				if (isset($result["ERROR_MSG"])) {
					return $result["ERROR_MSG"];
				} else {
					$nav_info = $result["NAVIGATOR_INFO"];

					if($getSubItems) {
						foreach(array_keys($nav_info) as $k) {
							$url = "http://{$this->wnServer}/webnative/portalDI?";
							$url .= "action=navigator";
							$url .= "&fileid=".$nav_info[$k]["FILE_ID"];
							$result = json_decode($this->curlObj->fetch_url($url),true);
							if(sizeof($result["NAVIGATOR_INFO"][0]) == 0) {
								$nav_info[$k]["foldercount"] = 0;
							} else {
								$nav_info[$k]["foldercount"] = sizeof($result["NAVIGATOR_INFO"]);
							}
						}
					}
					foreach($nav_info AS $k => $value) {
						array_push($files,$value);
					}
					return array("files" => $files, "facets" => $facets, "info" => $info);
				}
			}
		}
	}

	public function quickSearch($val, $maxmatches=100, $showDirs=false, $itemsPerPage=50, $page=1, $sort_criteria=false, $sort_order="desc")
	{

		$files = array();
		$facets = array();
		$info = array();
	
		if(isset($val)) 
		{

			if($showDirs) {
				$showDirsStr = "";
				$perPageStr = "&itemsperpage=".$itemsPerPage;
				$pageStr = "&page=".$page;
			} else {
				$showDirsStr = "&showdirs=false&showfiles=true";
				$perPageStr = "&filesperpage=".$itemsPerPage;
				$pageStr = "&filepage=".$page;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?";
			
			$url .= "action=search";
			$url .= "&searchengine=auto";
			$url .= "&showkywds=true";
			$url .= $showDirsStr;
			$url .= $perPageStr;
			$url .= $pageStr;
			$url .= "&searchsubdirs=true";
			$url .= "&quicksearch_0_0=".urlencode($val);

			if($sort_criteria) {
				$url .= "&sort=$sort_criteria+$sort_order";
			}
			
			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);

				foreach($result["FACET_INFO"] as $fi) {
					if($this->StartsWith($fi["NAME"], "field")) {
						$fi_name_array = explode("field", $fi["NAME"]);
						$fi_name_array2 = explode("_str", $fi_name_array[1]);
						$fi_id = (int)$fi_name_array2[0];
						$value_list = array();
						foreach($fi["VALUES"] as $fv) {
							if($fv["MATCHES"] > 0) {
								$value_list[] = array(
									"value" => $fv["VALUE"],
									"count" => $fv["MATCHES"]
								);
							}
						}
						$facets[] = array(
							"solrname" => $fi["NAME"],
							"id" => $fi_id,
							"values" => $value_list
						);
					}
				}

					$info["file_count"] = $result["DIRECTORY_INFO"]["TOTALMATCHES"];
					$info["page_count"] = ceil($result["DIRECTORY_INFO"]["TOTALMATCHES"] / $itemsPerPage);

//			print_r($result);
//			die();
		
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else {
				foreach($result["FILES_INFO"] AS $k => $value) {
					array_push($files,$value);	
				}
				return array("files" => $files, "facets" => $facets, "info" => $info);
			}
		}
	}	 

	public function quickSearchFilter($val, $maxmatches=100, $showDirs=false, $itemsPerPage=50, $page=1, $filters=array(), $sort_criteria=false, $sort_order="desc")
	{

		$files = array();
		$facets = array();
		$info = array();
	
		if(isset($val)) 
		{


			if($showDirs) {
				$showDirsStr = "";
				$perPageStr = "&itemsperpage=".$itemsPerPage;
				$pageStr = "&page=".$page;
			} else {
				$showDirsStr = "&showdirs=false&showfiles=true";
				$perPageStr = "&filesperpage=".$itemsPerPage;
				$pageStr = "&filepage=".$page;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?";
			
			$url .= "action=search";
			$url .= "&searchengine=auto";
			$url .= "&showkywds=true";
			$url .= $showDirsStr;
			$url .= $perPageStr;
			$url .= $pageStr;
			$url .= "&searchsubdirs=true";
			$url .= "&quicksearch_0_0=".urlencode($val);
			if($sort_criteria) {
				$url .= "&sort=$sort_criteria+$sort_order";
			}
			

			$filter_count = 0;
			$applied_filters = array();
			foreach($filters as $filter) {
				foreach($filter["values"] as $value) {
					$filter_count++;
					$url .= "&facet_solrname_0_$filter_count=".$filter["name"];
					$url .= "&facet_0_$filter_count=".urlencode($value);
					$applied_filters[$filter["name"]][$value] = 1;
				}
			}
//			print $url;
//			die();
			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);
//			print_r($result);
//			die();
		
				foreach($result["FACET_INFO"] as $fi) {
					if($this->StartsWith($fi["NAME"], "field")) {
						$fi_name_array = explode("field", $fi["NAME"]);
						$fi_name_array2 = explode("_str", $fi_name_array[1]);
						$fi_id = (int)$fi_name_array2[0];
						$value_list = array();
						foreach($fi["VALUES"] as $fv) {
							if($fv["MATCHES"] > 0 || isset($applied_filters[$fi["NAME"]][$fv["VALUE"]])) {
								$value_list[] = array(
									"value" => $fv["VALUE"],
									"count" => $fv["MATCHES"]
								);
							}
						}
						$facets[] = array(
							"solrname" => $fi["NAME"],
							"id" => $fi_id,
							"values" => $value_list
						);
					}
				}

					$info["file_count"] = $result["DIRECTORY_INFO"]["TOTALMATCHES"];
					$info["page_count"] = ceil($result["DIRECTORY_INFO"]["TOTALMATCHES"] / $itemsPerPage);

			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else {
				foreach($result["FILES_INFO"] AS $k => $value) {
					array_push($files,$value);	
				}
//				print_r($files);
//				die();
				return array("files" => $files, "facets" => $facets, "info" => $info);
			}
		}
	}	 
	
	public function getBrowseWithFacets($id, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="", $showFiles=true, $showFolders=false, $getSubitems=false, $showItemsPerPage=false, $showPage=false)
	{
		
		$files = array();
		$info = array();
		
		// check if the $path is specified
		if (empty($id)) return "Error: the \$path argument is missing";
		else {
			
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			$itemsperpage = $showItemsPerPage ? "&itemsperpage=".$showItemsPerPage : "";
			$page = $showPage ? "&page=".$showPage : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?action=browse&fileid=".$id.$kwds.$version.$history.$itemsperpage.$page.$custom;
//			print $url;
//			die();
			$result = json_decode($this->curlObj->fetch_url($url),true);
//			print_r($result);
//			die();
			
			$facets = array();
			foreach($result["FACET_INFO"] as $fi) {
				if($this->StartsWith($fi["NAME"], "field")) {
					$fi_name_array = explode("field", $fi["NAME"]);
					$fi_name_array2 = explode("_str", $fi_name_array[1]);
					$fi_id = (int)$fi_name_array2[0];
					$value_list = array();
					foreach($fi["VALUES"] as $fv) {
						$value_list[] = array(
							"value" => $fv["VALUE"],
							"count" => $fv["MATCHES"]
						);
					}
					$facets[] = array(
						"id" => $fi_id,
						"values" => $value_list
					);
				}
			}
			
//			print_r($facets);
					
			// If the path does not exist, an error will be returned
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else 
			{
				// Loop through the results and remove directory from the listing
				$files_info = $result["FILES_INFO"];
				$info["file_count"] = $result["DIRECTORY_INFO"]["TOTALMATCHES"];
				if($showItemsPerPage) {
					$info["page_count"] = ceil($result["DIRECTORY_INFO"]["TOTALMATCHES"] / $showItemsPerPage);
				} else {
					$info["page_count"] = 1;
				}
				if($getSubitems) {
					foreach(array_keys($files_info) as $k) {
						if($files_info[$k]["FILE_ISADIR"] == 1) {
							$folder_ids[] = $files_info[$k]["FILE_ID"];
						}
					}
					$items = array();
					if(sizeof($folder_ids) > 0) {
						$folders_contents = $this->getFilesInfoId($folder_ids, false, false, false, "", true, true);
						foreach($folders_contents as $file) {
							if($file["FILE_ISADIR"] == 0) {
								if(isset($items[$file["PARENT_FILE_ID"]]["filecount"])) {
									$items[$file["PARENT_FILE_ID"]]["filecount"] = $items[$file["PARENT_FILE_ID"]]["filecount"] + 1;
								} else {
									$items[$file["PARENT_FILE_ID"]]["filecount"] = 1;
								}
							} else {
								if(isset($items[$file["PARENT_FILE_ID"]]["foldercount"])) {
									$items[$file["PARENT_FILE_ID"]]["foldercount"] = $items[$file["PARENT_FILE_ID"]]["foldercount"] + 1;
								} else {
									$items[$file["PARENT_FILE_ID"]]["foldercount"] = 1;
								}
							}
						}
					}
					foreach(array_keys($files_info) as $k) {
						$tmp_file = $files_info[$k];
						if(isset($items[$tmp_file["FILE_ID"]]["filecount"])) {
							$files_info[$k]["filecount"] = $items[$tmp_file["FILE_ID"]]["filecount"];
						} else {
							$files_info[$k]["filecount"] = 0;
						}
						if(isset($items[$tmp_file["FILE_ID"]]["foldercount"])) {
							$files_info[$k]["foldercount"] = $items[$tmp_file["FILE_ID"]]["foldercount"];
						} else {
							$files_info[$k]["foldercount"] = 0;
						}
					}
				}
				foreach($files_info AS $k => $value)
				{
					if ($showFiles && ($value["FILE_ISADIR"] == 0)) array_push($files,$value);	
					if ($showFolders && ($value["FILE_ISADIR"] == 1)) array_push($files,$value);	
				}
			}
			return array("files" => $files, "facets" => $facets, "info" => $info);
		}
	}

	public function manageBasket() {
		$url = "http://{$this->wnServer}/webnative/plugins/basketadmin?/var/adm/webnative/pepsi_super/192.168.98.32.70f50696.basket";
//		print $url;
		$result = $this->curlObj->fetch_url($url);
		return $result;
	}

	public function getFilesInfoId($id, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="", $showFiles=true, $showFolders=false)
	{
		
		$files = array();
		
		// check if the $path is specified
		if (empty($id)) return "Error: the \$path argument is missing";
		else {
		
			$id_str = "";
			if(is_array($id)) {
				foreach($id as $idval) {
					$id_str .= "&fileid=".$idval;
				}
			} else {
				$id_str .= "&fileid=".$id;
			}
			
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?action=browse=".$id_str.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
					
			// If the path does not exist, an error will be returned
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else 
			{
				// Loop through the results and remove directory from the listing
				foreach($result["FILES_INFO"] AS $k => $value)
				{
					if ($showFiles && ($value["FILE_ISADIR"] == 0)) array_push($files,$value);	
					if ($showFolders && ($value["FILE_ISADIR"] == 1)) array_push($files,$value);	
				}
				return $files;
			}
		}
	}

	public function getImageStream($fileid, $filetype="small")
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=getimage&filetype=".$filetype . "&fileid=".$fileid;
		return $this->curlObj->fetch_url($url);
	}

	public function getInAliasForm($teamname)
	{
		$url = "http://{$this->wnServer}/ipik/inalias?action=register&team=" . $teamname;
//		$url = "http://{$this->wnServer}/ipik/inalias?action=register";
		return $this->curlObj->fetch_url($url);
	}

	public function registerInAliasUser($teamname, $form_data)
	{
		$url = "http://{$this->wnServer}/ipik/inalias?action=register&team=" . $teamname;
/*
		$form_data = array(
			"email" => $email,
			"password1" => $password,
			"password2" => $password
		);
*/
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
		}
//		print_r($form_data);
//		die();
//		print $url . "<br />";
//		print_r($form_data);
//		die();
		$result = $this->curlObj->send_post_data($url, $form_data, null, 3);
		return $result;
	}
	
	public function changePassword($teamname, $form_data)
	{
		$url = "http://{$this->wnServer}/ipik/inalias?action=pwreset&team=" . $teamname;
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
		}
		$result = $this->curlObj->send_post_data($url, $form_data, null, 3);
		return $result;
	}	

	public function changePasswordValidate($teamname, $form_data)
	{
		$url = "http://{$this->wnServer}/ipik/inalias?action=pwrvalidation&team=" . $teamname;
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
		}
		$result = $this->curlObj->send_post_data($url, $form_data, null, 3);
		return $result;
	}	

	public function changePasswordChange($teamname, $form_data)
	{
		$url = "http://{$this->wnServer}/ipik/inalias?action=pwupdate&team=" . $teamname."&id=".$form_data["id"];
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
			if($f == "id") unset($form_data[$f]);
		}
		$result = $this->curlObj->send_post_data($url, $form_data, null, 3);
		return $result;
	}	

	public function shareBasket($form_data)
	{
//		$url = "http://{$this->wnServer}/ipik/inalias?action=pwreset&team=" . $teamname;
		$url = "http://{$this->wnServer}/webnative/plugins/tr_cust?-F+-fassetlink_+".$basketname;
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
		}
		
		$form_data["ACTARG0"] = "auto";
		$form_data["FILETYPE"] = "HIGH";
		$form_data["ACTION"] = "assetlink";
		$form_data["status"] = "doorder";
//		$form_data["session"] = "1517229671";
		$form_data["NEXT"] = "Send%20Maillink";

		$user_info = $this->getUserInfo();
		$basketname = $user_info["BASKETFILE"];

		$form_data["BASKET"] = $basketname;
		
//		print_r($form_data);
//		die();

		$result = $this->curlObj->send_post_data($url, $form_data, null, 3);
		return $result;
	}	

	public function interactAssetLink($basketname, $form_data)
	{
		$url = "http://{$this->wnServer}/webnative/plugins/tr_cust?-F+-fassetlink_+".$basketname;
		foreach(array_keys($form_data) as $f) {
			if($f == "action") unset($form_data[$f]);
			if($f == "method") unset($form_data[$f]);
		}
		$result = $this->curlObj->send_post_data($url, $form_data, null, 10);
		return $result;
	}

	public function registerInAlias4User($sitename, $userid, $name, $email, $password)
	{
		$url = "http://{$this->wnServer}/inalias/inalias?" . $sitename . "+-xml" . "+-makeLogin";
//		print $url;
		$form_data = array(
			"userid" => $userid,
			"name" => $name,
			"email" => $email,
			"password" => $password,
			"repeat_password" => $password
		);
//		$result = $this->curlObj->fetch_url($url);
//		$result = json_decode($this->curlObj->fetch_url($url),true);
//print $url;
//die();
		$result = $this->curlObj->send_post_data($url, $form_data);
		
//		print "DONE";
//		die();
		return $result;
	}

	public function viewBasket()
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showbasket";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		return $result;
	}

	public function clearBasket()
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=clearbasket";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		return $result;
	}

	public function addBasket($id)
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=addbasket&fileid=".$id;
		$result = json_decode($this->curlObj->fetch_url($url),true);
		return $result;
	}

	public function removeBasket($id)
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=removebasket&fileid=".$id;
		$result = json_decode($this->curlObj->fetch_url($url),true);
		return $result;
	}

	public function downloadBasket($basket_file_name)
	{
	
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showbasket";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		ignore_user_abort(true);
		
		$rnd = rand(100000000, 999999999);
		$tmp_path = "/tmp/lgs/bsk_$rnd";
		mkdir($tmp_path, 0777, true);
		
		$zip = new ZipArchive();
		$filename = "$tmp_path/$basket_file_name";

		if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
			error("cannot open <$filename>\n");
		}
		foreach($result["BASKET_INFO"] as $f) {
			$this->downloadFile($f["FILE_ID"], "", $tmp_path."/".$f["FILE_NAME"], "", false);
			$zip->addFile($tmp_path."/".$f["FILE_NAME"],"/".$f["FILE_NAME"]);
		}
		$zip->close();
		foreach($result["BASKET_INFO"] as $f) {
			unlink($tmp_path."/".$f["FILE_NAME"]);
		}

		header("Content-Type: application/x-zip-compressed");
		header("Content-Disposition: attachment; filename=$basket_file_name");
		header("Content-Length: " . filesize($filename));
		
		readfile($filename);
		unlink($filename);
		rmdir($tmp_path);
		exit;
		
/*
		//try to use /webnative/plugins/downloadbasket
		$user_info = $this->getUserInfo();
		$basket_file = $user_info["BASKETFILE"];
		$url = "http://{$this->wnServer}/webnative/plugins/downloadbasket?".$basket_file;
		$form_data = array(
			"archName" => "SamsungBasket.zip",
			"archFmt" => "MACZIP",
			"exportXMP" => "no"
		);
//		curl_setopt($this->curlObj, CURLOPT_HEADER, false);
		$result = $this->curlObj->send_post_data($url, $form_data);
		
//		$header_size = curl_getinfo($this->curlObj, CURLINFO_HEADER_SIZE);
//		$header = substr($result, 0, $header_size);
//		$body = substr($result, $header_size);
//		header("Content-Type: application/x-zip-compressed");
//		header("Content-Disposition: attachment; filename=SamsungBasket.zip");
//		header("Content-Length: " . strlen($result));
		print $result;
*/

	}
	
	public function customOrderBasket($options)
	{
	
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showbasket";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		ignore_user_abort(true);
		
		$rnd = rand(100000000, 999999999);
		$tmp_path = "/tmp/lgs/bsk_$rnd";
		mkdir($tmp_path, 0777, true);
		
		$zip = new ZipArchive();
		$filename = "$tmp_path/SamsungBasket.zip";

		if ($zip->open($filename, ZipArchive::CREATE)!==TRUE) {
			error("cannot open <$filename>\n");
		}
		$options[] = array(
			"name" => "webready",
			"value" => "true"
		);
		foreach($result["BASKET_INFO"] as $f) {
			$img = $this->customOrder($f["FILE_ID"], $options, false);
			file_put_contents($tmp_path."/".$f["FILE_NAME"], $img);
			$zip->addFile($tmp_path."/".$f["FILE_NAME"],"/".$f["FILE_NAME"]);
		}
		$zip->close();
		foreach($result["BASKET_INFO"] as $f) {
			unlink($tmp_path."/".$f["FILE_NAME"]);
		}

		header("Content-Type: application/x-zip-compressed");
		header("Content-Disposition: attachment; filename=SamsungOrderBasket.zip");
		header("Content-Length: " . filesize($filename));
		
		readfile($filename);
		unlink($filename);
		rmdir($tmp_path);
		exit;
		
/*
		//try to use /webnative/plugins/downloadbasket
		$user_info = $this->getUserInfo();
		$basket_file = $user_info["BASKETFILE"];
		$url = "http://{$this->wnServer}/webnative/plugins/downloadbasket?".$basket_file;
		$form_data = array(
			"archName" => "SamsungBasket.zip",
			"archFmt" => "MACZIP",
			"exportXMP" => "no"
		);
//		curl_setopt($this->curlObj, CURLOPT_HEADER, false);
		$result = $this->curlObj->send_post_data($url, $form_data);
		
//		$header_size = curl_getinfo($this->curlObj, CURLINFO_HEADER_SIZE);
//		$header = substr($result, 0, $header_size);
//		$body = substr($result, $header_size);
//		header("Content-Type: application/x-zip-compressed");
//		header("Content-Disposition: attachment; filename=SamsungBasket.zip");
//		header("Content-Length: " . strlen($result));
		print $result;
*/

	}

	public function getKeywords()
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showkywdperms";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		return $result["KEYWORDS_INFO"];
	}
	
	public function searchAll($val, $showKeywords=false, $maxmatches=100, $selectedvol=1, $timeout=100, $showFiles=true, $showFolders=false)
	{

		$files = array();
	
		if(isset($val)) 
		{
			$url = "http://{$this->wnServer}/webnative/portalDI?action=search&searchaction=search";
			$url .= "&maxmatches=".$maxmatches;
			$url .= "&selectedvol=".$selectedvol;
			if($showKeywords) 
			{
				$url .= "&showkywds=true";
			}
			$url.="&searchall_0=".$val;
			$url.="&searchall_flag_0=1";

			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);
		
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else 
			{
				// Loop through the results and remove directory from the listing
				foreach($result["FILES_INFO"] AS $k => $value)
				{
					if ($showFiles && ($value["FILE_ISADIR"] == 0)) array_push($files,$value);	
					if ($showFolders && ($value["FILE_ISADIR"] == 1)) array_push($files,$value);	
				}
				return $files;
			}
		}
	}	 

	public function filterPath($pathid, $filters, $showKeywords=false, $maxmatches=100, $selectedvol=1, $timeout=100, $showFiles=true, $showFolders=false)
	{

		$files = array();

		if($pathid !== false) {
			$path_info = $this->getFileInfoId($pathid);
			if(!isset($path_info["FILES_INFO"])) die("No file found");
			$path = $path_info["FILES_INFO"][0]["FILE_PATH"];
			$doPathFilter = true;
			//add subfolders for filtered path
			$folder_info = $this->getBrowseWithFacets($pathid, false, false, false, "", false, true, true);
			foreach($folder_info["files"] as $f) {
//				array_push($files,$f);
			}
		} else {
			$doPathFilter = false;
		}
		//setting this to make sure folders that match filter criteria are excluded
		$showFolders = true;
	
			$url = "http://{$this->wnServer}/webnative/portalDI?action=search&searchaction=search";
			$url .= "&maxmatches=".$maxmatches;
//			$url .= "&selectedvol=".$selectedvol;
			if($showKeywords) 
			{
				$url .= "&showkywds=true";
			}
//			$url.="&fileid=".$id;
//			$url.="&fileid=950";
//			$url.="&fileid[]=222";
//			$url.="&path=/Data/Samsung/Master_Assets/Products";
//			$url.="&path=/Data/Samsung/Master_Assets/Products";
//			$url.="&treedepth=10";
			$total_filter_count = 0;
			$user_filters = array();
			foreach($filters as $f) {
				$sub_filter_count = 0;
				foreach($f["values"] as $v) {
					$user_filters[$f["name"]][] = $v;
					$url .= "&dbsearch_keyword_".$f["name"]."_".$total_filter_count."=".$v;
					$url .= "&dbsearch_flag_".$f["name"]."_".$total_filter_count."=4";
					if($total_filter_count > 0) {
						if($sub_filter_count > 0) {
							//OR search for filters in same keyword
							$url .= "&dbsearch_logic_".$f["name"]."_".$total_filter_count."=0";
						} else {
							//AND serach for new keyword filter
							$url .= "&dbsearch_logic_".$f["name"]."_".$total_filter_count."=0";
						}
					}
					$total_filter_count++;
					$sub_filter_count++;
				}
			}
//			print $url;
//			die();
			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);
			
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else 
			{
				foreach($result["FILES_INFO"] AS $k => $value)
				{
					$all_filters_matched = true;
					foreach(array_keys($user_filters) as $ufk) {
						$filter_match = false;
						foreach($user_filters[$ufk] as $ufv) {
							if($ufv == $value["KEYWORD_INFO"][$ufk]["KW_VALUE"]) {
								$filter_match = true;
							}
						}
						if(!$filter_match) {
							$all_filters_matched = false;
						}
					}
					if (!$all_filters_matched) continue;
					if ($doPathFilter && !$this->StartsWith($value["FILE_PATH"], $path)) continue;
					if ($showFiles && ($value["FILE_ISADIR"] == 0)) array_push($files,$value);
					if ($showFolders && ($value["FILE_ISADIR"] == 1)) array_push($files,$value);
				}
				return $files;
			}
	}	 


        public function uploadFile($dir, $local_filepath, $userPass, $metadata) {

                $param = array();

                if(!file_exists($local_filepath)) return "Local file does not exist";
                else {

                        $url = "http://{$this->wnServer}/webnative/upload?".$dir;
                        $md_str = "";
                        foreach($metadata as $m) {
                        	$md_str .= "-F'dbkeyword".$m["id"]."=".($m["value"])."' ";
                        }
                        $cmd = "/usr/bin/curl -F'overwrite=1' -F\"filedata=@$local_filepath\" $md_str -u '".base64_decode($userPass)."' -s '$url'";
                        exec($cmd);
                        return true;

                }

        }


	public function createFolder($path,$newName,$overwrite=false)
	{
		$path    = rawurlencode(urldecode($path));
		$newName = rawurlencode(urldecode($newName));
		
		$overwrite = $overwrite === FALSE ? "false" : "true";

		$url = "http://{$this->wnServer}/webnative/portalDI?action=filemgr&filemgraction=mkdir&newpath={$path}&newname={$newName}&overwrite={$overwrite}";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		return $result;
	}

	public function checkPathExists($path) {
//		$dir_path = dirname($path);
//		$dir_name = basename($path);
//		print "check $path exists<br />";
		$path_info = $this->getFileInfoPath($path);
//		print_r($path_info);
//		print "<br />";
		$path_exists = false;
		if(isset($path_info["FILES_INFO"]) && sizeof($path_info["FILES_INFO"]) > 0 && sizeof($path_info["FILES_INFO"][0]) > 0) {
			return true;
		}
		return false;
	}

	public function createFullPath($path) {
		$tmp_path = $path;
		$path_exists = false;
		$folders_to_create = array();
		while(!$path_exists && strlen($tmp_path) > 1) {
			$path_exists = $this->checkPathExists($tmp_path);
			if(!$path_exists) {
				array_unshift($folders_to_create,basename($tmp_path));
				$tmp_path = dirname($tmp_path);
			}
		}
		if(sizeof($folders_to_create) > 0) {
			foreach($folders_to_create as $ftc) {
				$create_success = $this->createFolder($tmp_path, $ftc, false);
				if($create_success["ERRCODE"] != 201) {
					return false;
				}
				if($tmp_path != "/") {
					$tmp_path .= "/";
				}
				$tmp_path .= $ftc;
			}
		}
		return true;
	}
	//NOT USING FUNCTIONS BELOW THIS LINE

	
	/*
	 * Get directory information
	 * 
	 * [PARAMETERS]:
	 * $path           => path to which the directory is located
	 * $showKeywords   => (boolean) If set to true, will show keywords information about the directory
	 * $showVersion    => (boolean) If set to true, will show all versions related to that directory
	 * $showHistory    => (boolean) If set to true, will show hitosy information
	 * $custom         => (optional) Additional parameters can be passed to the portalDI cgi
	 * 
	 */
	public function getDirectoryInfo($path, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="")
	{
		if (empty($path)) return "Error: the \$path argument is missing";
		else {
			
			$path    = rawurlencode(urldecode($path));
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?path=".$path.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
			
		}
		
		return $result;
	}
	
	
	/*
	 * Get list of directories based on a certain path
	 * 
	 * [PARAMETERS]:
	 * $path   => path to which the directory is located
	 * $custom => (optional) Additional parameters can be passed to the portalDI cgi
	 */
	public function getDirectories($path, $custom="")
	{
		if (empty($path)) return "Error: the \$path argument is missing";
		else 
		{
			$path = rawurlencode(urldecode($path));
			
			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?path=".$path.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
			
		}
		
		return (isset($result["FILES_INFO"]) ? $result["FILES_INFO"] : $result);		
	}
	
	/*
	 * Get list of directories based on a certain path
	 * 
	 * [PARAMETERS]:
	 * $path   => path to which the directory is located
	 * $custom => (optional) Additional parameters can be passed to the portalDI cgi
	 */
	public function getAllContents($path, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="")
	{
		if (empty($path)) return "Error: the \$path argument is missing";
		else {

			$path    = rawurlencode(urldecode($path));
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";			
			
			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?showfiles=true&path=".$path.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
		}
		
		return (isset($result["FILES_INFO"]) ? $result["FILES_INFO"] : $result);
	}	
	
	/*
	 * Get file/s information
	 */
	public function getFilesInfo($path, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="", $showFiles=true, $showFolders=false)
	{
		
		$files = array();
		
		// check if the $path is specified
		if (empty($path)) return "Error: the \$path argument is missing";
		else {
			
			$path    = rawurlencode(urldecode($path));
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?showfiles=true&path=".$path.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);

			// If the path does not exist, an error will be returned
			if (isset($result["ERROR_MSG"])) return $result["ERROR_MSG"];
			else 
			{
				// Loop through the results and remove directory from the listing
				foreach($result["FILES_INFO"] AS $k => $value)
				{
					if ($showFiles && ($value["FILE_ISADIR"] == 0)) array_push($files,$value);	
					if ($showFolders && ($value["FILE_ISADIR"] == 1)) array_push($files,$value);	
				}
				return $files;
			}
		}
	}


	public function getFileInfoPath($path, $showKeywords=false, $showVersion=false, $showHistory=false, $custom="")
	{
		if (empty($path)) return "Error: the \$path argument is missing";
		else {
			
			$path    = rawurlencode(urldecode($path));
			$kwds    = $showKeywords ? "&showkywds=true" : "";
			$version = $showVersion ? "&showversions=true" : "";
			$history = $showHistory ? "&showhistory=true" : "";

			if (!empty($custom))
			{
				if (substr($custom,0,1) != "&") $custom = "&".$custom;
			}

			$url = "http://{$this->wnServer}/webnative/portalDI?action=fileinfo&path=".$path.$kwds.$version.$history.$custom;
			$result = json_decode($this->curlObj->fetch_url($url),true);
			return $result;

		}
	
	}

	

	public function portalTrigger($id) {
		if (empty($id)) return "Error: the \$path argument is missing";
		$url = "http://{$this->wnServer}/webnative/portalDI?action=setevent&fileid=".$id;
		$result = json_decode($this->curlObj->fetch_url($url),true);
	}

	public function getTree($id, $showFiles=true, &$results = array()) {
//		print "START GET TREE FOR $id</br />";
		$url = "http://{$this->wnServer}/webnative/portalDI?action=browse&fileid=".$id."&showdirs=true";
		if ($showFiles) $url.="&showfiles=true";
		$url = "http://{$this->wnServer}/webnative/portalDI?action=browse&fileid=".$id;
		$files_info = json_decode($this->curlObj->fetch_url($url),true);
//		print_r($files_info["FILES_INFO"]);
//		die();
//		foreach($files_info["FILES_INFO"] as $f) {
//			print $f["FILE_ID"] . "<br />";
//		}
		foreach($files_info["FILES_INFO"] as $f) {
			if($f["FILE_ISADIR"] == 1) {
//				print "DO ANOTHER GETTREE";
//				die();
				$this->getTree($f["FILE_ID"], $showFiles, $results);
				$results["dirs"][] = $f["FILE_PATH"];
			} else {
				$results["files"][$f["FILE_ID"]] = $f["FILE_PATH"];
			}
		}
		return $results;
	}

	
	/*
	 * Return keywordsa vailable to the current user
	 */
	
	public function setKeywords($keywords,$path, $recursive=false)
	{
		
		// It could be that the path is part encoded, therefore i am decode it all, and encode it right after.
//		$path = urldecode($path);
//		$assetInfo = $this->getDirectories($path);
		
//		$url = "http://{$this->wnServer}/webnative/portalDI?action=submitkywd{$keywords}&path={$path}";
//		$result = json_decode($this->curlObj->fetch_url($url),true);

		$url = "http://{$this->wnServer}/webnative/portalDI";
		$keywords["action"] = "submitkywd";
		$keywords["path"] = $path;
		$result = json_decode($this->curlObj->send_post_data($url, $keywords),true);

//		print $url."<br />";
		
//		return $result["KEYWORDS_INFO"];
	}


	public function setKeywordsId($keywords,$id, $recursive=false)
	{
		
		// It could be that the path is part encoded, therefore i am decode it all, and encode it right after.
//		$path = urldecode($path);
//		$assetInfo = $this->getDirectories($path);
		
//		$url = "http://{$this->wnServer}/webnative/portalDI?action=submitkywd{$keywords}&path={$path}";
//		$result = json_decode($this->curlObj->fetch_url($url),true);

		$url = "http://{$this->wnServer}/webnative/portalDI";
		$keywords["action"] = "submitkywd";
		$keywords["fileid"] = $id;
		$result = json_decode($this->curlObj->send_post_data($url, $keywords),true);

//		print $url."<br />";
		
//		return $result["KEYWORDS_INFO"];

		return $result;
	}	
	/***********************/
	/*** FILE MANAGEMENT ***/	
	/***********************/
	
	/*
	 * Create folder on webnative
	 * 
	 * [PARAMETERS]:
	 * $path      => path to where the new directory is going to be created
	 * $newName   => name of the new directory
	 * $overwrite => (optional) Decide wh
	 */

	public function copyFileId($id,$newPath,$newName,$overwrite=true)
	{
		$newName = rawurlencode(urldecode($newName));
		
		$overwrite = $overwrite === FALSE ? "false" : "true";

		$url = "http://{$this->wnServer}/webnative/portalDI?fileid={$id}&action=filemgr&filemgraction=copy&newpath={$newPath}&newname={$newName}&overwrite={$overwrite}";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		return $result;
	}

	public function moveFileId($id,$newPath,$newName,$overwrite=true)
	{
		$newName = rawurlencode(urldecode($newName));
		
		$overwrite = $overwrite === FALSE ? "false" : "true";

		$url = "http://{$this->wnServer}/webnative/portalDI?fileid={$id}&action=filemgr&filemgraction=move&newpath={$newPath}&newname={$newName}&overwrite={$overwrite}";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		return $result;
	}

	public function deleteFileId($id)
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?fileid={$id}&action=filemgr&filemgraction=delete";
		$result = json_decode($this->curlObj->fetch_url($url),true);
		
		return $result;
	}
	
	/*
	 * Remove folder on webnative
	 * 
	 * [PARAMETERS]:
	 * $path      => path to where the new directory is going to be created
	 * $newName   => name of the new directory
	 * $overwrite => (optional) Decide wh
	 */
	public function deleteAsset($path_to_file)
	{
		$path_to_file = rawurlencode(urldecode($path_to_file));
		
		$url = "http://{$this->wnServer}/webnative/portalDI?action=filemgr&filemgraction=delete&filename=".$path_to_file;
		$result = json_decode($this->curlObj->fetch_url($url),true);
 
		return $result["MSG"];
	}
	
	public function searchVenture($kwArray, $paths, $showKeywords=false, $maxmatches=100, $selectedvol=1, $filedir=false, $timeout=100) {
	
		if(isset($kwArray)) {
	
			$url = "http://{$this->wnServer}/webnative/portalDI?action=search&searchaction=search";
			$url .= "&maxmatches=".$maxmatches;
			$url .= "&selectedvol=".$selectedvol;
			if($showKeywords) {
				$url .= "&showkywds=true";
			}
			if($filedir == "0") {
				$url .= "&filedir_0=0";
				$url .= "&filedir_logic_0=0";
			} else if($filedir == "1") {
				$url .= "&filedir_0=1";
				$url .= "&filedir_logic_0=1";
			}
			$attrCount=1;
			foreach($kwArray as $kw) {
				if($kw[0] == "filename") {
					$url.="&filename_".$attrCount."=".$kw[2]."&filenamelogic_".$attrCount."=0"."&filename_flag_".$attrCount."=1";
				} else {
					$url.="&dbsearchflag".$kw[0]."_".$attrCount."=".$kw[1]."&dbsearchkeyword".$kw[0]."_".$attrCount."=".$kw[2];
					if($kw[3]) {
						$url.="&dbsearchlogic".$kw[0]."_".$attrCount."=".$kw[3];
					}
				}
				$attrCount++;
			}
			foreach($paths as $path) {
				$url.="&path=".urlencode($path);
			}
//			print $url;
			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);
		
			return $result;
		}
	}

	public function searchFilename($val, $paths, $showKeywords=false, $maxmatches=100, $selectedvol=1, $filedir=false, $timeout=100) 
	{
	
		if(isset($val)) 
		{
			$url = "http://{$this->wnServer}/webnative/portalDI?action=search&searchaction=search";
			$url .= "&maxmatches=".$maxmatches;
			$url .= "&selectedvol=".$selectedvol;
			if($showKeywords) 
			{
				$url .= "&showkywds=true";
			}
			$url.="&filename_0=".$val;
			$url.="&filename_flag_0=1";
			foreach($paths as $path) 
			{
				$url.="&path=".urlencode($path);
			}
			if($filedir == "0") {
				$url .= "&filedir=0";
			} else if($filedir == "1") {
				$url .= "&filedir=1";
			}

//			print $url;
			$result = json_decode($this->curlObj->fetch_url($url, null, $timeout),true);
		
			return $result;
		}
	}	 

	
	
	public function downloadFile($fileid="", $filepath="", $output, $filetype="", $attach=false)
	{
		$param = "";
		
		if (empty($fileid) && empty($filepath)) return "Either fileid or filepath has to be defined";
		else {
	 
			if (!empty($fileid))   $param .= "&fileid=".$fileid;
			if (!empty($filepath)) $param .= "&filepath=".rawurlencode(urldecode($filepath));
			if (!empty($filetype)) $param .= "&filetype=".$filetype;
			if (!empty($attach))   $param .= "&attach=".$attach;			
		
			$url = "http://{$this->wnServer}/webnative/portalDI?action=streamfile".$param;
			$file = $this->curlObj->fetch_url($url);
//			print $url;
//			die();
			if($output == "browser") {
				$finfo = $this->getFileInfoId($fileid);
				if(!isset($finfo["FILES_INFO"][0])) error("Invalid file id");
//				print_r($finfo);
//				die();
				header("Content-Length: " . $finfo["FILES_INFO"][0]["FILE_LENGTH"]);
				header("Content-Disposition: attachment; filename=" . $finfo["FILES_INFO"][0]["FILE_NAME"]);
				header('Content-Transfer-Encoding: binary');
				header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
				print $file;
			} else {
				file_put_contents($output,$file);
			}
		}
		
	}

	public function customOrder($fileid="", $options, $attach=false)
	{
		$param = "";
		
		if (empty($fileid)) return "fileid has to be defined";
		else {
	 
			$param .= "&fileid=".$fileid;
			foreach($options as $option) {
				$param .= "&".$option["name"]."=".$option["value"];
			}
		
			$url = "http://{$this->wnServer}/webnative/portalDI?action=getorderimage".$param;
//			print $url;
//			die();
			$file = $this->curlObj->fetch_url($url);
			return $file;
//			die();
//			print $url;
//			die();
			$finfo = $this->getFileInfoId($fileid);
//			print_r($finfo);
//			die();
			if(!isset($finfo["FILES_INFO"][0])) error("Invalid file id");
//			print_r($finfo);
//			die();
			header("Content-Length: " . $finfo["FILES_INFO"][0]["FILE_LENGTH"]);
			header("Content-Disposition: attachment; filename=" . $finfo["FILES_INFO"][0]["FILE_NAME"]);
			header('Content-Transfer-Encoding: binary');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			print $file;
		}
		
	}

	public function getUserSettings()
	{
		$url = "http://{$this->wnServer}/webnative/portalDI?action=showusersettings";
		$result = json_decode($this->curlObj->fetch_url($url, null),true);
		return $result;
	}

        
}

	

?>
