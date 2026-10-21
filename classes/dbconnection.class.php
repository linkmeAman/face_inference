<?php
require_once __DIR__."/services/ModuleFlowConnection.php";
date_default_timezone_set('Asia/Kolkata');

	class dbconnection{
		protected $mysqli;

		// This file is included in gitignore
		public $api_url = "//admin.creativeculturalcentre.in/";

		// Method to connect with db by db_name
		public function connect($db_name){
            if (class_exists("ModuleFlowRuntime", false) && ModuleFlowRuntime::connectionFor($db_name)) return ModuleFlowRuntime::connectionFor($db_name);

			$mysqli = new ModuleFlowConnection(getenv("FACE_EXPORT_DB_HOST") ?: "127.0.0.1", getenv("FACE_EXPORT_DB_USER") ?: "", getenv("FACE_EXPORT_DB_PASSWORD") ?: "", $db_name);
			if ($mysqli->connect_errno) {
			    echo "Failed to connect to MySQL: " . $mysqli->connect_error;
			}
			else
				return $mysqli;
		}

		// Method to find out the id of action table pertaining to action
		public function action($label){
			$conn = $this->connect('ccc_central');
			$action = $conn->query("SELECT id FROM `ccc_central`.`action` WHERE label = '{$label}'")->fetch_assoc();

			return !empty($action) ? $action['id'] : 0 ;
		}

		// Method to find out the id of module table pertaining to module name
		public function module($label){
			$conn = $this->connect('ccc_central');
			$module = $conn->query("SELECT id FROM `ccc_central`.`module` WHERE module = '{$label}' AND park = '0'")->fetch_assoc();

			return !empty($module) ? $module['id'] : 0 ;	
		}

		public function getAbbreviation($module) {
			$permissions = $_SESSION['permissions'];
			foreach ($permissions as $key => $permission) {
				if ($permission['module_id'] == $module) {
					return $permission['abbreviation'];
				}
			} 
			return "";
		}

		public function getModuleTitle($module) {
			$permissions = $_SESSION['permissions'];
			foreach ($permissions as $key => $permission) {
				if ($permission['module_id'] == $module) {
					return $permission['module'];
				}
			}
			return "";
		}
	}

 ?>
