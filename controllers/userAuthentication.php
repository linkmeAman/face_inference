<?php
	//error_reporting(0);
	require_once __DIR__ . '/../classes/dbconnection.class.php';
	require_once __DIR__ . '/../classes/mailer.class.php';
	require_once __DIR__ . '/../classes/smsgateway.class.php';
	require_once __DIR__ . '/../inc/defaults.php';


	class userAuthentication extends dbconnection{
		
		public $response = array();


		public function check_session(){
            require_once __DIR__.'/../classes/session.class.php';
            if(!(new session())->check_session([]))return json_encode(['error'=>0,'error_msg'=>'','data'=>['alreadyLogin'=>0],'message'=>'']);

			if(session_status()!==PHP_SESSION_ACTIVE)session_start();
			if (!isset($_SESSION['db_name']) && empty($_SESSION['db_name'])) 
				$response = ['data'=>['alreadyLogin'=>0],'error'=>0,'error_msg'=>'','message'=>''];
			else if(isset($_SESSION['db_name']) && !empty($_SESSION['db_name']))
				$response = ['data'=>['alreadyLogin'=>1,'home_page'=>$_SESSION['home_page'],'user_name'=>$_SESSION['user_name']],'error'=>0,'error_msg'=>'','message'=>''];
			else
				$response = ['data'=>[],'error'=>1,'error_msg'=>'','message'=>''];

			return json_encode($response);	
		}


		public function get_user_settings($request){
            require_once __DIR__.'/../classes/session.class.php';
            if(!(new session())->check_session($request))return json_encode(['error'=>2,'error_msg'=>'AUTH_REQUIRED','data'=>[],'message'=>'Please sign in again']);
            $request['user_type']=$GLOBALS['ccc_authenticated_context']['type'];

			error_reporting(1);
			$conn = $this->connect("ccc_central");		
			$result = $conn->query("SELECT `u`.`id`,`u`.`admin`,`u`.`admin`,`u`.`fname`,`u`.`contact_id`,`u`.`position_id`,`us`.`image_url`,`u`.`lname`,`u`.`mpin`,`u`.`password`,`u`.`client_id`,`cd`.`db_name`,`cd`.`logo`,`cd`.`inst_name`, `u`.`is_first_login`, `u`.`type` AS `user_type` FROM user AS u LEFT JOIN user_social AS us ON `u`.`id` = `us`.`user_id` INNER JOIN client_db AS cd ON `u`.`client_id` = `cd`.`id` WHERE `u`.`id` = '".get_uid()."' AND `u`.`type` = '{$request['user_type']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' ");
			// echo "SELECT `u`.`id`,`u`.`admin`,`u`.`admin`,`u`.`fname`,`u`.`contact_id`,`u`.`position_id`,`us`.`image_url`,`u`.`lname`,`u`.`mpin`,`u`.`password`,`u`.`client_id`,`cd`.`db_name`,`cd`.`logo`,`cd`.`inst_name`, `u`.`is_first_login`, `u`.`type` AS `user_type` FROM user AS u LEFT JOIN user_social AS us ON `u`.`id` = `us`.`user_id` INNER JOIN client_db AS cd ON `u`.`client_id` = `cd`.`id` WHERE `u`.`id` = '".get_uid()."' AND `u`.`type` = '{$request['user_type']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' ";

			$row_cnt = $result->num_rows;
			$row = $result->fetch_assoc();
			if($row_cnt){

				// if($request['user_type'] == 'E')
				// 	$response = $this->emp_data($conn,$row,0);
				// else if($request['user_type'] == 'M')
				// 	$response = $this->memb_data($conn,$row);	
				$response = $this->getData($conn, $row, 0);
	 		}
	 		else{
	 			$response['data'] = array();
				$response['error'] = 2;
				$response['error_msg'] = '';
				$response['message'] = 'Invalid Login';
	 		}
	 		$conn->close();
			return json_encode($response);
		}


		public function validate_oauth($request){
            return json_encode(['error'=>1,'error_msg'=>'OAUTH_PROOF_REQUIRED','data'=>[],'message'=>'Sign in with your password; an unverified social profile cannot authorize a session']);

			$conn = $this->connect("ccc_central");
					
			$result = $conn->query("SELECT `u`.`id`,`u`.`admin`,`u`.`fname`,`u`.`contact_id`,`u`.`position_id`,`us`.`image_url`,`u`.`lname`,`u`.`mpin`,`u`.`password`,`u`.`client_id`, `u`.`is_first_login`,`cd`.`db_name`,`cd`.`logo`,`cd`.`inst_name` FROM user AS u INNER JOIN user_social AS us ON `u`.`id` = `us`.`user_id` INNER JOIN client_db AS cd ON `u`.`client_id` = `cd`.`id` WHERE `us`.`email` = '{$request['email']}' AND `us`.`social_type` = '{$request['type']}' AND `us`.`social_id` = '{$request['id']}' AND `u`.`type` = '{$request['user_type']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' AND `u`.`signup` = '1' ");

			$row_cnt = $result->num_rows;
			$row = $result->fetch_assoc();
			$token = $this->update_device($conn,$row['id']);
			if($row_cnt){
				if(!empty($row['mpin']) || !empty($row['password']))
					$response = ['data'=>['hasPassword'=>1],'error'=>1,'error_msg'=>'','message'=>''];
				else{
					// if($request['user_type'] == 'E')
					// 	$response = $this->emp_data($conn,$row,1);
					// else if($request['user_type'] == 'M')
					// 	$response = $this->memb_data($conn,$row);
					$response = $this->getData($conn, $row, 1);
				}
	 		}
	 		else{
	 			if (isset($request['login']) && $request['login']==0) { //Set in temp
	 				$conn->query("INSERT INTO `otp_backup`(`name`, `email`,`signup`, `created_at`) VALUES ('{$request['name']}','{$request['email']}', '1', '".date('Y-m-d H:i:s')."')");
	 			}
	 			$response['data'] = array();
				$response['error'] = 0;
				$response['error_msg'] = '';
				$response['message'] = '';
	 		}
	 		$conn->close();
			return json_encode($response);		
			
		}

		private function emp_data($conn,$row,$start_session = 1,$error_no = 0){
			$home_page = "pf/dashboard.php";
			$token = $this->update_device($conn,$row['id']);
			$branch = array();
			$client_cont = $conn->query("SELECT contact_id FROM client_member WHERE client_id = '{$row['client_id']}'")->fetch_assoc(); 
			$conn1 = $this->connect($row['db_name']);
			$studio = $conn1->query("SELECT studio FROM studio ")->fetch_assoc();

			$studio_sett = $conn1->query("SELECT `inter_branch_member`, `just_dial` FROM `studio_setting`")->fetch_assoc();

			$contact = $conn1->query("SELECT image FROM contact WHERE id = '{$row['contact_id']}'")->fetch_assoc();
			$image_url = "https://admin.creativeculturalcentre.in/content/contact_images/";

			$client = $conn1->query("SELECT `b`.`id`, `b`.`branch`, `b`.`type` FROM `{$row['db_name']}`.branch AS `b` INNER JOIN `ccc_central`.`user_bid` AS `ub` ON `ub`.`bid`=`b`.`id`  WHERE `ub`.`user_id` = '{$row['id']}' ORDER BY  `b`.`is_headquaters` DESC,`b`.`branch` ASC");

			//Get Equipments
			$equipment_arr = array();
			$equipments = $conn1->query("SELECT `equipment_id`, `equipment` FROM `equipment_view` ORDER BY `equipment_id` ASC");
			while($equipment = $equipments->fetch_assoc()) {
				array_push($equipment_arr, $equipment);
			}

			while ($branch_data = $client->fetch_assoc()) 
				array_push($branch, $branch_data);

			$permissions = $this->permissions($conn,$row['client_id'],$row['id'],$branch[0]['id']);
			$config = $this->config($conn,$row['client_id'],$row['id'],$branch[0]['id'],$row['user_type']); 

			$emp = $conn1->query("SELECT id FROM employee WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();
			if($start_session){
				session_start();
				$_SESSION = ['config'=>$config,'permissions'=>$permissions,'firstLogin'=>0,'db_name'=>$row['db_name'],'bid'=>$branch[0]['id'],'client_id'=>$row['client_id'],'user_id'=>$row['id'],'admin'=>$row['admin'],'user_name'=>$row['fname'].' '.$row['lname'],'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],'session_id'=>session_id(),'inst_name'=>$studio['studio'],'just_dial'=>$studio_sett['just_dial'],'inter_branch_member'=>$studio_sett['inter_branch_member'],'branch'=>$branch,'position_id'=>$row['position_id'],'employee_id'=>$emp['id'],'token'=>$token,'contact_id'=>$row['contact_id'],'client_contact_id'=>$client_cont['contact_id'], 'is_first_login'=>$row['is_first_login'],'equipments'=>$equipment_arr,'user_type'=>$row['user_type'],'home_page'=>$home_page];
				$_SESSION['auth_epoch']=(int)$conn->query('SELECT auth_epoch FROM user WHERE id='.(int)$row['id'])->fetch_row()[0];
                session_commit();
				set_branch_type();
			}
			$response = ['data'=>['config'=>$config,'permissions'=>$permissions,'firstLogin'=>0,'token'=>$token,'client_id'=>$row['client_id'],'bid'=>$branch[0]['id'],'admin'=>$row['admin'],'user_id'=>$row['id'],'user_name'=>$row['fname'].' '.$row['lname'],'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],'inst_name'=>$studio['studio'],'just_dial'=>$studio_sett['just_dial'],'inter_branch_member'=>$studio_sett['inter_branch_member'],'branch'=>$branch,'position_id'=>$row['position_id'],'employee_id'=>$emp['id'],'contact_id'=>$row['contact_id'],'client_contact_id'=>$client_cont['contact_id'], 'is_first_login'=>$row['is_first_login'],'equipments'=>$equipment_arr,'user_type'=>$row['user_type'],'home_page'=>$home_page],'error'=>$error_no,'error_msg'=>'','message'=>''];

			return $response; 
		}

		private function memb_data($conn,$row,$start_session = 1,$error_no = 0){
			$date = date('Y-m-d');
			$token = $this->update_device($conn,$row['id']);
			$conn1 = $this->connect($row['db_name']);
			$studio = $conn1->query("SELECT studio FROM studio ")->fetch_assoc();
			$branch = array();
			$client = $conn1->query("SELECT `b`.`id`, `b`.`branch`, `b`.`type` FROM `{$row['db_name']}`.branch AS `b` INNER JOIN `ccc_central`.`user_bid` AS `ub` ON `ub`.`bid`=`b`.`id`  WHERE `ub`.`user_id` = '{$row['id']}' ");
			while ($branch_data = $client->fetch_assoc()) 
				array_push($branch, $branch_data);
				
			$contact = $conn1->query("SELECT image, rfid FROM member_contact_view WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();

			$assessment = $conn1->query("SELECT `value` AS `weight` FROM `assessmentlog_task_view` WHERE `task_id` = '61' AND `contact_id` = '{$row['contact_id']}' AND `park` = '0' AND `bid` = '{$branch[0]['id']}' ORDER BY `date` DESC")->fetch_assoc();

			// Get template
			$template = $conn1->query("SELECT `workout_template_id` AS `id` FROM `assign_workout` WHERE `member_id` = (SELECT `id` FROM `member` WHERE `contact_id` = '{$row['contact_id']}') AND `start_date` <= '{$date}' ORDER BY `start_date` DESC")->fetch_assoc();
			$meal_arr = array();
			$meals = $conn1->query("SELECT `meal_id`, `meal`, `calories`, `time` FROM `workout_meal_view` WHERE `template_id` = '{$template['id']}'");
			while ($meal = $meals->fetch_assoc()) {
				array_push($meal_arr, $meal);
			}

			$studio_sett = $conn1->query("SELECT `iot_implement` FROM `studio_setting`")->fetch_assoc();
		
			$image_url = "https://admin.creativeculturalcentre.in/content/contact_images/";
			//Get Equipments
			$equipment_arr = array();
			$equipments = $conn1->query("SELECT `equipment_id`, `equipment`, `uom` FROM `equipment_view` ORDER BY `equipment_id` ASC");
			while($equipment = $equipments->fetch_assoc()) {
				array_push($equipment_arr, $equipment);
			}
			$response = ['data'=>['user_id'=>$row['id'],'user_name'=>$row['fname'].' '.$row['lname'],'contact_id'=>$row['contact_id'],'client_id'=>$row['client_id'],'bid'=>$branch[0]['id'],'inst_name'=>$studio['studio'],'branch'=>$branch,'token'=>$token,'user_image'=>(!empty($contact['image']) ? $image_url.$contact['image'] : ''),'weight'=>(int)$assessment['weight'],'iot_implement'=>$studio_sett['iot_implement'],'rfid'=>$contact['rfid'],'is_first_login'=>$row['is_first_login'],'meals'=>$meal_arr,'equipments'=>$equipment_arr],'error'=>$error_no,'error_msg'=>'','message'=>''];

			return $response;
		}

		private function getData($conn,$row,$start_session = 1,$error_no = 0) {
			error_reporting(1);
			// $home_page = "/pf/dashboard.php";
			if ($row['user_type'] == 'M' || $row['user_type']=='F') {
				$home_page = '/pf/summary.php?contact='.$row['contact_id'];
			}
			$date = date('Y-m-d');
			$token = $this->update_device($conn,$row['id']);
			$branch = array();
			$client_cont = $conn->query("SELECT contact_id FROM client_member WHERE client_id = '{$row['client_id']}'")->fetch_assoc(); 
			$conn1 = $this->connect($row['db_name']);
		
			$studio = $conn1->query("SELECT studio FROM studio ")->fetch_assoc();
			$employee_details = $conn1->query("SELECT `position_id` FROM `employee` WHERE `contact_id` = '{$row['contact_id']}'")->fetch_assoc();
			$studio_sett = $conn1->query("SELECT `inter_branch_member`, `just_dial`, `iot_implement`, `pushapp_dashboard` FROM `studio_setting`")->fetch_assoc();
			// echo "SELECT image, rfid, carrot, gender, mobile, email, `code`  FROM member_contact_view WHERE contact_id = '{$row['contact_id']}'";exit;

			$contact = $conn1->query("SELECT image, rfid, carrot, gender, mobile, email, `code`  FROM member_contact_view WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();
			$active = $conn->query("SELECT MAX(`end`) AS `till` FROM `member_app_subscription` WHERE `contact_id` = '{$row['contact_id']}' AND `client_id` = '{$row['client_id']}'")->fetch_assoc();
			if (!isset($active['till']) || empty($active['till']) || is_null($active['till'])) {
				$active['till'] = '';
			}
			if ($active['till'] != '' && strtotime($active['till']) >= strtotime(date('Y-m-d'))) {
				$active['is_active'] = '1';
			} else {
				$active['is_active'] = '0';
			}
			// echo "SELECT image, rfid, carrot  FROM member_contact_view WHERE id = '{$row['contact_id']}'";
			$image_url = "https://admin.creativeculturalcentre.in/content/contact_images/";

			$client = $conn1->query("SELECT `b`.`id`, `b`.`branch`, `b`.`timezone`, `b`.`type` FROM `{$row['db_name']}`.branch AS `b` INNER JOIN `ccc_central`.`user_bid` AS `ub` ON `ub`.`bid`=`b`.`id`  WHERE `ub`.`user_id` = '{$row['id']}' ORDER BY  `b`.`is_headquaters` DESC,`b`.`branch` ASC");
	
			//Get Equipments
			$equipment_arr = array();
			$equipments = $conn1->query("SELECT `equipment_id`, `equipment` FROM `equipment_view` ORDER BY `equipment_id` ASC");
			while($equipment = $equipments->fetch_assoc()) {
				array_push($equipment_arr, $equipment);
			}
			
			//Get Assignments
			$assignment_arr = array();
			$batch_module_arr = array();
			if ($row['user_type'] == 'F') {
				$assignment_sql = $conn1->query("SELECT DISTINCT `assignment_id` FROM `session_batch_view` WHERE `contact_id` = '{$row['contact_id']}' AND `check_assignment` = '1'");
				while($assignment = $assignment_sql->fetch_assoc()) {
					array_push($assignment_arr, $assignment['assignment_id']);
				}
				$batch_module_sql = $conn1->query("SELECT DISTINCT `batch_id`, `module_id` FROM `session_batch_view` WHERE `contact_id` = '{$row['contact_id']}'");
				while ($batch_module = $batch_module_sql->fetch_assoc()) {
					array_push($batch_module_arr, $batch_module);
				}
			}

			while ($branch_data = $client->fetch_assoc()) 
				array_push($branch, $branch_data);
			$assessment = ['weight'=>0];
			$meals_arr = [];

			error_reporting(1);
			//Get levels
			$level_data = [];
			$sql_levels = $conn1->query("SELECT `cl`.`component_id`, `cl`.`level`, `c`.`gender` FROM `contact_component_level` `cl` join `contact` `c` on (`cl`.`contact_id` = `c`.`id`) WHERE `cl`.`contact_id` = '{$row['contact_id']}'");
			if ($sql_levels) {
				while ($level = $sql_levels->fetch_assoc()) {
					//Get Component and level
					if ($level['component_id'] == 1) { // Strength
						$level_data = $conn->query("SELECT `description` AS `level`, `image` FROM `ccc_central`.`level` WHERE `level` = '{$level['level']}' AND `component_id` = '{$level['component_id']}' AND `gender` = '{$contact['gender']}'")->fetch_assoc();

					}
				}
			}
			if (empty($level_data)) {
				$level_data = ['level'=>' - ', 'image'=>''];
			}

			//Get App Version data
			$app_type = $row['user_type'] == 'M' ? 0 : 1; //Member app : Trainer App {Not decided for admin app}
			$version = $conn->query("SELECT `ios`, `android`, `urgent` FROM `app_ver` WHERE `app_type` = '{$app_type}' ORDER BY `launch_date` DESC, `id` DESC LIMIT 1")->fetch_assoc();

			
			// if ($row['user_type'] == 'M') {
			// 	$assessment = $conn1->query("SELECT `value` AS `weight` FROM `assessmentlog_task_view` WHERE `task_id` = '61' AND `contact_id` = '{$row['contact_id']}' AND `park` = '0' AND `bid` = '{$branch[0]['id']}' ORDER BY `date` DESC")->fetch_assoc();

			// Get template
			// 	$template = $conn1->query("SELECT `workout_template_id` AS `id` FROM `assign_workout` WHERE `member_id` = (SELECT `id` FROM `member` WHERE `contact_id` = '{$row['contact_id']}') AND `start_date` <= '{$date}' ORDER BY `start_date` DESC")->fetch_assoc();
			// 	$meal_arr = array();
			// 	$meals = $conn1->query("SELECT `meal_id`, `meal`, `calories`, `time` FROM `workout_meal_view` WHERE `template_id` = '{$template['id']}'");
			// 	while ($meal = $meals->fetch_assoc()) {
			// 		array_push($meal_arr, $meal);
			// 	}
			// }

			$permissions = $this->permissions($conn,$row['client_id'],$row['id'],$branch[0]['id']);
			// if ($employee_details['position_id']==28 || $employee_details['position_id']==43){
			// 	$home_page = '/pf/list.php?id=315';
			// }else{
    			$home_page = '/pf/summary.php?contact='.$row['contact_id'];
			// }
			// foreach($permissions as $modules){
			// 	if(($modules['module']=="Dashboard") && ($modules["permission"]>0)){
			// 		$home_page = "/pf/dashboard.php";
			// 	}
			// }
			// var_dump($home_page);exit;
			$config = $this->config($conn,$row['client_id'],$row['id'],$branch[0]['id'],$row['user_type']); 

			$emp = $conn1->query("SELECT id,doj,workshift_in_time,workshift_out_time,week_off_code,qualifier FROM employee WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();
			if($start_session){
				session_start();
				$_SESSION = ['config'=>$config,'permissions'=>$permissions,'firstLogin'=>0,'db_name'=>$row['db_name'],'bid'=>$branch[0]['id'],'timezone'=>$branch[0]['timezone'],'client_id'=>$row['client_id'],'user_id'=>$row['id'],'admin'=>$row['admin'],'user_name'=>$row['fname'].' '.$row['lname'],'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],'session_id'=>session_id(),'inst_name'=>$studio['studio'],'just_dial'=>$studio_sett['just_dial'], 'pushapp_dashboard'=>$studio_sett['pushapp_dashboard'],'inter_branch_member'=>$studio_sett['inter_branch_member'],'branch'=>$branch,'position_id'=>$employee_details['position_id'],'employee_id'=>$emp['id'],'doj'=>$emp['doj'],'workshift_in_time'=>$emp['workshift_in_time'],'qualifier'=>$emp['qualifier'],'workshift_out_time'=>$emp['workshift_out_time'],'token'=>$token,'contact_id'=>$row['contact_id'],'client_contact_id'=>$client_cont['contact_id'], 'is_first_login'=>$row['is_first_login'],'equipments'=>$equipment_arr,'user_type'=>$row['user_type'],'home_page'=>$home_page,'weight'=>($row['user_type'] == 'M') ? (int)$assessment['weight'] : "0",'iot_implement'=>$studio_sett['iot_implement'],'rfid'=>$contact['rfid'],'code'=>$contact['code'],'meals'=>($row['user_type'] == 'M') ? $meal_arr : [], 'carrot'=>($row['user_type'] == 'M') ? $contact['carrot']:'0', 'level'=>$level_data, 'active'=>($row['user_type'] == 'M') ? ['is_active'=>$active['is_active'],'active_till'=>$active['till']] : [],'assignment'=>$assignment_arr,'batch_module'=>$batch_module_arr, 'version'=>$version,'mobile'=>$contact['mobile'],'email'=>$contact['email']];
				$_SESSION['auth_epoch']=(int)$conn->query('SELECT auth_epoch FROM user WHERE id='.(int)$row['id'])->fetch_row()[0];
                session_commit();
				set_branch_type();
			}
			$response = ['data'=>['config'=>$config,'permissions'=>$permissions,'firstLogin'=>0,'token'=>$token,'client_id'=>$row['client_id'],'bid'=>$branch[0]['id'],'admin'=>$row['admin'],'user_id'=>$row['id'],'user_name'=>$row['fname'].' '.$row['lname'],'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],'inst_name'=>$studio['studio'],'just_dial'=>$studio_sett['just_dial'],'pushapp_dashboard'=>$studio_sett['pushapp_dashboard'],'inter_branch_member'=>$studio_sett['inter_branch_member'],'branch'=>$branch,'position_id'=>$employee_details['position_id'],'employee_id'=>$emp['id'],'contact_id'=>$row['contact_id'],'client_contact_id'=>$client_cont['contact_id'], 'is_first_login'=>$row['is_first_login'],'equipments'=>$equipment_arr,'user_type'=>$row['user_type'],'home_page'=>$home_page,'weight'=>($row['user_type'] == 'M') ? (int)$assessment['weight'] : "0",'iot_implement'=>$studio_sett['iot_implement'],'rfid'=>$contact['rfid'],'code'=>$contact['code'],'meals'=>($row['user_type'] == 'M') ? $meal_arr : [], 'carrot'=>($row['user_type'] == 'M') ? $contact['carrot']:'0', 'level'=>$level_data,  'active'=>($row['user_type'] == 'M') ? ['is_active'=>$active['is_active'],'active_till'=>$active['till']] : [],'assignment'=>$assignment_arr,'batch_module'=>$batch_module_arr, 'version'=>$version,'mobile'=>$contact['mobile'],'email'=>$contact['email']],'error'=>$error_no,'error_msg'=>'','message'=>''];

			return $response;
		}

		public function validate_mobile($request){
			$conn = $this->connect("ccc_central");

			$result = $conn->query("SELECT `id`, `signup` FROM user WHERE `mobile` = '{$request['mobile']}' AND `type` = '{$request['user_type']}' AND `park` = '0' AND `inactive` = '0' ");

			$row_cnt = $result->num_rows;

			if($row_cnt){
				$user_data = $result->fetch_assoc();
				$response['data'] = ['signup'=>$user_data['signup'],'userid'=>$user_data['id']];
				$response['error'] = 1;
				$response['error_msg'] = 'mobile already present';
				$response['message'] = 'Mobile no. has already associated account,Please enter different mobile no.';
	 		}
	 		else{
	 			$response['data'] = array();
				$response['error'] = 0;
				$response['error_msg'] = '';
				$response['message'] = '';
	 		}
	 		$conn->close();
			return json_encode($response);
			
		}

		

		public function send_otp($request){
			$conn = $this->connect("ccc_central");
			$user = $conn->query("SELECT id FROM user WHERE type = 'E' AND signup = '0' AND mobile = '{$request['mobile']}'");
			if($user->num_rows){
				$user_data = $user->fetch_assoc();
				$response = ['data'=>['userid'=>$user_data['id'],'signup'=>0], 'error'=>0,'error_msg'=>'','message'=>''];
			}
			else{
				$smscnt = 0;
				$contacts = array();
				$senderID = "PAPAFT";
				$from = "noreply@waev.in";
				$from_name = "Papa Fit";
				$subject = "Your login OTP is here";
				$bodyToAdmin = "";
				$username = "meetpapafit@gmail.com";
				$password = "P@sSw0paparD";
				$mail = new mailer;
				$sms = new smsgateway;
				$recipients = array();

				$otp = $this->generate_otp();
				  			
			  	if($request['signup'] == 1){

			 		$smsBody = "Dear customer, ".$otp." is your OTP for Sign Up at papa.fit";
			 		array_push($contacts, $request['mobile']);
			 		$bodyToUsers = "Welcome to papa.fit, <br><br><br> Your OTP for verification at papa.fit is <strong>".$otp."</strong>";
			 		array_push($recipients, $request['email']);	
			  	}
			 	else if($request['signup'] == 0){

			 		$bodyToUsers = "Welcome to papa.fit, <br> <br> <br> Your login OTP is : <strong>".$otp."</strong>";
			 		array_push($recipients, $request['email']);	
			 		$smsBody = "Dear customer, ".$otp." is your OTP for verification at papa.fit  ";
			 		array_push($contacts, $request['mobile']);
			 		
			 	}
			 	
			    $sms_success = $sms->sendSms($senderID,$contacts,$smsBody);
			    $res = $mail->sendMail($recipients,"","",$from,$from_name,$subject,$bodyToUsers,$username,$password,false);
				foreach ($sms_success as $key => $sms)
				   	if($sms)
				   		$smscnt ++;

			    if($smscnt == count($contacts)){

				  	if($request['signup'] == 1) {
				  		if ($conn->query("SELECT `id` FROM `otp_backup` WHERE `email` = '{$request['email']}' AND `signup` = '1'")->num_rows) {
							$conn->query("UPDATE `otp_backup` SET `mobile` = '{$request['mobile']}', `otp` = '{$otp}',`name` = '{$request['name']}', `created_at` = '".date('Y-m-d H:i:s')."' WHERE `email` = '{$request['email']}' AND `signup` = '1'");		
				  		} else {
					  		$insert_into_temp = $conn->query("INSERT INTO otp_backup(mobile,otp,name,email,signup,created_at) VALUES('{$request['mobile']}','{$otp}','{$request['name']}','{$request['email']}','1','".date('Y-m-d H:i:s')."')");
					  	}
				  	}
				  	else
				  		$insert_into_temp = $conn->query("INSERT INTO otp_backup(mobile,otp,name,email,signup,created_at) VALUES('{$request['mobile']}','{$otp}','{$request['name']}','{$request['email']}','0','".date('Y-m-d H:i:s')."')");

			      	$response['data'] = ['signup'=>1];
			      	$response['data']['otp'] = $otp;
					$response['error'] = 0;
					$response['error_msg'] = '';
					$response['message'] = '';
			    }
			    else{
			    	$response['data'] = array();
					$response['error'] = 1;
					$response['error_msg'] = 'something went wrong';
					$response['message'] = 'please try again';
			    }
			}
			$conn->close();	
		    return json_encode($response);
		}

		private function generate_otp(){
			$otp = 0;
			for($i = 0; $i < 5; $i++){
		  		$num = mt_rand(1000, 9999);
		  		if(strlen((string)$num) == 4){
		  			$otp = $num;
		  			break;
		  		}
		  		else
		  			continue;
		  	}

			return $otp;
		}

		public function check_credential($request){
            require_once __DIR__.'/../classes/services/ModuleCredentials.php';
            $connection=$this->connect('ccc_central');$identity=ModuleCredentials::login($connection,$request);
            if(!$identity)return json_encode(['error'=>1,'error_msg'=>'INVALID_CREDENTIALS','data'=>[],'message'=>'Check your credentials and tenant selection']);
            return json_encode($this->getData($connection,$identity));


			$conn = $this->connect("ccc_central");
			
			$result = $conn->query("SELECT `u`.`id`,`u`.`admin`,`u`.`chat_id`,`u`.`fname`,`u`.`contact_id`,`u`.`position_id`,`us`.`image_url`,`u`.`lname`,`cd`.`db_name`,`cd`.`inst_name`,`cd`.`logo`,`u`.`client_id`, `u`.`is_first_login`, `u`.`type` AS `user_type` FROM user AS u LEFT JOIN user_social AS us ON `u`.`id` = `us`.`user_id` INNER JOIN client_db AS cd ON `u`.`client_id` = `cd`.`id` WHERE (`u`.`mobile` = '{$request['username']}' ) AND (`u`.`password` = '{$request['password']}' OR `u`.`mpin` = '{$request['password']}') AND `u`.`country_code` = '{$request['country_code']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' ");
			
			
			
			$row_cnt = $result->num_rows;
			if($row_cnt && !empty($request['password']) && !empty($request['username'])){
				$row = $result->fetch_assoc();
				if (isset($request['user_type']) && !empty($request['user_type'])) {
					if ($request['user_type'] != $row['user_type']) {
						return json_encode(['error'=>'1','message'=>'Invalid Login','user_type'=>$request['user_type'] .' '.$row['user_type']]);
					}
				}
				// if ($row['client_id'] == '351') {
				// 	return json_encode(['error'=>'1','message'=>'Login not allowed']);
				// }
				// if (isset($request['user_type']) && !empty($request['user_type']) && $request['user_type'] != $row['user_type']) {
					// 	$response['data'] = array();
					// 	$response['error'] = 1;
					// 	$response['error_msg'] = 'Invalid credentials';
					// 	$response['message'] = 'Please check your credentials';
					// } else {
						if(!empty($request['oauth'])){
							$result_user_social = $conn->query("INSERT INTO user_social (email, gender,social_type,image_url,user_id,social_id) VALUES('{$request['oauth']['email']}', '{$request['oauth']['gender']}','{$request['oauth']['type']}', '{$request['oauth']['profileUrl']}',{$row['id']},'{$request['oauth']['id']}')");
						$row['image_url'] = $request['oauth']['profileUrl'];
					}
					
					// if($row_cnt['user_type'] == 'E')
					// 	$response = $this->emp_data($conn,$row);
					// else if($row_cnt['user_type'] == 'M'){
					// 	$response = $this->memb_data($conn,$row);
					// }
					$response=$this->getData($conn, $row);
				// }
	 		}
	 		else{
	 			$response['data'] = array();
				$response['error'] = 1;
				$response['error_msg'] = 'Invalid credentials';
				$response['message'] = 'Please check your credentials';
	 		}
	 		$conn->close();
			return json_encode($response);	
		}

		public function verify_MobileEmail($request){
			$conn = $this->connect("ccc_central");

			$result = $conn->query("SELECT `u`.`mpin`,`u`.`mobile`,`u`.`id`,`u`.`signup` ,`u`.`password` FROM user AS u LEFT OUTER JOIN user_social AS us ON `u`.`id` = `us`.`user_id` WHERE (`us`.`email` = '{$request['existingUsername']}' OR `u`.`mobile` = '{$request['existingUsername']}') AND `u`.`type` = '{$request['user_type']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' ");

			$row_cnt = $result->num_rows;
			$row = $result->fetch_assoc();

			if($row_cnt){
				if(!empty($row['mpin']) || !empty($row['password'])){
					$response['data'] = array();
					$response['data']['hasPassword'] = 1;
					$response['data']['signup'] = $row['signup'];
					$response['data']['mobile'] = $row['mobile'];
					$response['data']['userid'] = $row['id'];		
					$response['error'] = 0;
					$response['error_msg'] = '';
					$response['message'] = '';
				}
				else{
					$response['data'] = array();
					$response['data']['hasPassword'] = 0;
					$response['data']['signup'] = $row['signup'];
					$response['data']['mobile'] = $row['mobile'];
					$response['data']['userid'] = $row['id'];
					$response['error'] = 0;
					$response['error_msg'] = '';
					$response['message'] = '';
				}
	 		}
	 		else{
	 			$response['data'] = array();
				$response['error'] = 1;
				$response['error_msg'] = '';
				$response['message'] = '';
	 		}
	 		$conn->close();
			return json_encode($response);
		}

		public function reset_credentials($request){
            return json_encode(['error'=>1,'error_msg'=>'RESET_PROOF_REQUIRED','data'=>[],'message'=>'Use the verified password reset link to change credentials']);

			$conn = $this->connect("ccc_central");
			if(!empty($request['mpin']) || !empty($request['password'])){
				if(empty($request['mpin']))
					$update_credentials = $conn->query("UPDATE user SET  `password`='{$request['password']}' WHERE `mobile`='{$request['mobile']}' AND type = '{$request['user_type']}' ");
				else if(empty($request['password']))
					$update_credentials = $conn->query("UPDATE user SET `mpin`='{$request['mpin']}' WHERE `mobile`='{$request['mobile']}' AND type = '{$request['user_type']}' ");
				else
					$update_credentials = $conn->query("UPDATE user SET `mpin`='{$request['mpin']}', `password`='{$request['password']}' 
													WHERE `mobile`='{$request['mobile']}' AND type = '{$request['user_type']}' ");

				if($update_credentials){
					$response['data'] = array();
					$response['error'] = 0;
					$response['error_msg'] = '';
					$response['message'] = 'Credentials updated';
				}
				else{
					$response['data'] = array();
					$response['error'] = 1;
					$response['error_msg'] = 'something went wrong';
					$response['message'] = 'Please try again';
				}
			}
			else
				$response = ['data'=>[],'error'=>1,'error_msg'=>'invalid data', 'message'=>'please provide valid data'];

			$conn->close();
			return json_encode($response);
		}

		public function config($conn,$client_id,$user_id,$bid,$user_type='E'){		

			$config = array();
			$data = array();

			$result = $conn->query("SELECT `cm`.`module_id`,`up`.`permission`,`e`.`target`,`e`.`id` as `element_id`,`e`.`mobile_id`,`e`.`mobile_icon`,`e`.`type`,`e`.`hide`,`e`.`parent_id`,`e`.`name`,`e`.`position`,`e`.`url`,`e`.`report_id`,`e`.`icon` FROM `client_module` AS `cm` INNER JOIN `element_view` AS `e` ON (`e`.`module_id` = `cm`.`module_id` AND (`e`.`client_id` = `cm`.`client_id` OR `e`.`client_id` IS NULL)) INNER JOIN `user_permission` AS `up` ON `cm`.`id` = `up`.`cm_id` WHERE `cm`.`client_id` = '{$client_id}' AND `up`.`user_id` = '{$user_id}' AND `up`.`permission` <> '0' AND `up`.`bid` = '{$bid}' AND `cm`.`active` = '1' AND `e`.`park` = 0 AND `e`.`user_type` = '{$user_type}' ORDER BY `e`.`position` ASC ");
			
				while ($row = $result->fetch_assoc())
					array_push($data,$row);
					
				$tree = $this->parseTree($data);

				foreach ($tree as $key => $record)
					array_push($config,$record);

			return $config;
		}


		public function permissions($conn,$client_id, $user_id,$bid){
			$permissions = array();

			$result = $conn->query("SELECT `cm`.`module_id`,`up`.`permission`, `cm`.`module`, `cm`.`abbreviation` FROM `client_module` AS `cm` INNER JOIN `user_permission` AS `up` ON `cm`.`id` = `up`.`cm_id` WHERE `cm`.`client_id` = '{$client_id}' AND `up`.`user_id` = '{$user_id}' AND `up`.`bid` = '{$bid}' AND `cm`.`active` = '1'");

			while($row = $result->fetch_assoc())
				array_push($permissions,$row);

			return $permissions;
		}
		
		public function parseTree($tree,$root = 0,$permission = 3) {
		    $child = array();
		    foreach($tree as $key => $parent) {
		        if($parent['parent_id'] == $root ) {
		            unset($tree[$key]);
		            // ADR-029: Batch Finder is non-functional; suppress it from the navigation tree
		            if(($parent['url'] ?? '') === '#modal-batch_finder' || ($parent['name'] ?? '') === 'Batch Finder' || (int)($parent['module_id'] ?? 0) === 61) {
		                continue;
		            }
		            // Parked: Product under settings dropdown unless requested
		            if(($parent['url'] ?? '') === 'product.php' || ($parent['name'] ?? '') === 'Product' || (int)($parent['module_id'] ?? 0) === 41) {
		                continue;
		            }
		           if($permission > 1){
			            $child[] = array(
			            	'parent_id' => $parent['parent_id'],
			                'module_id' => $parent['module_id'],
			                'element_id' => $parent['element_id'],
			                'type' => $parent['type'],
			                'name' => $parent['name'],
			                'position' => $parent['position'],
			                'permission' => $parent['permission'],
			                'url' => $parent['url'],
			                'report_id' => $parent['report_id'],
			                'icon' => $parent['icon'],
			                'mobile_icon'=>$parent['mobile_icon'],
			                'mobile_id'=>$parent['mobile_id'],
			                'hide'=>$parent['hide'],
			                'target' => $parent['target'],
			                'sub' => $this->parseTree($tree, $parent['element_id'],$parent['permission'])
			            );
			        }        
		        }
		    }
		    return empty($child) ? [] : $child;    
		}

		public function update_device($conn,$userid){
            $token=bin2hex(random_bytes(32));$device=(string)get_device();if(strlen($device)>128)return '';
            $agent=(string)get_device_name();$name=preg_match('/Android/',$agent)?'Android Mobile':(preg_match('/iPhone|iPad/',$agent)?'iOS':'Browser');$uid=(int)$userid;$ip=(string)($_SERVER['REMOTE_ADDR']??'');
            $stmt=$conn->prepare('SELECT id FROM user_device WHERE device_id=? AND device=? AND user_id=?');$stmt->bind_param('ssi',$device,$name,$uid);$stmt->execute();$row=$stmt->get_result()->fetch_assoc();$stmt->close();
            if($row){$id=(int)$row['id'];$stmt=$conn->prepare('UPDATE user_device SET access_token=?,last_time=current_log,current_log=NOW(),last_ip=current_ip,current_ip=?,logout=0,park=0 WHERE id=? AND user_id=?');$stmt->bind_param('ssii',$token,$ip,$id,$uid);}
            else{$stmt=$conn->prepare('INSERT INTO user_device(user_id,device_id,device,current_log,current_ip,access_token) VALUES(?,?,?,NOW(),?,?)');$stmt->bind_param('issss',$uid,$device,$name,$ip,$token);}
            $stmt->execute();$stmt->close();return $token;
        }

        public function genToken($conn) {
			$token = bin2hex(random_bytes(16));
			if ($conn->query("SELECT `id` FROM `user_device` WHERE `access_token` = '{$token}'")->num_rows) {
				return $this->genToken($conn);
			}
			return $token;
		}
		
		public function check_credential_app($request){
            require_once __DIR__.'/../classes/services/ModuleCredentials.php';
            $connection=$this->connect('ccc_central');$identity=ModuleCredentials::login($connection,$request);
            if(!$identity)return json_encode(['error'=>1,'error_msg'=>'INVALID_CREDENTIALS','data'=>[],'message'=>'Check your credentials and tenant selection']);
            return json_encode($this->getDataApp($connection,$identity));


			$conn = $this->connect("ccc_central");

			$result = $conn->query("SELECT `u`.`id`,`u`.`admin`,`u`.`chat_id`,`u`.`fname`,`u`.`contact_id`,`u`.`position_id`,`us`.`image_url`,`u`.`lname`,`cd`.`db_name`,`cd`.`inst_name`,`cd`.`logo`,`u`.`client_id`, `u`.`is_first_login`, `u`.`type` AS `user_type` FROM user AS u LEFT JOIN user_social AS us ON `u`.`id` = `us`.`user_id` INNER JOIN client_db AS cd ON `u`.`client_id` = `cd`.`id` WHERE (`u`.`mobile` LIKE '%{$request['username']}%' ) AND (`u`.`password` = '{$request['password']}' OR `u`.`mpin` = '{$request['password']}') AND `u`.`country_code` = '{$request['country_code']}' AND `u`.`park` = '0' AND `u`.`inactive` = '0' ");

			

			$row_cnt = $result->num_rows;
			if($row_cnt && !empty($request['password']) && !empty($request['username'])){
				$row = $result->fetch_assoc();
				if (isset($request['user_type']) && !empty($request['user_type'])) {
					if ($request['user_type'] != $row['user_type']) {
						return json_encode(['error'=>'1','message'=>'Invalid Login','user_type'=>$request['user_type'] .' '.$row['user_type']]);
					}
				}
				// if ($row['client_id'] == '351') {
				// 	return json_encode(['error'=>'1','message'=>'Login not allowed']);
				// }
				// if (isset($request['user_type']) && !empty($request['user_type']) && $request['user_type'] != $row['user_type']) {
				// 	$response['data'] = array();
				// 	$response['error'] = 1;
				// 	$response['error_msg'] = 'Invalid credentials';
				// 	$response['message'] = 'Please check your credentials';
				// } else {
					if(!empty($request['oauth'])){
						$result_user_social = $conn->query("INSERT INTO user_social (email, gender,social_type,image_url,user_id,social_id) VALUES('{$request['oauth']['email']}', '{$request['oauth']['gender']}','{$request['oauth']['type']}', '{$request['oauth']['profileUrl']}',{$row['id']},'{$request['oauth']['id']}')");
						$row['image_url'] = $request['oauth']['profileUrl'];
					}
					
					// if($row_cnt['user_type'] == 'E')
					// 	$response = $this->emp_data($conn,$row);
					// else if($row_cnt['user_type'] == 'M'){
					// 	$response = $this->memb_data($conn,$row);
					// }
					$response=$this->getDataApp($conn, $row);
				// }
	 		}
	 		else{
	 			$response['data'] = array();
				$response['error'] = 1;
				$response['error_msg'] = 'Invalid credentials';
				$response['message'] = 'Please check your credentials';
	 		}
	 		$conn->close();
			return json_encode($response);	
		}

		private function getDataApp($conn,$row,$start_session = 1,$error_no = 0) {
			error_reporting(1);
			$home_page = "/pf/dashboard.php";
			if ($row['user_type'] == 'M' || $row['user_type']=='F') {
				$home_page = '/pf/summary.php?contact='.$row['contact_id'];
			}
			$date = date('Y-m-d');
			$token = $this->update_device($conn,$row['id']);
			$branch = array();
			$client_cont = $conn->query("SELECT contact_id FROM client_member WHERE client_id = '{$row['client_id']}'")->fetch_assoc(); 
			$conn1 = $this->connect($row['db_name']);
			$studio = $conn1->query("SELECT studio FROM studio ")->fetch_assoc();
			$employee_details = $conn1->query("SELECT `position_id`,`rec_dir` FROM `employee` WHERE `contact_id` = '{$row['contact_id']}'")->fetch_assoc();
			$studio_sett = $conn1->query("SELECT `inter_branch_member`, `just_dial`, `iot_implement`, `pushapp_dashboard` FROM `studio_setting`")->fetch_assoc();

			$contact = $conn1->query("SELECT image, rfid, carrot, gender, mobile, email, `code`  FROM member_contact_view WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();

			$active = $conn->query("SELECT MAX(`end`) AS `till` FROM `member_app_subscription` WHERE `contact_id` = '{$row['contact_id']}' AND `client_id` = '{$row['client_id']}'")->fetch_assoc();
			if (!isset($active['till']) || empty($active['till']) || is_null($active['till'])) {
				$active['till'] = '';
			}
			if ($active['till'] != '' && strtotime($active['till']) >= strtotime(date('Y-m-d'))) {
				$active['is_active'] = '1';
			} else {
				$active['is_active'] = '0';
			}
			// echo "SELECT image, rfid, carrot  FROM member_contact_view WHERE id = '{$row['contact_id']}'";
			$image_url = "https://admin.creativeculturalcentre.in/content/contact_images/";

			$client = $conn1->query("SELECT `b`.`id`, `b`.`branch`, `b`.`timezone`, `b`.`type` FROM `{$row['db_name']}`.branch AS `b` INNER JOIN `ccc_central`.`user_bid` AS `ub` ON `ub`.`bid`=`b`.`id`  WHERE `ub`.`user_id` = '{$row['id']}'  ORDER BY  `b`.`is_headquaters` DESC,`b`.`branch` ASC");

			//Get Equipments
			$equipment_arr = array();
			$equipments = $conn1->query("SELECT `equipment_id`, `equipment` FROM `equipment_view` ORDER BY `equipment_id` ASC");
			while($equipment = $equipments->fetch_assoc()) {
				array_push($equipment_arr, $equipment);
			}

			//Get Assignments
			$assignment_arr = array();
			$batch_module_arr = array();
			if ($row['user_type'] == 'F') {
				$assignment_sql = $conn1->query("SELECT DISTINCT `assignment_id` FROM `session_batch_view` WHERE `contact_id` = '{$row['contact_id']}' AND `check_assignment` = '1'");
				while($assignment = $assignment_sql->fetch_assoc()) {
					array_push($assignment_arr, $assignment['assignment_id']);
				}
				$batch_module_sql = $conn1->query("SELECT DISTINCT `batch_id`, `module_id` FROM `session_batch_view` WHERE `contact_id` = '{$row['contact_id']}'");
				while ($batch_module = $batch_module_sql->fetch_assoc()) {
					array_push($batch_module_arr, $batch_module);
				}
			}

			while ($branch_data = $client->fetch_assoc()) 
				array_push($branch, $branch_data);
			$assessment = ['weight'=>0];
			$meals_arr = [];

			error_reporting(1);
			//Get levels
			$level_data = [];
			$sql_levels = $conn1->query("SELECT `cl`.`component_id`, `cl`.`level`, `c`.`gender` FROM `contact_component_level` `cl` join `contact` `c` on (`cl`.`contact_id` = `c`.`id`) WHERE `cl`.`contact_id` = '{$row['contact_id']}'");
			if ($sql_levels) {
				while ($level = $sql_levels->fetch_assoc()) {
					//Get Component and level
					if ($level['component_id'] == 1) { // Strength
						$level_data = $conn->query("SELECT `description` AS `level`, `image` FROM `ccc_central`.`level` WHERE `level` = '{$level['level']}' AND `component_id` = '{$level['component_id']}' AND `gender` = '{$contact['gender']}'")->fetch_assoc();

					}
				}
			}
			if (empty($level_data)) {
				$level_data = ['level'=>' - ', 'image'=>''];
			}

			//Get App Version data
			$app_type = $row['user_type'] == 'M' ? 0 : 1; //Member app : Trainer App {Not decided for admin app}
			$version = $conn->query("SELECT `ios`, `android`, `urgent` FROM `app_ver` WHERE `app_type` = '{$app_type}' ORDER BY `launch_date` DESC, `id` DESC LIMIT 1")->fetch_assoc();

			$permissions = $this->permissions($conn,$row['client_id'],$row['id'],$branch[0]['id']);
			$config = $this->config($conn,$row['client_id'],$row['id'],$branch[0]['id'],$row['user_type']); 

			$emp = $conn1->query("SELECT id, doj FROM employee WHERE contact_id = '{$row['contact_id']}'")->fetch_assoc();
			if($start_session){
				session_start();
				$_SESSION = ['config'=>$config,'permissions'=>$permissions,'firstLogin'=>0,'db_name'=>$row['db_name'],'bid'=>$branch[0]['id'],'timezone'=>$branch[0]['timezone'],'client_id'=>$row['client_id'],'user_id'=>$row['id'],'admin'=>$row['admin'],'user_name'=>$row['fname'].' '.$row['lname'],'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],'session_id'=>session_id(),'inst_name'=>$studio['studio'],'just_dial'=>$studio_sett['just_dial'], 'pushapp_dashboard'=>$studio_sett['pushapp_dashboard'],'inter_branch_member'=>$studio_sett['inter_branch_member'],'branch'=>$branch,'position_id'=>$employee_details['position_id'],'rec_dir'=>$employee_details['rec_dir'],'employee_id'=>$emp['id'],'doj'=>$emp['doj'],'token'=>$token,'contact_id'=>$row['contact_id'],'client_contact_id'=>$client_cont['contact_id'], 'is_first_login'=>$row['is_first_login'],'equipments'=>$equipment_arr,'user_type'=>$row['user_type'],'home_page'=>$home_page,'weight'=>($row['user_type'] == 'M') ? (int)$assessment['weight'] : "0",'iot_implement'=>$studio_sett['iot_implement'],'rfid'=>$contact['rfid'],'code'=>$contact['code'],'meals'=>($row['user_type'] == 'M') ? $meal_arr : [], 'carrot'=>($row['user_type'] == 'M') ? $contact['carrot']:'0', 'level'=>$level_data, 'active'=>($row['user_type'] == 'M') ? ['is_active'=>$active['is_active'],'active_till'=>$active['till']] : [],'assignment'=>$assignment_arr,'batch_module'=>$batch_module_arr, 'version'=>$version,'mobile'=>$contact['mobile'],'email'=>$contact['email']];
				$_SESSION['auth_epoch']=(int)$conn->query('SELECT auth_epoch FROM user WHERE id='.(int)$row['id'])->fetch_row()[0];
                session_commit();
				set_branch_type();
			}
			$response = ['data'=>
			['config'=>$config,
			'permissions'=>$permissions,
			'firstLogin'=>0,'token'=>$token,
			'client_id'=>$row['client_id'],
			'bid'=>$branch[0]['id'],
			'admin'=>$row['admin'],
			'user_id'=>$row['id'],
			'session_id'=>session_id(),
			'user_name'=>$row['fname'].' '.$row['lname'],
			'user_image'=>!empty($contact['image']) ? $image_url.$contact['image'] : './img/favicon.png','inst_logo'=>$row['logo'],
			'inst_name'=>$studio['studio'],
			'just_dial'=>$studio_sett['just_dial'],
			'pushapp_dashboard'=>$studio_sett['pushapp_dashboard'],
			'inter_branch_member'=>$studio_sett['inter_branch_member'],
			'branch'=>$branch,
			'position_id'=>$employee_details['position_id'],
			'rec_dir'=>$employee_details['rec_dir'],
			'employee_id'=>$emp['id'],
			'contact_id'=>$row['contact_id'],
			'client_contact_id'=>$client_cont['contact_id'], 
			'is_first_login'=>$row['is_first_login'],
			'equipments'=>$equipment_arr,
			'user_type'=>$row['user_type'],
			'home_page'=>$home_page,
			'weight'=>($row['user_type'] == 'M') ? (int)$assessment['weight'] : "0",'iot_implement'=>$studio_sett['iot_implement'],
			'rfid'=>$contact['rfid'],
			'code'=>$contact['code'],
			'meals'=>($row['user_type'] == 'M') ? $meal_arr : [], 
			'carrot'=>($row['user_type'] == 'M') ? $contact['carrot']:'0', 
			'level'=>$level_data,  'active'=>($row['user_type'] == 'M') ? ['is_active'=>$active['is_active'],'active_till'=>$active['till']] : [],'assignment'=>$assignment_arr,
			'batch_module'=>$batch_module_arr, 
			'version'=>$version,
			'mobile'=>$contact['mobile'],
			'email'=>$contact['email']
			],
			'error'=>$error_no,
			'error_msg'=>'',
			'message'=>''];

			return $response;
		}


	} 

    
?>