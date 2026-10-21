<?php
	// error_reporting(1);
	require_once '../classes/dbconnection.class.php';
	require_once '../classes/querybuilder.class.php';
	require_once '../classes/session.class.php';
	require_once '../classes/check_user_subscription.class.php';
	require_once '../classes/services/AttendanceDomainService.php';
	require_once "../inc/defaults.php";
	require_once "../controllers/notification.php";

	class attendance extends dbconnection{
		private $response = array();

		private function recordBatchMemberAttendance($conn, array $contact, array $setting): array
		{
			$datetime = isset($contact['datetime']) ? $contact['datetime'] : date('Y-m-d H:i:s');
			$memberId = isset($contact['contact_id']) ? (int)$contact['contact_id'] : 0;
			$sessionId = isset($contact['session_id']) ? (int)$contact['session_id'] : 0;
			$attemptId = isset($contact['client_attempt_id']) && $contact['client_attempt_id'] !== ''
				? $contact['client_attempt_id']
				: 'manual:' . $memberId . ':' . $contact['batch_id'] . ':' . date('Y-m-d', strtotime($datetime)) . ':' . $sessionId . ':' . (isset($contact['type']) ? $contact['type'] : 'M');

			$command = [
				'branch_id' => isset($contact['bid']) ? $contact['bid'] : 0,
				'batch_id' => isset($contact['batch_id']) ? $contact['batch_id'] : 0,
				'attendance_date' => date('Y-m-d', strtotime($datetime)),
				'session_id' => $sessionId,
				'member_id' => $memberId,
				'person_type' => isset($contact['type']) ? $contact['type'] : 'M',
				'negative' => isset($contact['negative']) ? $contact['negative'] : 0,
				'source' => isset($contact['source']) ? $contact['source'] : 'MANUAL',
				'event_type' => 'PUNCH_IN',
				'actor_employee_id' => isset($contact['user_id']) ? $contact['user_id'] : get_uid(),
				'client_attempt_id' => $attemptId,
				'code' => isset($contact['code']) ? $contact['code'] : '',
				'attendance_time' => date('H:i:s', strtotime($datetime)),
				'item_id' => isset($contact['item_id']) ? $contact['item_id'] : 0,
				'attendance_session' => isset($contact['session']) ? $contact['session'] : 0,
				'deduct_session_absentee' => isset($contact['deduct_session_absentee']) ? $contact['deduct_session_absentee'] : 0,
				'check_month' => !empty($contact['check_month']),
				'row_color' => isset($contact['row_color']) ? $contact['row_color'] : 0,
				'comment' => isset($contact['comment']) ? $contact['comment'] : '',
			];

			$result = (new AttendanceDomainService($conn))->recordMemberAttendance($command);
			if ($result['state'] === 'RECORDED') {
				// Renewal dates are explicit package operations, not an attendance side effect.
				return [
					'data' => ['bal' => '0', 'state' => $result['state'], 'attendance_id' => $result['attendance_id']],
					'error' => 0,
					'error_msg' => '',
					'message' => 'Attendance punched',
				];
			}

			if ($result['state'] === 'ALREADY_MARKED') {
				return [
					'data' => ['bal' => '0', 'state' => $result['state'], 'attendance_id' => $result['attendance_id']],
					'error' => 1,
					'error_msg' => 'attendance already punched',
					'message' => 'Already punched,Please try again',
				];
			}

			$isDatePolicy = isset($result['reason']) && $result['reason'] === 'DATE_POLICY';
			return [
				'data' => ['bal' => '0', 'state' => $result['state']],
				'error' => $isDatePolicy ? 3 : 1,
				'error_msg' => $isDatePolicy ? 'No priviliges' : ($result['reason'] ?? 'attendance not allowed'),
				'message' => $result['message'] ?? 'Attendance was not recorded',
			];
		}
		public function check_attendance($request,$conn)
		{
			$session_id = isset($request['session_id']) && $request['session_id'] !== '' ? $request['session_id'] : 0;
			$attendance = $conn->query("SELECT `id` from attendance where `contact_id`='{$request['contact_id']}' AND `date`='{$request['date']}' AND `batch_id`='{$request['batch_id']}' AND `session_id`='{$session_id}' AND `type`= 'M' AND `park`='0'");
			if($attendance->num_rows)
			{
				return false;
			}
			else
			{ 
				return true;
			}

		}
		public function check_attendance_app($request,$conn)
		{
			$session_id = isset($request['session_id']) && $request['session_id'] !== '' ? $request['session_id'] : 0;
			$attendance = $conn->query("SELECT `id` from attendance where `contact_id`='{$request['contact_id']}' AND `date`='{$request['date']}' AND (`batch_id`='{$request['batch_id']}' OR `type`= 'M') AND `session_id`='{$session_id}' AND `park`='0'");
			if($attendance->num_rows)
			{
				return false;
			}
			else
			{ 
				return true;
			}

		}

		public function insert_attendance($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),2)){
					$first_attendance = 0;	
					$conn = $this->connect($dbname);
					$request['user_id'] = get_uid();
					$request['status'] = '1';
					if (!isset($request['deduct_session_absentee']) || $request['deduct_session_absentee'] === '') {
						$request['deduct_session_absentee'] = 1;
					}
					$att_settings = $conn->query("SELECT punch_dif,bio_extension,bio_type FROM attendance_settings WHERE bid = '{$request['bid']}'")->fetch_assoc();
					//Get inter branch member from setting 
					$studio_sett = $conn->query("SELECT `inter_branch_member` FROM `studio_setting`")->fetch_assoc();
					$reqCode = trim((string)($request['code'] ?? ""));
					$reqCid = (int)($request['contact_id'] ?? 0);
					$reqBid = (int)($request['bid'] ?? 0);
					$codeEsc = $conn->real_escape_string($reqCode);
					$memberMatch = "";
					if ($reqCode !== "" && $reqCid > 0) {
						$memberMatch = "(`code` = '{$codeEsc}' OR `contact_id` = '{$reqCid}')";
					} elseif ($reqCode !== "") {
						$memberMatch = "`code` = '{$codeEsc}'";
					} elseif ($reqCid > 0) {
						$memberMatch = "`contact_id` = '{$reqCid}'";
					}
					$empMatch = "";
					if ($reqCode !== "" && $reqCid > 0) {
						$empMatch = "(`ecode` = '{$codeEsc}' OR `contact_id` = '{$reqCid}')";
					} elseif ($reqCode !== "") {
						$empMatch = "`ecode` = '{$codeEsc}'";
					} elseif ($reqCid > 0) {
						$empMatch = "`contact_id` = '{$reqCid}'";
					}
					if ($memberMatch !== "" && $studio_sett['inter_branch_member'] == 1) {
						$user = $conn->query("SELECT `bid` FROM member_contact_view WHERE {$memberMatch} AND park = '0'")->fetch_assoc();
						if (isset($user['bid']) && !empty($user['bid'])) {
							$request['bid'] = $user['bid'];
							$request['type'] = 'E'; 
						}
					}
					$user = null;
					$isMemberPunch = isset($request['type']) && $request['type'] === 'M';
					if ($isMemberPunch && $memberMatch !== "") {
						$user = $conn->query("SELECT `id`,`contact_id`,`fullname`,`code` FROM member_contact_view WHERE {$memberMatch} AND bid = '{$request['bid']}' AND status = '1' AND park = '0'")->fetch_assoc();
					}
					if(!$isMemberPunch && $empMatch !== "" && ($user = $conn->query("SELECT `id`,`contact_id`,`fullname` FROM emp_cont_view WHERE {$empMatch} AND bid = '{$request['bid']}' AND park = '0' ")->fetch_assoc())){
						$request['contact_id'] = $user['contact_id'];
						$request['fullname'] = $user['fullname'];
						$request['row_color'] = '1';
						$response = $this->punch_attendance($conn,$request,$att_settings);
					}
					else if($user || ($memberMatch !== "" && ($user = $conn->query("SELECT `id`,`contact_id`,`fullname`,`code` FROM member_contact_view WHERE {$memberMatch} AND bid = '{$request['bid']}' AND status = '1' AND park = '0'")->fetch_assoc()))){
						$request['contact_id'] = $user['contact_id'];
						$request['type'] = 'M';
						$request['fullname'] = $user['fullname'];
						if (empty($request['code']) && !empty($user['code'])) {
							$request['code'] = $user['code'];
						}
						$member = new check_user_subscription;
						$to_date = $member->get_date($conn,$request['contact_id'],$request['code'],$att_settings['bio_extension']);
						if(!empty($to_date)) {
							//Check for batch id != 0 and item id = 0
							if ($request['batch_id'] != 0 && !empty($request['batch_id']) && $request['item_id'] == 0) {
								$request = $this->getInvoiceItemFromBatch($request, $conn);
							}
							if(($request['batch_id'] != 0 && !empty($request['batch_id'])) && ($request['item_id'] != 0 && !empty($request['item_id']))){
								// Check if batch session is cancelled or rescheduled on this date
								$attDateOnly = date('Y-m-d', strtotime($request['datetime'] ?? 'now'));
								$batchIdEsc = (int)$request['batch_id'];
								$cancelledBatch = $conn->query("SELECT id, status FROM attendance WHERE batch_id = '{$batchIdEsc}' AND date = '{$attDateOnly}' AND contact_id = '0' AND status IN ('2','3') AND type = 'B' AND park = '0' LIMIT 1")->fetch_assoc();
								if ($cancelledBatch) {
									$msg = (int)$cancelledBatch['status'] === 3 ? 'Batch session is rescheduled on this date' : 'Batch session is cancelled on this date';
									return json_encode(['data' => [], 'error' => 1, 'error_msg' => 'batch is cancelled', 'message' => $msg]);
								}

								// Check batch schedule day of week
								$req_day_code = (int)date('w', strtotime($request['datetime'] ?? 'now'));
								$batch_times = $conn->query("SELECT `day_code` FROM `batch_time` WHERE `batch_id` = '{$request['batch_id']}'");
								$has_schedule = ($batch_times && $batch_times->num_rows > 0);
								$is_day_matched = false;
								$scheduled_days = [];
								if ($has_schedule) {
									while ($bt = $batch_times->fetch_assoc()) {
										$scheduled_days[] = (int)$bt['day_code'];
										if ((int)$bt['day_code'] === $req_day_code) {
											$is_day_matched = true;
										}
									}
								}
								$is_override = !empty($request['override_schedule']) || !empty($request['allow_makeup']) || !empty($request['makeup']) || !empty($request['extra_class']);
								if ($has_schedule && !$is_day_matched && !$is_override) {
									$dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
									$curDayName = $dayNames[$req_day_code] ?? 'today';
									$response = [
										'data' => [
											'scheduled_days' => $scheduled_days,
											'current_day' => $req_day_code,
											'current_day_name' => $curDayName,
											'requires_override' => true
										],
										'error' => 1,
										'error_msg' => 'UNSCHEDULED_BATCH_DAY',
										'message' => "Student is not scheduled for attendance on {$curDayName}. Confirm as Makeup / Extra Class to proceed."
									];
								}
								else {
									$att_date = date('Y-m-d',strtotime($request['datetime']));
									//Select invoice_item for sessions and check for it
									$sess_check = $conn->query("SELECT (`sessions` - `sessions_used`) AS `diff` FROM `invoice_item` WHERE `id` = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}'")->fetch_assoc();
									
									//check for due payment
									$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$request['contact_id']}' AND park = 0")->fetch_assoc(); 
									//For balance
									if(!empty($due) && $due['amount'] != 0){
										$request['row_color']= '2';
										$response = $this->punch_attendance($conn,$request,$att_settings);
									 	$response['message'] = 'Welcome '.$user['fullname'].', Payment due Rs.'.$due['amount'];
									 	$response['data']['bal'] = 1;
									 	$notification = new notification;
										$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with due",'Payment Due',$request['bid'],$request['contact_id'],$request['contact_id']);
									} //For sessions done
									else if ((!empty($sess_check['diff']) || isset($sess_check['diff'])) && $sess_check['diff'] <= 0) {
										$request['row_color']= '3';
										// $request['sessions'] = 1;
										$request['negative'] = 2;
										
										$res = $this->punch_attendance($conn,$request,$att_settings);	

										$notification = new notification;
										$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with expiry",'Package Expiry',$request['bid'],$request['contact_id'],$request['contact_id']);

										$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance not allowed','message'=>'Membership expired'];
									}
									else { //For normal punch
										if ($has_schedule && !$is_day_matched && $is_override) {
											$request['comment'] = trim(($request['comment'] ?? '') . ' [Makeup / Extra Class]');
										}
										$response = $this->punch_attendance($conn,$request,$att_settings);
										if ($has_schedule && !$is_day_matched && $is_override && isset($response['error']) && $response['error'] === 0) {
											$response['message'] = 'Welcome '.$user['fullname'].', Makeup / Extra Class attendance punched';
											$response['data']['is_makeup'] = 1;
										}
									}
								}
							}
							else{

								$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$request['contact_id']}' AND `park` = 0")->fetch_assoc(); 	
								if(!empty($due) && $due['amount'] != 0){
									$request['row_color'] = '2';
									$response = $this->punch_attendance($conn,$request,$att_settings);
									$response['message'] = 'Welcome '.$user['fullname'].',Payment due Rs.'.$due['amount'];
									$response['data']['bal'] = 1;
									$notification = new notification;
									$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with due",'Payment Due',$request['bid'],$request['contact_id'],$request['contact_id']);
								}
								else
									$response = $this->punch_attendance($conn,$request,$att_settings);
							}
					
						}
						else{
							$request['row_color']= '3';
							$request['sessions'] = 0;
							$request['negative'] = 2;
							
							$res = $this->punch_attendance($conn,$request,$att_settings);	

							$notification = new notification;
							$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with expiry",'Package Expiry',$request['bid'],$request['contact_id'],$request['contact_id']);

							$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance not allowed','message'=>'Membership expired or package has not start yet'];
						}

					}
					else if($user = $conn->query("SELECT `id`,`contact_id`,`fullname` FROM member_contact_view WHERE (`code` = '{$request['code']}' OR `contact_id` = '{$request['contact_id']}') AND bid = '{$request['bid']}' AND (status = '2' OR status = '0')")->fetch_assoc()){ //Get Inactive / Ex Members
						$request['contact_id'] = $user['contact_id'];
						$request['type'] = 'M';
						$request['row_color']= '3';
						$request['sessions'] = 0;
						$request['negative'] = 2;
						
						$res = $this->punch_attendance($conn,$request, $att_settings);	
						$response['error'] = 1;
						$response['error_msg'] = 'expired ';
						$response['message'] = 'Membership expired';
						// require_once('../controllers/notification.php');
						$notification = new notification;
						$notification->create_noti($conn, 6, 6, get_uid() ,"","- {$user['fullname']} with expiry","Package expiry",$request['bid'],$request['contact_id'],$request['contact_id']);
					}
					else{
						$request['batch_id'] = 0;
						$request['sessions'] = 0;
						$request['negative'] = 3;
						$res = $this->punch_attendance($conn,$request,$att_settings);
						$response['error'] = 1;
						$response['error_msg'] = 'code not found';
						$response['message'] = 'Member is either inactive or code is invalid';
						// $notification = new notification();
						// $notification.create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with expiry",'Package Expiry',$request['bid'],$request['contact_id'],$request['contact_id']);

					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>1,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}


		public function insert_zoom_log($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				$conn = $this->connect($dbname);
				$result = $conn->query("SELECT `date`,`user_id`,`bid` FROM `users_zoom_log` WHERE `date` = '{$request['date']}'");
				if(!$result)
				{
					$insert_log = $conn->query("INSERT INTO users_zoom_log(`date`,`user_id`,`bid`) VALUES('{$request['date']}','{$request['user_id']}','{$request['bid']}')");
					
					if($insert_log)
					{
						$response['error'] = 0;
						$response['error_msg'] = 'log inserted';
						$response['message'] = 'log inserted';
					}
					else
					{
						$response['error'] = 1;
						$response['error_msg'] = 'log not inserted';
						$response['message'] = ' log not inserted';
					}
				}
				else
				{
						$response['error'] = 1;
						$response['error_msg'] = 'already inserted log';
						$response['message'] = 'You have already joined the zoom class';

				}
				$conn->close();
				return json_encode($response);
			}
		}


		private function getInvoiceItemFromBatch($request, $conn) {
			$att_date = date('Y-m-d',strtotime($request['datetime']));
			//Get Category from batch
			$category = $conn->query("SELECT `category_id` FROM `batch` WHERE `id` = '{$request['batch_id']}'")->fetch_assoc();
			//Get Invoice Item with category and active;
			if (!empty($category['category_id'])) {
				$invoice_item = $conn->query("SELECT `item_id`, `sessions` FROM `invoice_invoiceitem_view` WHERE `code` = '{$request['code']}' AND `contact_id` = '{$request['contact_id']}' AND `end_date` >= '{$att_date}' AND `start_date` <= '{$att_date}' AND (`sessions` = 0 OR (`sessions` > `sessions_used`)) AND `category_id` = '{$category['category_id']}' AND `park` = 0 AND `item_park` = 0 ORDER BY `start_date` ASC LIMIT 1")->fetch_assoc();
				if (!empty($invoice_item['item_id'])) {
					$request['item_id'] = $invoice_item['item_id'];
					$request['sessions'] = empty($invoice_item['sessions']) ? "0" : "1";
				}
			}
			return $request; 
		}

		public function insert_attendance_via_bio($request){
			// error_reporting(1);
			if (($request['sr_no'] == '0426143300408' || $request['device_id'] == '20')) {
	            if (!isset($request['controlled']))
                    return;
            }
			$first_attendance = 0;
			$conn1 = $this->connect("ccc_central");
			$client = $conn1->query("SELECT `c`.`db_name`, `b`.`bid`, `c`.`id` FROM client_db AS c INNER JOIN bio_device AS b ON `c`.`id` = `b`.`client_id` WHERE `b`.`sr_no` = '{$request['sr_no']}' OR `b`.`device_id` = '{$request['device_id']}'")->fetch_assoc();
			$admin = $conn1->query("SELECT `id` FROM `user` WHERE `client_id` = '{$client['id']}' AND `admin` = 1")->fetch_assoc();
			$conn = $this->connect($client['db_name']);
			$request['bid'] = $client['bid'];
			$att_settings = $conn->query("SELECT punch_dif,bio_extension,bio_type FROM attendance_settings WHERE bid = '{$request['bid']}'")->fetch_assoc();
			$all = [];
			
			foreach ($request['i'] as $key => $obj) {
				if (!isset($obj['datetime']) || empty($obj['datetime'])) {
					$obj['datetime'] = date("Y-m-d H:i:s");
				}
				// var_dump($obj);
				// var_dump("SELECT `id`,`contact_id` FROM emp_cont_view WHERE `ecode` = '{$obj['code']}' AND bid = '{$request['bid']}' AND park = '0' ");
				// echo "SELECT `id`,`contact_id`,`fullname` FROM member_contact_view WHERE (`code` = '{$obj['code']}') AND bid = '{$request['bid']}' AND (status = '2' OR status = '0')";
				if($user = $conn->query("SELECT `id`,`contact_id` FROM emp_cont_view WHERE `ecode` = '{$obj['code']}' AND bid = '{$request['bid']}' AND park = '0' ")->fetch_assoc()){
					$obj['contact_id'] = $user['contact_id'];
					$obj['type'] = 'E';
					$obj['bid'] = $request['bid'];
					$obj['row_color'] = '1';
					$obj['status'] = '1';
					$obj['session'] = '1';
					$obj['source'] = '2';
					$response = $this->punch_attendance($conn,$obj,$att_settings);
				}
				else if($user = $conn->query("SELECT `id`,`contact_id`,`fullname` FROM member_contact_view WHERE `code` = '{$obj['code']}' AND bid = '{$request['bid']}' AND status = '1' AND park = '0'")->fetch_assoc()){
					$obj['contact_id'] = $user['contact_id'];
					$obj['bid'] = $request['bid'];
					$obj['type'] = 'M';
					$obj['status'] = '1';
					$obj['source'] = '2';
					$member = new check_user_subscription;
					$to_date = $member->get_date($conn,$obj['contact_id'],$obj['code'],$att_settings['bio_extension']);
					if(!empty($to_date)){			
						if(($request['batch_id'] != 0 && !empty($request['batch_id'])) && ($request['item_id'] != 0 && !empty($request['item_id']))){
							$att_date = date('Y-m-d',strtotime($obj['datetime']));
							//Select invoice_item for sessions and check for it
							$sess_check = $conn->query("SELECT (`sessions` - `sessions_used`) AS `diff` FROM `invoice_item` WHERE `id` = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}'")->fetch_assoc();
							
							//check for due payment
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$obj['contact_id']}' AND `park` = 0")->fetch_assoc(); 
							
							if(!empty($due) && $due['amount'] != 0){ //For balance
								$obj['row_color'] = '2';
								$response = $this->punch_attendance($conn,$obj,$att_settings);
								$response['message'] = 'Payment due '.$due['amount'].' Rs.';
								$notification = new notification;
								$notification->create_noti($conn, 6, 6, $admin['id'] ,'',"- {$user['fullname']} with due",'Payment Due',$request['bid'],$obj['contact_id'],$obj['contact_id']);
							}
							 //For sessions done
							else if ((!empty($sess_check['diff']) || isset($sess_check['diff'])) && $sess_check['diff'] <= 0) {
								$request['row_color']= '3';
								// $request['sessions'] = 1;
								$request['negative'] = 2;

								
								$res = $this->punch_attendance($conn,$request,$att_settings);	

								$notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$user['fullname']} with expiry",'Package Expiry',$request['bid'],$request['contact_id'],$request['contact_id']);

								$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance not allowed','message'=>'Membership expired'];
							} //For normal
							else{
								$obj['session'] = 1;
								$response = $this->punch_attendance($conn,$obj,$att_settings);
							}

							
						}
						else{
							$att_date = date('Y-m-d',strtotime($obj['datetime']));
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$obj['contact_id']}' AND park = 0")->fetch_assoc(); 	
							$invoice_item = $conn->query("SELECT item_id,sessions,sessions_used,`batch_id` FROM invoice_invoiceitem_view WHERE (contact_id = '{$obj['contact_id']}' AND `code` = '{$obj['code']}') AND start_date <= '{$att_date}' and  end_date >= '{$att_date}' AND park = '0' AND sessions > sessions_used ")->fetch_assoc();
							// echo "SELECT item_id,sessions,sessions_used,`batch_id` FROM invoice_invoiceitem_view WHERE (contact_id = '{$obj['contact_id']}' AND `code` = '{$obj['code']}') AND start_date <= '{$att_date}' and  end_date >= '{$att_date}' AND park = '0' AND sessions > sessions_used ";
							if (isset($invoice_item['item_id']) && !empty($invoice_item['item_id'])) {
								$obj['item_id'] = $invoice_item['item_id'];
								if ($invoice_item['sessions'] > 0)
									$obj['session'] = 1;
								else $obj['session'] = 0;
								$obj['batch_id'] = $invoice_item['batch_id'];
							}
							if(!empty($due) && $due['amount'] != 0){
								$obj['row_color'] = '2';
								$response = $this->punch_attendance($conn,$obj,$att_settings);
								$response['message'] = 'Payment due Rs.'.$due['amount'];
								$notification = new notification;
								$notification->create_noti($conn, 6, 6, $admin['id'] ,'',"- {$user['fullname']} with due",'Payment Due',$request['bid'],$obj['contact_id'],$obj['contact_id']);
							}
							else
								$response = $this->punch_attendance($conn,$obj,$att_settings);

						}
					}
					else{
						$obj['session'] = 0;
						$obj['negative'] = 2;
						$obj['row_color'] = '3';
						$res = $this->punch_attendance($conn,$obj,$att_settings);
						$notification = new notification;
						$notification->create_noti($conn, 6, 6, $admin['id'] ,'',"- {$user['fullname']} with expiry",'Package Expiry',$request['bid'],$obj['contact_id'],$obj['contact_id']);
						$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance not allowed','message'=>'Please make payment or renew your package'];
					}

				}
				else if($user = $conn->query("SELECT `id`,`contact_id`,`fullname` FROM member_contact_view WHERE (`code` = '{$obj['code']}') AND bid = '{$request['bid']}' AND (status = '2' OR status = '0')")->fetch_assoc()){
					$obj['contact_id'] = $user['contact_id'];
					$obj['type'] = 'M';
					$obj['row_color']= '3';
					$obj['session'] = 0;
					$obj['negative'] = 2;
					$obj['bid'] = $request['bid'];
					
					$res = $this->punch_attendance($conn,$obj,$att_settings);	
					$response['error'] = 1;
					$response['error_msg'] = 'expired ';
					$response['message'] = 'Membership expired';
					// require_once('../controllers/notification.php');
					$notification = new notification;
					$notification->create_noti($conn, 6, 6, $admin['id'] ,"","- {$user['fullname']} with expiry","Package expiry",$request['bid'],$obj['contact_id'],$obj['contact_id']);
				}
				else{
					$obj['batch_id'] = 0;
					$obj['session'] = 0;
					$obj['negative'] = 3;
					$obj['bid'] = $request['bid'];
					$res = $this->punch_attendance($conn,$obj,$att_settings);
					$response['error'] = 1;
					$response['error_msg'] = 'code not found';
					$response['message'] = 'Member is either inactive or code is invalid';
				}
				
				array_push($all,$response);
			}

			$conn->close();
			$conn1->close();

			return json_encode($all);
		}

		public function insert_batch_log($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),2)){
					$first_attendance = 0;	
					$conn = $this->connect($dbname);
					$batch_data = $conn->query("SELECT `venue_id`,`batch` FROM batch WHERE id = '{$request['batch_id']}'")->fetch_assoc();

					$request['comment'] = $conn->escape_string($request['comment']);
					
					$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`batch`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`new_batch_id`,`status`,`comment`,`created_at`,`created_by`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$batch_data['venue_id']}','{$batch_data['batch']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','B','{$request['new_batch_id']}','{$request['status']}','{$request['comment']}','".date('Y-m-d H:i:s')."','".get_uid()."')");

					$response = ($insert_attendance) ? ['data'=>[],'error'=>0,'error_msg'=>'','message'=>'Batch canceled'] :  ['data'=>[],'error'=>1,'error_msg'=>'','message'=>'Something went wrong, please try again'];
					
				}
				else
					$response = ['data'=>[],'error'=>1,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];
				$conn->close();
				return json_encode($response);
			}
		}

		public function insert_batch_attendance($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),2)){
					$first_attendance = 0;	
					$conn = $this->connect($dbname);
					$att_settings = $conn->query("SELECT punch_dif,bio_extension,bio_type,deduct_session_absentee FROM attendance_settings WHERE bid = '{$request['bid']}'")->fetch_assoc();

					$batchId = (int)$request['batch_id'];
					$attDate = $conn->real_escape_string(date('Y-m-d', strtotime($request['date'])));
					$cancelled = $conn->query("SELECT id, status FROM attendance WHERE batch_id = '{$batchId}' AND date = '{$attDate}' AND contact_id = '0' AND status IN ('2','3') AND type = 'B' AND park = '0' LIMIT 1")->fetch_assoc();
					if ($cancelled) {
						$msg = (int)$cancelled['status'] === 3 ? 'Batch session is rescheduled on this date' : 'Batch session is cancelled on this date';
						$conn->close();
						return json_encode(['data' => [], 'error' => 1, 'error_msg' => 'batch is cancelled', 'message' => $msg]);
					}

					$insert_cnt = 0; $personResults=[];
					$canEditPast = (new session)->can_edit_previous_month(get_uid());
					foreach ($request['contacts'] as $key => $contact) {
                        $response=['error'=>0,'message'=>'Skipped','data'=>['state'=>'SKIPPED']];
						$contact['deduct_session_absentee'] = '1';
						$contact['session'] = '1';
						if($att_settings['deduct_session_absentee'] == 0 && $contact['selected'] == false){ //& for not deduct session
							$contact['deduct_session_absentee'] = '0';
							$contact['session'] = '0';
						}
						$contact['source'] = (isset($contact['source']) && !empty($contact['source'])) ? $contact['source'] : '4';
						$contact['datetime'] = $request['date'].' '.$request['time'];
						$contact['batch_id'] = $request['batch_id'];
						$contact['bid'] = $request['bid'];
						$contact['user_id'] = get_uid();
						$contact['status'] = '1';
						$contact['session_id'] = $request['s_id'];

						// Server-side check for past month editing permission
						$contact['check_month'] = !$canEditPast;

						if ($contact['type'] == 'E') { // Employee
							$isEmp = (int)$conn->query("SELECT COUNT(id) FROM employee WHERE contact_id = '{$contact['contact_id']}' AND status = '1' AND park = '0'")->fetch_row()[0];
							if ($isEmp === 0) {
								$contact['type'] = 'M';
							}
						}

						if ($contact['type'] == 'E') { // Employee
							$contact['row_color'] = '1';
							$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							if ($response['error'] == 0) {
								$insert_cnt++;
							}
						}
						else if(!$contact['inactive'] && $contact['selected'] == true && $contact['skip'] == false){ //Active member present
							//check for due payment
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$contact['contact_id']}' AND park = 0")->fetch_assoc();

							if(!empty($due) && $due['amount'] != 0){ //For balance
								$contact['row_color']= '2';
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
                                $notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with due",'Payment Due',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							} else { //For normal
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							}
							if($response['error'] == 0){
								$insert_cnt++;
							}
						}
						else if(!$contact['inactive'] && ($contact['selected'] == false && $contact['skip'] == false)){ //Active member absent
							//check for due payment
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$contact['contact_id']}' AND park = 0")->fetch_assoc();
							$contact['negative'] = 1; // for absent
							if(!empty($due) && $due['amount'] != 0){ //For balance
								$contact['row_color']= '2';
								$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
                                $notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with due",'Payment Due',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							} else { //For normal
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							}
							if($response['error'] == 0){
								$insert_cnt++;
							}
						}
						else if ($contact['inactive'] && $contact['selected'] && $contact['skip'] == false) { // Inactive Member if present
							$contact['negative'] = 2;
							$contact['row_color'] = 3;
								$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							if ($response['error'] == 0) {
								$insert_cnt++;
								$notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with expiry",'Package Expiry',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							}
						}
						$personResults[]=['contact_id'=>(int)$contact['contact_id'],'state'=>$response['data']['state']??((int)($response['error']??1)===0?'RECORDED':'REJECTED'),'message'=>$response['message']??'Skipped'];
						// else if($contact['inactive'] == true && $contact['selected'] == false){
						// 	$contact['batch_id'] = 0;
						// 	$contact['datetime'] = $request['date'].' '.$request['time'];
						// 	$contact['sessions'] = 0;
						// 	$contact['negative'] = 2;
						// 	$contact['row_color'] = 3;
						// 	$response = $this->punch_attendance($conn,$contact);
						// }
					}
					if($response['error']!=3){
						$response = ($insert_cnt > 0)  ? ['data'=>['results'=>$personResults,'state'=>count(array_filter($personResults,fn($p)=>(int)$p['error']>0))?'PARTIAL':'RECORDED'],'error'=>0,'error_msg'=>'','message'=>count(array_filter($personResults,fn($p)=>(int)$p['error']>0))?'Attendance partly saved; review individual results':'Batch attendance punched'] : ['data'=>['results'=>$personResults,'state'=>'REJECTED'],'error'=>1,'error_msg'=>'no attendance to punch','message'=>(empty($request['contacts']) ? 'No attendance to punch' : 'No new attendance saved; review individual results')];
					}
					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>1,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}
		public function insert_batch_attendance_app($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),2)){
					$first_attendance = 0;
					$conn = $this->connect($dbname);
					$att_settings = $conn->query("SELECT punch_dif,bio_extension,bio_type,deduct_session_absentee FROM attendance_settings WHERE bid = '{$request['bid']}'")->fetch_assoc();
					$insert_cnt = 0; $personResults=[];
					foreach ($request['contacts'] as $key => $contact) {
                        $response=['error'=>0,'message'=>'Skipped','data'=>['state'=>'SKIPPED']];
                        $response=['error'=>0,'message'=>'Skipped','data'=>['state'=>'SKIPPED']];
						$contact['deduct_session_absentee'] = '1';
						$contact['session'] = '1';
						if($att_settings['deduct_session_absentee'] == 0 && $contact['selected'] == false){ //& for not deduct session
							$contact['deduct_session_absentee'] = '0';
							$contact['session'] = '0';
						}
						$contact['source'] = (isset($contact['source']) && !empty($contact['source'])) ? $contact['source'] : '4';
						$contact['datetime'] = $request['date'].' '.$request['time'];
						$contact['batch_id'] = $request['batch_id'];
						$contact['bid'] = $request['bid'];
						$contact['user_id'] = get_uid();
						$contact['status'] = '1';
						$contact['session_id'] = $request['s_id'];
						
						//If $request['check_month'] is undefined or false then u r not allowed to punch the attendance
						$request['check_month'] ? $contact['check_month']=true : $contact['check_month']=false;

						if ($contact['type'] == 'E') { // Employee
							$contact['row_color'] = '1';
							$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							if ($response['error'] == 0) {
								$insert_cnt++;
							}
						}
						else if(!$contact['inactive'] && $contact['selected'] == true && $contact['skip'] == false){ //Active member present
							//check for due payment
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$contact['contact_id']}' AND park = 0")->fetch_assoc(); 
							
							if(!empty($due) && $due['amount'] != 0){ //For balance
								$contact['row_color']= '2';
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							 	$notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with due",'Payment Due',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							} else { //For normal
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							}
							if($response['error'] == 0){
								$insert_cnt++;
							}
						}
						else if(!$contact['inactive'] && ($contact['selected'] == false && $contact['skip'] == false)){ //Active member absent
							//check for due payment
							$due = $conn->query("SELECT SUM(`pay_balance`) AS amount FROM invoice_payment_view WHERE reminder <= '".date('Y-m-d')."' AND contact_id = '{$contact['contact_id']}' AND park = 0")->fetch_assoc(); 
							$contact['negative'] = 1; // for absent
							if(!empty($due) && $due['amount'] != 0){ //For balance
								$contact['row_color']= '2';
								$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							 	$notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with due",'Payment Due',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							} else { //For normal
									$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							}
							if($response['error'] == 0){
								$insert_cnt++;
							}
						}
						else if ($contact['inactive'] && $contact['selected'] && $contact['skip'] == false) { // Inactive Member if present
							$contact['negative'] = 2;
							$contact['row_color'] = 3;
								$response = $this->recordBatchMemberAttendance($conn, $contact, $att_settings);
							if ($response['error'] == 0) {
								$insert_cnt++;
								$notification = new notification;
								$notification->create_noti($conn, 6, 6, get_uid() ,'',"- {$contact['fullname']} with expiry",'Package Expiry',$contact['bid'],$contact['contact_id'],$contact['contact_id']);
							}
						}
						// else if($contact['inactive'] == true && $contact['selected'] == false){
						// 	$contact['batch_id'] = 0;
						// 	$contact['datetime'] = $request['date'].' '.$request['time'];
						// 	$contact['sessions'] = 0;
						// 	$contact['negative'] = 2;
						// 	$contact['row_color'] = 3;
						// 	$response = $this->punch_attendance($conn,$contact);	
						// }
                        $personResults[]=['contact_id'=>(int)$contact['contact_id'],'error'=>(int)$response['error'],'message'=>$response['message'],'state'=>$response['data']['state']??'REJECTED'];
					}
					if($response['error']!=3){
						$response = ($insert_cnt > 0)  ? ['data'=>['results'=>$personResults,'state'=>count(array_filter($personResults,fn($p)=>(int)$p['error']>0))?'PARTIAL':'RECORDED'],'error'=>0,'error_msg'=>'','message'=>count(array_filter($personResults,fn($p)=>(int)$p['error']>0))?'Attendance partly saved; review individual results':'Batch attendance punched'] : ['data'=>['results'=>$personResults,'state'=>'REJECTED'],'error'=>1,'error_msg'=>'no attendance to punch','message'=>(empty($request['contacts']) ? 'No attendance to punch' : 'No new attendance saved; review individual results')];
					}				
					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>1,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		public function punch_attendance($conn,$request,$setting){
			error_reporting(1);
			$first_attendance = 0 ;
			$request['date'] = date('Y-m-d',strtotime($request['datetime']));
			$request['time'] = date('H:i:s',strtotime($request['datetime']));
			if (!isset($request['session_id']) || empty($request['session_id']))
				$request['session_id'] = 0;

			if(!empty($request['check_month']) && date('Y-m', strtotime($request['date'])) !== date('Y-m')){
				return ['data'=>[], 'error'=>3, 'error_msg'=>'No priviliges', 'message'=>'Can\'t Punch previous month data'];
			}

			$att=$this->check_attendance($request,$conn);

			if($att)
			{
					$memberDetails = $conn->query("SELECT `pending_makeup`, `pending_not_pay_sal_attendance` AS `no_pay_sal` FROM `member` WHERE `contact_id` = '{$request['contact_id']}'")->fetch_assoc();
					$makeupTurn = 0;
					$pay_sal = 1;
					if ($memberDetails['pending_makeup'] > 0) {
						$makeupTurn = 1;
						// $pay_sal = 0; // now makeup turns are also paid and it was also over ridden by another if condition so no use of this line
					}
					if ($memberDetails['no_pay_sal'] > 0) {
						$pay_sal = 0;
					}
					$attendance_count=$conn->query("SELECT COUNT(id)+1 AS attendance_count FROM attendance WHERE park = 0 AND contact_id = '{$request['contact_id']}' AND type = 'M'")->fetch_assoc();
					$request['attendance_count']=$attendance_count['attendance_count']; 

					$class_nos=$conn->query("SELECT (completed_sessions)+1 AS class_no,batch FROM batch WHERE id != 0  AND id = '{$request['batch_id']}'"); 
					if($class_nos->num_rows){
						$class_no = $class_nos->fetch_assoc();
					}else{
						$class_no['class_no'] = 0;
						$class_no['batch'] = '';
					}
					$request['class_no']=$class_no['class_no']; 
					$request['batch']=$class_no['batch']; 

					$select_date = $conn->query("SELECT `id`,`in_time`,`out_time`,`date` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND batch_id = '{$request['batch_id']}' ) AND park = '0' AND `status` = '1' AND `negative` != '1' AND `session_id` = '{$request['session_id']}' ORDER BY id DESC LIMIT 1");
					// echo "SELECT `id`,`in_time`,`out_time`,`date` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND batch_id = '{$request['batch_id']}' ) AND park = '0' AND `status` = '1' ORDER BY id DESC LIMIT 1";
					
					if($select_date->num_rows){
						$previous = $select_date->fetch_assoc();
						$in_minute = $conn->query("SELECT TIMEDIFF('{$request['time']}','{$previous['in_time']}') AS `diff` ")->fetch_assoc();
						$out_minute = $conn->query("SELECT TIMEDIFF('{$request['time']}','{$previous['out_time']}') AS `diff` ")->fetch_assoc(); 
						
					}
					else
						$first_attendance = 1;

					if(($in_minute['diff'] > $setting['punch_dif'] && $out_minute['diff'] > $setting['punch_dif'])|| $first_attendance){
						//For batch attendance
						// $out_time = "00:00:00";
						// if ($request['source'] == 4) { //Batch Attendance
						// 	//Get Batch timing for the day of the date and start time is given
							
						// }
						$where = "";
						if ($request['batch_id'] && !empty($request['batch_id'])) {
							$where = " AND `batch_id` = '{$request['batch_id']}'";
						}
						if ($request['session_id'] && !empty($request['session_id'])) {
							$where .= " AND `session_id` = '{$request['session_id']}'";
						}
						$pending_query = $conn->query("SELECT `id` FROM `attendance` WHERE (`status` = '0' OR (`status` = '1' AND `negative` = '1')) AND `park` = '0' AND `date` = '{$request['date']}' AND `code` = '{$request['code']}' AND `contact_id` = '{$request['contact_id']}'".$where)->fetch_assoc();

						if($first_attendance){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}',1,'{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							// echo "UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = ".get_uid()." WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ";
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						else if($setting['bio_type'] == 0){
							if($request['batch_id'] && !empty($request['batch_id'])){
								$att = $conn->query("SELECT `id` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND `batch_id` = '{$request['batch_id']}' AND out_time = '00:00:00') AND park = '0' AND `status` = 1  LIMIT 1")->fetch_assoc();
								if(empty($att)){
									$att_id = 0;
									if (isset($pending_query['id']) && !empty($pending_query['id'])) {
										$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
										$att_id = $pending_query['id'];
									} else {
										$att=$this->check_attendance($request,$conn);
										$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
										if($att)
										{
											$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
											$att_id = $conn->insert_id;
										}
										else
										{
											$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
										}
									}
									$att_date = date('Y-m-d',strtotime($request['datetime']));
									// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
									$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
									if ($att_id > 0) {
										$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
									}
								}
								else{
									$update_attendance = $conn->query("UPDATE attendance SET out_time = '{$request['time']}' WHERE id = '{$att['id']}'");
								}
							}
							else{
								$att = $conn->query("SELECT `id` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND out_time = '00:00:00') AND park = '0' AND `status` = 1 LIMIT 1")->fetch_assoc();
								if(empty($att)){
									$att_id = 0;
									if (isset($pending_query['id']) && !empty($pending_query['id'])) {
										$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
										$att_id = $pending_query['id'];
									} else {
										$att=$this->check_attendance($request,$conn);
										$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
										if($att)
										{
											$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
											$att_id = $conn->insert_id;
										}
										else
										{
											$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
										}
									}
									$att_date = date('Y-m-d',strtotime($request['datetime']));
									// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
									$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
									if ($att_id > 0) {
										$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
									}
								}
								else{
									$update_attendance = $conn->query("UPDATE attendance SET out_time = '{$request['time']}' WHERE id = '{$att['id']}'");
								}
							}
								
						}
						else if($setting['bio_type'] == 1){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						else if($setting['bio_type'] == 2){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `out_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`out_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						if (isset($att_id) && $att_id > 0) {
							$conn->query("UPDATE `attendance` SET `session_id` = '{$request['session_id']}' WHERE `id` = '{$att_id}'");
						}
						$updateQueryBody = "";
						if ($makeupTurn == 1) {
							$updateQueryBody .= "`pending_makeup` = `pending_makeup` - 1";
						}
						if ($pay_sal == 0) {
							$updateQueryBody .= (strlen($updateQueryBody) > 0 ? ", " : "") . " `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` - 1";
						}
						if (strlen($updateQueryBody) > 0) {
							$conn->query("UPDATE `member` SET {$updateQueryBody} WHERE `contact_id` = '{$request['contact_id']}'");
						}
						
						if($insert_attendance || $update_attendance){
							$response['data'] = ['bal'=>'0', 'attendance_id' => $att_id, 'id' => $att_id];
							$response['error'] = 0;
							$response['error_msg'] = '';
							$response['message'] = 'Welcome '.$request['fullname'].', Attendance punched';
						}
						else{
							$response['data'] = ['bal'=>'0'];
							$response['error'] = 1;
							$response['error_msg'] = 'something went wrong';
							$response['message'] = 'please try again';
						}
						// Renewal date changes require an explicit package edit.
					}
					else{
						$response['data'] = ['bal'=>'0'];
						$response['error'] = 1;
						$response['error_msg'] = '';
						$response['message'] = 'Attendance already punched';
					}
					return $response;
			}
			else
			{
				$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Already punched,Please try again'];
				return $response;
			}
        }

		public function punch_attendance_app($conn,$request,$setting){
			error_reporting(1);
			$first_attendance = 0 ;
			$request['date'] = date('Y-m-d',strtotime($request['datetime']));
			$request['time'] = date('H:i:s',strtotime($request['datetime']));
			if (!isset($request['session_id']) || empty($request['session_id']))
				$request['session_id'] = 0;

			if(!empty($request['check_month']) && date('Y-m', strtotime($request['date'])) !== date('Y-m')){
				return ['data'=>[], 'error'=>3, 'error_msg'=>'No priviliges', 'message'=>'Can\'t Punch previous month data'];
			}

			$att=$this->check_attendance_app($request,$conn);

			if($att)
			{
					$memberDetails = $conn->query("SELECT `pending_makeup`, `pending_not_pay_sal_attendance` AS `no_pay_sal` FROM `member` WHERE `contact_id` = '{$request['contact_id']}'")->fetch_assoc();
					$makeupTurn = 0;
					$pay_sal = 1;
					if ($memberDetails['pending_makeup'] > 0) {
						$makeupTurn = 1;
						// $pay_sal = 0; // now makeup turns are also paid and it was also over ridden by another if condition so no use of this line
					}
					if ($memberDetails['no_pay_sal'] > 0) {
						$pay_sal = 0;
					}
					$attendance_count=$conn->query("SELECT COUNT(id)+1 AS attendance_count FROM attendance WHERE park = 0 AND contact_id = '{$request['contact_id']}' AND type = 'M'")->fetch_assoc();
					$request['attendance_count']=$attendance_count['attendance_count']; 

					$class_nos=$conn->query("SELECT (completed_sessions)+1 AS class_no,batch FROM batch WHERE id != 0  AND id = '{$request['batch_id']}'"); 
					if($class_nos->num_rows){
						$class_no = $class_nos->fetch_assoc();
					}else{
						$class_no['class_no'] = 0;
						$class_no['batch'] = '';
					}
					$request['class_no']=$class_no['class_no']; 
					$request['batch']=$class_no['batch']; 

					$select_date = $conn->query("SELECT `id`,`in_time`,`out_time`,`date` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND batch_id = '{$request['batch_id']}' ) AND park = '0' AND `status` = '1' AND `negative` != '1' AND `session_id` = '{$request['session_id']}' ORDER BY id DESC LIMIT 1");
					// echo "SELECT `id`,`in_time`,`out_time`,`date` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND batch_id = '{$request['batch_id']}' ) AND park = '0' AND `status` = '1' ORDER BY id DESC LIMIT 1";
					
					if($select_date->num_rows){
						$previous = $select_date->fetch_assoc();
						$in_minute = $conn->query("SELECT TIMEDIFF('{$request['time']}','{$previous['in_time']}') AS `diff` ")->fetch_assoc();
						$out_minute = $conn->query("SELECT TIMEDIFF('{$request['time']}','{$previous['out_time']}') AS `diff` ")->fetch_assoc(); 
						
					}
					else
						$first_attendance = 1;

					if(($in_minute['diff'] > $setting['punch_dif'] && $out_minute['diff'] > $setting['punch_dif'])|| $first_attendance){
						//For batch attendance
						// $out_time = "00:00:00";
						// if ($request['source'] == 4) { //Batch Attendance
						// 	//Get Batch timing for the day of the date and start time is given
							
						// }
						$where = "";
						if ($request['batch_id'] && !empty($request['batch_id'])) {
							$where = " AND `batch_id` = '{$request['batch_id']}'";
						}
						if ($request['session_id'] && !empty($request['session_id'])) {
							$where .= " AND `session_id` = '{$request['session_id']}'";
						}
						$pending_query = $conn->query("SELECT `id` FROM `attendance` WHERE (`status` = '0' OR (`status` = '1' AND `negative` = '1')) AND `park` = '0' AND `date` = '{$request['date']}' AND `code` = '{$request['code']}' AND `contact_id` = '{$request['contact_id']}'".$where)->fetch_assoc();

						if($first_attendance){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance_app($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}',1,'{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							// echo "UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = ".get_uid()." WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ";
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						else if($setting['bio_type'] == 0){
							if($request['batch_id'] && !empty($request['batch_id'])){
								$att = $conn->query("SELECT `id` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND `batch_id` = '{$request['batch_id']}' AND out_time = '00:00:00') AND park = '0' AND `status` = 1  LIMIT 1")->fetch_assoc();
								if(empty($att)){
									$att_id = 0;
									if (isset($pending_query['id']) && !empty($pending_query['id'])) {
										$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
										$att_id = $pending_query['id'];
									} else {
										$att=$this->check_attendance_app($request,$conn);
										$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
										if($att)
										{
											$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
											$att_id = $conn->insert_id;
										}
										else
										{
											$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
										}
									}
									$att_date = date('Y-m-d',strtotime($request['datetime']));
									// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
									$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
									if ($att_id > 0) {
										$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
									}
								}
								else{
									$update_attendance = $conn->query("UPDATE attendance SET out_time = '{$request['time']}' WHERE id = '{$att['id']}'");
								}
							}
							else{
								$att = $conn->query("SELECT `id` FROM attendance  WHERE (`code` = '{$request['code']}' AND`contact_id` = '{$request['contact_id']}' AND `date`='{$request['date']}' AND out_time = '00:00:00') AND park = '0' AND `status` = 1 LIMIT 1")->fetch_assoc();
								if(empty($att)){
									$att_id = 0;
									if (isset($pending_query['id']) && !empty($pending_query['id'])) {
										$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
										$att_id = $pending_query['id'];
									} else {
										$att=$this->check_attendance_app($request,$conn);
										$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
										if($att)
										{
											$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
											$att_id = $conn->insert_id;
										}
										else
										{
											$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
										}
									}
									$att_date = date('Y-m-d',strtotime($request['datetime']));
									// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
									$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
									if ($att_id > 0) {
										$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
									}
								}
								else{
									$update_attendance = $conn->query("UPDATE attendance SET out_time = '{$request['time']}' WHERE id = '{$att['id']}'");
								}
							}
								
						}
						else if($setting['bio_type'] == 1){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `in_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance_app($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`in_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						else if($setting['bio_type'] == 2){
							$att_id = 0;
							if (isset($pending_query['id']) && !empty($pending_query['id'])) {
								$update_attendance = $conn->query("UPDATE `attendance` SET `out_time` = '{$request['time']}', `negative` = '{$request['negative']}', `session` = '{$request['session']}', `bid` = '{$request['bid']}', `source` = '{$request['source']}', `type`='{$request['type']}', `status` = '1', `comment` = '{$request['comment']}', `row_color` = '{$request['row_color']}', `makeup` = '{$makeupTurn}', `pay_sal` = '{$pay_sal}', `modified_by` = '".get_uid()."' WHERE `id` = '{$pending_query['id']}'");
								$att_id = $pending_query['id'];
							} else {
								$att=$this->check_attendance_app($request,$conn);
								$request['venue_id'] = $conn->query("SELECT `venue_id` FROM `batch` WHERE id = '{$request['batch_id']}'")->fetch_assoc()['venue_id'];
								if($att)
								{
									$insert_attendance = $conn->query("INSERT INTO attendance(`date`,`out_time`,`contact_id`,`batch_id`,`venue_id`,`code`,`negative`,`session`,`user_id`,`bid`,`source`,`type`,`status`,`attendance_count`,`class_no`,`batch`,`comment`,`row_color`,`created_at`,`created_by`, `makeup`, `pay_sal`) VALUES('{$request['date']}','{$request['time']}','{$request['contact_id']}','{$request['batch_id']}','{$request['venue_id']}','{$request['code']}','{$request['negative']}','{$request['session']}','{$request['user_id']}','{$request['bid']}','{$request['source']}','{$request['type']}','1','{$request['attendance_count']}','{$request['class_no']}','{$request['batch']}','{$request['comment']}','{$request['row_color']}','".date('Y-m-d H:i:s')."','".get_uid()."', '{$makeupTurn}', '{$pay_sal}')");
									$att_id = $conn->insert_id;
								}
								else
								{
									$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Please try again'];
								}
							}
							$att_date = date('Y-m-d',strtotime($request['datetime']));
							// $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + 1,modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}' AND start_date <= '{$att_date}' AND end_date >= '{$att_date}' ");
							$conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$request['deduct_session_absentee']}',modified_by = '".get_uid()."' WHERE id = '{$request['item_id']}'");
							if ($att_id > 0) {
								$conn->query("UPDATE `attendance` SET `sessions_completed` = (SELECT `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}') WHERE `id` = '{$att_id}'");
							}
						}
						if (isset($att_id) && $att_id > 0) {
							$conn->query("UPDATE `attendance` SET `session_id` = '{$request['session_id']}' WHERE `id` = '{$att_id}'");
						}
						$updateQueryBody = "";
						if ($makeupTurn == 1) {
							$updateQueryBody .= "`pending_makeup` = `pending_makeup` - 1";
						}
						if ($pay_sal == 0) {
							$updateQueryBody .= (strlen($updateQueryBody) > 0 ? ", " : "") . " `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` - 1";
						}
						if (strlen($updateQueryBody) > 0) {
							$conn->query("UPDATE `member` SET {$updateQueryBody} WHERE `contact_id` = '{$request['contact_id']}'");
						}
						
						if($insert_attendance || $update_attendance){
							$response['data'] = ['bal'=>'0'];
							$response['error'] = 0;
							$response['error_msg'] = '';
							$response['message'] = 'Welcome '.$request['fullname'].', Attendance punched';
						}
						else{
							$response['data'] = ['bal'=>'0'];
							$response['error'] = 1;
							$response['error_msg'] = 'something went wrong';
							$response['message'] = 'please try again';
						}
						// Renewal date changes require an explicit package edit.
					}
					else{
						$response['data'] = ['bal'=>'0'];
						$response['error'] = 1;
						$response['error_msg'] = '';
						$response['message'] = 'Attendance already punched';
					}
					return $response;
			}
			else
			{
				$response = ['data'=>[],'error'=>1,'error_msg'=>'attendance already punched','message'=>'Already punched,Please try again'];
				return $response;
			}
        }

		public function update_attendance($request){
			
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid()) ? get_uid() : $request['uid'],$this->module('Attendance'),2)){
					$conn = $this->connect($dbname);

					$build = new querybuilder;
					$query = "UPDATE attendance SET";

					if(array_key_exists('u', $request) && !empty($request['u']))
						$query = $build->update($request['u'],$query);

					if(array_key_exists('filter', $request) && !empty($request['filter']))
						$query = $build->where($request['filter'],$query);

					if(array_key_exists('orfilter', $request) && !empty($request['orfilter']))
						$query = $build->orWhere($request['orfilter'],$query);

					$newNegative = null;
					if (array_key_exists('u', $request) && is_array($request['u'])) {
						foreach ($request['u'] as $colVal) {
							if (isset($colVal['col']) && $colVal['col'] === 'negative') {
								$newNegative = (int)$colVal['val'];
								break;
							}
						}
					}

					$rowsToUpdate = [];
					if ($newNegative !== null && array_key_exists('filter', $request) && !empty($request['filter'])) {
						$selectQuery = $build->where($request['filter'], "SELECT id, contact_id, batch_id, session, date, negative, type, park, bid FROM attendance ");
						$res = $conn->query($selectQuery);
						if ($res) {
							while ($r = $res->fetch_assoc()) {
								$rowsToUpdate[] = $r;
							}
						}
					}

					$updated = $conn->query($query);
					if($updated){
						if ($newNegative !== null) {
							foreach ($rowsToUpdate as $r) {
								if ($r['type'] === 'M' && (int)$r['park'] === 0) {
									$oldNegative = (int)$r['negative'];
									if ($oldNegative !== $newNegative) {
										$branchId = !empty($r['bid']) ? (int)$r['bid'] : (int)($request['bid'] ?? 1);
										$branchSetting = $conn->query("SELECT deduct_session_absentee FROM attendance_settings WHERE bid = '{$branchId}' LIMIT 1")->fetch_assoc();
										$deductAbsentee = !empty($branchSetting['deduct_session_absentee']) ? 1 : 0;
										if ($deductAbsentee === 0) {
											$sessionCount = !empty($r['session']) ? (int)$r['session'] : 1;
											if ($oldNegative === 0 && $newNegative === 1) {
												// Present -> Absent: refund session
												$this->revert_session($conn, $r['contact_id'], $r['batch_id'], $sessionCount, $r['date']);
											} elseif ($oldNegative === 1 && $newNegative === 0) {
												// Absent -> Present: deduct session
												$this->deduct_session($conn, $r['contact_id'], $r['batch_id'], $sessionCount, $r['date']);
											}
										}
									}
								}
							}
						}

						/* Generate notification */
						$noti = new notification;
						$noti->create_noti($conn,2,6,get_uid(),'','','',$request['bid'],0,0);

						$response['data'] = array();
						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = 'Attendance updated';
					}
					else{
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'something went wrong';
						$response['message'] = 'Please try again';
					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>1,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		public function delete_attendance($request){
			return $this->park_attendance($request);
		}

		public function park_attendance($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),3)){
					$conn = $this->connect($dbname);

					$build = new querybuilder;
					$request['comment'] = $conn->escape_string($request['comment']);
					
					$canEditPast = (new session)->can_edit_previous_month(get_uid());
					if(!$canEditPast){
						$query1 = "SELECT date,park FROM attendance  ";

						if(array_key_exists('d', $request) && !empty($request['d'])){
						$query1 = $build->where($request['d'],$query1);}
						
						$row1 = $conn->query($query1)->fetch_assoc();
						
						if($row1 && date('m',strtotime($row1['date'])) != date('m')){
							$response['data'] = array();
							$response['error'] = 0;
							$response['error_msg'] = 'No privileges';
							$response['message'] = 'Can\'t Delete previous month data';
							return json_encode($response);
						}
					}

					$query = "UPDATE attendance SET park = '{$request['park_value']}', comment = CONCAT(comment,'{$request['comment']} ') , `modified_by` = '".get_uid()."' ";

					if(array_key_exists('d', $request) && !empty($request['d']))
						$query = $build->where($request['d'],$query);

					$deleted = $conn->query($query);
					if($deleted){
						$row = $conn->query($build->where($request['d'],"SELECT id,fullname,code,contact_id,session,batch_id,`date`,makeup,pay_sal FROM attendance_cont_view "))->fetch_assoc();
						
						
						if(intval($request['park_value']) == 1){
							if($row['batch_id'] != 0)
								$this->revert_session($conn,$row['contact_id'],$row['batch_id'],$row['session'],$row['date']);
								if($row['makeup']==1){
									$this->add_member_pending_makeup($conn,$row['contact_id'],$row['session'],$row['pay_sal']);
								}
								$this->revert_makeup($conn,$row['contact_id'],$row['batch_id'],$row['session'],$row['date'],$request);//only while deleting
							/* Generate notification */
							$noti = new notification;
							$noti->create_noti($conn,3,6,get_uid(),$row['id'],'For '.$row['code'].' - '.$row['fullname'],'Trashed',get_bid(),$row['contact_id'],0);
							$response = ['data'=>[],'error'=>0,'error_msg'=>'','message'=>'Attendance deleted'];
						}
						else if(intval($request['park_value']) == 0){
							if($row['batch_id'] != 0)
								$this->deduct_session($conn,$row['contact_id'],$row['batch_id'],$row['session'],$row['date']);
								if($row['makeup']==1){
									$this->deduct_member_pending_makeup($conn,$row['contact_id'],$row['session'],$row['pay_sal']);
								}
								// restoring attendance does not give makeup turns
							/* Generate notification */
							$noti = new notification;
							$noti->create_noti($conn,15,6,get_uid(),$row['id'],'For '.$row['code'].' - '.$row['fullname'],'Recycled',get_bid(),$row['contact_id'],0);
							$response = ['data'=>[],'error'=>0,'error_msg'=>'','message'=>'Attendance restored'];
						}
						else if(intval($request['park_value']) == 2){
							$response = ['data'=>[],'error'=>0,'error_msg'=>'','message'=>'Attendance permanently deleted'];	
						}
						
						
					}
					else{
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'something went wrong';
						$response['message'] = 'Please try again';
					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		private function revert_session($conn,$contact_id,$batch_id,$session,$date){
			$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}' AND park = '0' AND `start_date`<= '{$date}' AND end_date>='{$date}' AND `sessions`>'0' order by item_id DESC LIMIT 1")->fetch_assoc(); //select items based on start date end date and same batch
			if(empty($item['item_id'])||!isset($item['item_id'])){
				$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}'  AND `sessions`>'0'  AND park = '0' ORDER BY id DESC");
				if($item->num_rows>0){
					$item=$item->fetch_assoc(); //start date forwarded and then deleting old invoice case sorted here and much more
				}else{
					$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE `start_date`<= '{$date}' AND end_date>='{$date}' AND contact_id = '{$contact_id}'  AND `sessions`>'0'  AND park = '0' ORDER BY id DESC")->fetch_assoc();//transfer case is solved here
				}
			}
			$update_item = $conn->query("UPDATE invoice_item SET sessions_used = sessions_used - '{$session}' WHERE sessions_used <> '0' and id = '{$item['item_id']}'"); 
		}

		private function deduct_session($conn,$contact_id,$batch_id,$session,$date){
			$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}' AND park = '0' AND `start_date`<= '{$date}' AND end_date>='{$date}' AND `sessions`>'0' order by item_id DESC LIMIT 1")->fetch_assoc();
			if(empty($item['item_id'])||!isset($item['item_id'])){
				$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}'  AND `sessions`>'0' AND park = '0' ORDER BY id DESC");
				if($item->num_rows>0){
					$item=$item->fetch_assoc(); //start date forwarded and then restored old invoice case sorted here and much more
				}else{
					$item = $conn->query("SELECT item_id FROM invoice_invoiceitem_view WHERE `start_date`<= '{$date}' AND end_date>='{$date}' AND contact_id = '{$contact_id}'  AND `sessions`>'0'  AND park = '0' ORDER BY id DESC")->fetch_assoc();//transfer case is solved here
				}
			}
			$update_item = $conn->query("UPDATE invoice_item SET  sessions_used = sessions_used + '{$session}' WHERE id = '{$item['item_id']}'"); // restoring must re-deduct even when the balance had returned to 0
		}

		private function add_member_pending_makeup($conn,$contact_id,$session,$paid_sal){
			if(!isset($paid_sal)||$paid_sal==1){ //no salary deduction was taken place for employee hence no need to add anything in member
				$paid_sal=0;
			}
			if($paid_sal==0){//salary deduction was taken place for employee hence need to add pending_not_pay_sal_attendance in member
				$paid_sal=1;
			}
			if((isset($contact_id) && $contact_id !=0 && !empty($contact_id))){            
				$update_member_pending = $conn->query("UPDATE `member` SET `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` + '{$paid_sal}',`pending_makeup` = `pending_makeup` + '{$session}' WHERE `contact_id` = '{$contact_id}'");            
			}
		}
		private function deduct_member_pending_makeup($conn,$contact_id,$session,$paid_sal){
			if(!isset($paid_sal)||$paid_sal==1){ //no salary deduction was taken place for employee hence no need to deduct anything in member
				$paid_sal=0;
			}
			if($paid_sal==0){//salary deduction was taken place for employee hence need to deduct pending_not_pay_sal_attendance in member
				$paid_sal=1;
			}
			if((isset($contact_id) && $contact_id !=0 && !empty($contact_id))){            
				$update_member_pending = $conn->query("UPDATE `member` SET `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` - '{$paid_sal}',`pending_makeup` = `pending_makeup` - '{$session}' WHERE `contact_id` = '{$contact_id}'");            
			}
		}
		
		private function revert_makeup($conn,$contact_id,$batch_id,$session,$date,$request){
			$item = $conn->query("SELECT invoice_id, item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}' AND park = '0' AND `start_date`<= '{$date}' AND end_date>='{$date}' AND `sessions` > 0 order by item_id DESC LIMIT 1")->fetch_assoc();
			if(empty($item['item_id'])||!isset($item['item_id'])){
				$item = $conn->query("SELECT invoice_id, item_id FROM invoice_invoiceitem_view WHERE batch_id = '{$batch_id}' AND contact_id = '{$contact_id}' AND park = '0'")->fetch_assoc();
			}
			$row = $conn->query("SELECT MAX(`date`) FROM attendance_cont_view WHERE contact_id='{$contact_id}' AND batch_id='{$batch_id}' ORDER by id DESC LIMIT 1")->fetch_assoc();
			if($row['date']!=$date){
				 // No actions if not the latest attendance
			}else{
				// To check whether makeup was given or not on attendance date
				$makeup_session = $conn->query("SELECT makeup_session,pay_not_sal_session FROM makeup_log WHERE `makeup_date` = '{$date}' AND invoice_item_id = '{$item['item_id']}' AND park = '0'")->fetch_assoc();

				if(!empty($makeup_session['makeup_session'])){
					// DEDUCT MAKE UP MEMBER DATA
					$reduce_member_pending = $conn->query("UPDATE `member` SET `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` - '{$makeup_session['pay_not_sal_session']}',`pending_makeup` = `pending_makeup` - '{$makeup_session['makeup_session']}' WHERE `contact_id` = '{$contact_id}'");
					
					// DEDUCT MAKE UP SESSIONS FROM INVOICEITEM AND END DATE TO TODAY FOR SCRIPT ACTIONS
					$update_item = $conn->query("UPDATE invoice_item SET  sessions = sessions - '{$makeup_session['makeup_session']}', end_date = '".date('Y-m-d')."' WHERE sessions_used <> '0' and id = '{$item['item_id']}'"); 

					if($makeup_session['makeup_session'] > 0){

						// INSERT invoice Change Log
						$conn->query("INSERT INTO `invoice_change_log`(`invoice_id`, `from_item_id`, `change`, `comment`,`extend_session`, `created_by`) VALUES ('{$item['invoice_id']}','{$item['item_id']}','10','Extension deducted due to attendance deletion of {$date}.\nComment: {$request['comment']}',CONCAT('-','{$makeup_session['makeup_session']}'),'".get_uid()."')");
					}
					//deleted makeup log for deleted attendance item_id 
					$update_item = $conn->query("UPDATE makeup_log SET park = '1' WHERE `makeup_date` = '{$date}' AND item_id = '{$item['item_id']}'");
				}
			}
		}

		public function select_attendance($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$conn = $this->connect($dbname);
					
					$build = new querybuilder;
					$query = "";

					if(array_key_exists('col', $request) && !empty($request['col']))
						$query = $build->select($request['col']);

					$query.="FROM attendance_cont_view";

					if(array_key_exists('filter', $request) && !empty($request['filter']))
						$query = $build->where($request['filter'],$query);

					if(array_key_exists('orfilter', $request) && !empty($request['orfilter']))
						$query = $build->orWhere($request['orfilter'],$query);

					if(array_key_exists('order', $request) && !empty($request['order']))
						$query = $build->order($request['order'],$query);

					$query.= !empty($request['groupby']) ? " GROUP BY ".$request['groupby'] : "" ;

					if(array_key_exists('limit', $request) && !empty($request['limit']))
						$query .= " LIMIT {$request['limit']}";

					if(array_key_exists('offset', $request) && !empty($request['offset']))
						$query .= " OFFSET {$request['offset']}";

					$result = $conn->query($query);

					// var_dump($query);

					if($result->num_rows){
						$response['data'] =array();
						while ($row = $result->fetch_assoc())
							array_push($response['data'],$row);
						
						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
					}
					else{
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'record not found';
						$response['message'] = 'Not found';
					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		public function search($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$days = ['Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6,'Sun'=>7];
					$conn = $this->connect($dbname);

					//Get settings from studio_settings
					$sett = $conn->query("SELECT `inter_branch_member` FROM `studio_setting`")->fetch_assoc();
					$whr_clause = " AND `m`.`bid` = '".get_bid()."' ";
					if ($sett['inter_branch_member'] == 1) {
						$whr_clause = "";
					}

					if(array_key_exists('contact_id', $request) && !empty($request['contact_id'])){
						$memb_result = $conn->query("SELECT concat(`c`.`fname`,' ',`c`.`lname`) AS fullname,`c`.`mobile`,`m`.`code` FROM contact AS c INNER JOIN member AS m ON `c`.`id`=`m`.`contact_id` WHERE `m`.`contact_id` = '{$request['contact_id']}' AND `m`.`park` = '0' {$whr_clause}");
					}
					else{
						$memb_result = $conn->query("SELECT concat(`c`.`fname`,' ',`c`.`lname`) AS fullname,`c`.`mobile`,`m`.`code` FROM contact AS c INNER JOIN member AS m ON `c`.`id`=`m`.`contact_id` WHERE (`c`.`fname` LIKE '{$request['searchString']}%' OR `m`.`code` LIKE '{$request['searchString']}%') AND `m`.`park` = '0' {$whr_clause}");
					}

					
					if(array_key_exists('contact_id', $request) && !empty($request['contact_id'])){
						$emp_result = $conn->query("SELECT concat(`c`.`fname`,' ',`c`.`lname`) AS fullname,`c`.`mobile`,`e`.`ecode` AS `code` FROM contact AS c INNER JOIN emp_cont_view AS e ON `c`.`id`=`e`.`contact_id` WHERE `e`.`contact_id` = '{$request['contact_id']}' AND `e`.`park` = '0' AND `e`.`bid` = '".get_bid()."' ");
					}
					else{
						$emp_result = $conn->query("SELECT concat(`c`.`fname`,' ',`c`.`lname`) AS fullname,`c`.`mobile`,`e`.`ecode` AS `code` FROM contact AS c INNER JOIN emp_cont_view AS e ON `c`.`id`=`e`.`contact_id` WHERE `c`.`fname` LIKE '{$request['searchString']}%' OR `c`.`mobile` LIKE '{$request['searchString']}%' OR `e`.`ecode` LIKE '{$request['searchString']}%' AND `e`.`park` = '0' AND `e`.`bid` = '".get_bid()."' ");
					}
					
									
					$response['data'] =array();
					if($memb_result->num_rows){
							
						while ($row = $memb_result->fetch_assoc()){
							$row['type'] = 'M';
							array_push($response['data'],$row);
						}
						
						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
					}
					if($emp_result->num_rows){
					
						while ($row = $emp_result->fetch_assoc()){
							$row['type'] = 'E';
							array_push($response['data'],$row);
						}

						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
					}
					if (empty($response['data'])){
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'record not found';
						$response['message'] = 'Not found';
					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		public function has_batch($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$conn = $this->connect($dbname);
		        	$response['data']['batch'] = [];

					$studio_sett = $conn->query("SELECT `inter_branch_member` FROM `studio_setting`")->fetch_assoc();

					$whr_clause = " AND bid = '{$request['bid']}' ";

					if ($studio_sett['inter_branch_member'] == 1) {
						$whr_clause = "";
					}
		        	//get Settings
		        	$get_all_batch = 0;
		        	$att_settings = $conn->query("SELECT `get_all_batch` FROM `attendance_settings` WHERE `bid` = '{$request['bid']}'")->fetch_assoc();
		        	$get_all_batch = empty($att_settings['get_all_batch']) ? "0" : $att_settings['get_all_batch'];


					if($conn->query("SELECT `id` FROM emp_cont_view WHERE `ecode` = '{$request['code']}' AND `park` = '0' ")->num_rows){
						$whereClause = "`code` = '{$request['code']}'";
						if ($get_all_batch) { //Get All batches of the day
							$whereClause = "`day_code` = '".date('w', strtotime($request['datetime']))."'";
						}

						$result = $conn->query("SELECT DISTINCT batch,id FROM batch_employee_time_view `b` WHERE bid = '{$request['bid']}' AND `park` = '0' AND `cont_park` = '0' AND `inactive` = '0' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."'  AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND {$whereClause}");
						// echo "SELECT DISTINCT batch,id FROM batch_employee_time_view `b` WHERE bid = '{$request['bid']}' AND `park` = '0' AND `cont_park` = '0' AND `inactive` = '0' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."'  AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND {$whereClause}";
						while ($row = $result->fetch_assoc()){

							//check whether batch has cancelled.
							$check_batch_canceled = $conn->query("SELECT id,status FROM attendance WHERE batch_id = '{$row['id']}' AND `date` = '".date('Y-m-d', strtotime($request['datetime']))."' and contact_id = '0' and status in ('2','3') and type = 'B' and park = '0' and bid = '{$request['bid']}'")->fetch_assoc();


							if($check_batch_canceled == null || $check_batch_canceled == 0 || empty($check_batch_canceled)){
								array_push($response['data']['batch'], $row);
							}
							else if ($check_batch_canceled != null && !empty($check_batch_canceled) && intval($check_batch_canceled['status']) == 3) {
								// check if rescheduled .
								$result1 = $conn->query("SELECT DISTINCT batch,id FROM batch_employee_time_view `b` WHERE bid = '{$request['bid']}' AND `parent_id` = '{$row['id']}'  AND `park` = '0' AND `cont_park` = '0' AND `inactive` = '0' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND parent_id = '0'");

								if($result1->num_rows){
									while ($row1 = $result1->fetch_assoc()){

										array_push($response['data']['batch'], $row1);
									}
								}

							}
						}

					}
					else if($conn->query("SELECT `id` FROM member WHERE `code` = '{$request['code']}' AND `park` = '0' {$whr_clause}")->num_rows){
			
						$response['data']['no_batch'] = array();
						//Check for attendance already punch for the same date
						// $pending_query = $conn->query("SELECT `batch_id` FROM `attendance` WHERE `code` = '{$request['code']}' AND `park` = 0 AND `status` = '0' AND `date` = '".date('Y-m-d',strtotime($request['datetime']))."' AND `bid` = '{$request['bid']}')");
						// if (isset($pending_query['batch_id']) && !empty($pending_query['batch_id'])) {

						// }
						//Only select first active invoice of student
						$result = $conn->query("SELECT batch_id,item_id,sessions,sessions_used FROM invoice_invoiceitem_view WHERE `code` = '{$request['code']}' AND  `park` = '0' AND `item_park` = '0' AND `start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND (`sessions` = 0 OR (`sessions` > `sessions_used`)) AND `bid` = '{$request['bid']}' AND `is_product` = 0 ORDER BY `id` ASC LIMIT 1");
			
						while ($row = $result->fetch_assoc()){
						 	if($row['sessions'] == 0){
								$batch = $conn->query("SELECT `b`.`batch`,`b`.`id`, `bt`.`start_time`, `bt`.`end_time` FROM batch AS b INNER JOIN batch_time AS bt ON `b`.`id` = `bt`.`batch_id` WHERE `bt`.`batch_id` = '{$row['batch_id']}' AND `b`.`inactive` = '0' AND `b`.`park` = '0' AND `b`.`bid` = '{$request['bid']}' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`parent_id` = '0'")->fetch_assoc();
								if(!empty($batch)){
									$batch['item_id'] = $row['item_id'];
									$batch['sessions'] = 0;	

									//check whether batch has cancelled.
									$check_batch_canceled = $conn->query("SELECT id,status FROM attendance WHERE batch_id = '{$batch['id']}' AND `date` = '".date('Y-m-d', strtotime($request['datetime']))."' and contact_id = '0' and status in ('2','3') and type = 'B' and park = '0' and bid = '{$request['bid']}'")->fetch_assoc();
									if($check_batch_canceled == null || $check_batch_canceled == 0 || empty($check_batch_canceled)){
										array_push($response['data']['batch'], $batch);
									}
									else if ($check_batch_canceled != null && !empty($check_batch_canceled) && intval($check_batch_canceled['status']) == 3) {
										// check if rescheduled .
										$rescheduled_batch = $conn->query("SELECT `b`.`batch`,`b`.`id`, `bt`.`start_time`, `bt`.`end_time` FROM batch AS b INNER JOIN batch_time AS bt ON `b`.`id` = `bt`.`batch_id` WHERE `b`.`parent_id` = '{$row['batch_id']}' AND `b`.`inactive` = '0' AND `b`.`park` = '0' AND `b`.`bid` = '{$request['bid']}' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`parent_id` = '0'")->fetch_assoc();

										if(!empty($rescheduled_batch)){
											$rescheduled_batch['item_id'] = $row['item_id'];
											$rescheduled_batch['sessions'] = 0;	
											array_push($response['data']['batch'], $rescheduled_batch);
											
										}

									}
								}
								else
									array_push($response['data']['no_batch'], ['item_id'=>$row['item_id'],'sessions'=>0]);

							}
							else if($row['sessions'] > 0 && ($row['sessions_used'] < $row['sessions'])){
								$batch = $conn->query("SELECT `b`.`batch`,`b`.`id`, `bt`.`start_time`, `bt`.`end_time` FROM batch AS b INNER JOIN batch_time AS bt ON `b`.`id` = `bt`.`batch_id` WHERE `bt`.`batch_id` = '{$row['batch_id']}' AND `b`.`inactive` = '0' AND `b`.`park` = '0' AND `b`.`bid` = '{$request['bid']}' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."' ")->fetch_assoc();
								if(!empty($batch)){
									$batch['item_id'] = $row['item_id'];
									$batch['sessions'] = 1;	

									//check whether batch has cancelled.
									$check_batch_canceled = $conn->query("SELECT id,status FROM attendance WHERE batch_id = '{$batch['id']}' AND `date` = '".date('Y-m-d', strtotime($request['datetime']))."' and contact_id = '0' and status in ('2','3') and type = 'B' and park = '0' and bid = '{$request['bid']}'")->fetch_assoc();

									
									if($check_batch_canceled == null || $check_batch_canceled == 0 || empty($check_batch_canceled)){
										array_push($response['data']['batch'], $batch);
									}
									else if ($check_batch_canceled != null && !empty($check_batch_canceled) && intval($check_batch_canceled['status']) == 3) {
										// check if rescheduled .
										$rescheduled_batch = $conn->query("SELECT `b`.`batch`,`b`.`id`, `bt`.`start_time`, `bt`.`end_time` FROM batch AS b INNER JOIN batch_time AS bt ON `b`.`id` = `bt`.`batch_id` WHERE `b`.`parent_id` = '{$row['batch_id']}' AND `b`.`inactive` = '0' AND `b`.`park` = '0' AND `b`.`bid` = '{$request['bid']}' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."'")->fetch_assoc();

										if(!empty($rescheduled_batch)){
											$rescheduled_batch['item_id'] = $row['item_id'];
											$rescheduled_batch['sessions'] = 1;	
											array_push($response['data']['batch'], $rescheduled_batch);
										}
									}
								}
								else
									array_push($response['data']['no_batch'], ['item_id'=>$row['item_id'],'sessions'=>1]);
							}
						}
						//Check for flag n get all batch accordingly
						if ($get_all_batch) {
							//Get All batches of the day
							$sql_batches = $conn->query("SELECT DISTINCT batch,id, start_time, end_time FROM batch_employee_time_view WHERE `day_code` = '".date('w', strtotime($request['datetime']))."' AND `park` = '0' AND `cont_park` = '0' AND `inactive` = '0' AND `bid` = '{$request['bid']}' AND `start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."'");
							while ($batch = $sql_batches->fetch_assoc()) {
								$batchExists = false;
								//Check for batch already exists
								foreach ($response['data']['batch'] as $key => $obj) {
									if ($obj['id'] == $batch['id']) {
										$batchExists = true;
										break;
									}
								}
								if (!$batchExists) {
									$check_batch_canceled = $conn->query("SELECT id,status FROM attendance WHERE batch_id = '{$batch['id']}' AND `date` = '".date('Y-m-d')."' and contact_id = '0' and status in ('2','3') and type = 'B' and park = '0' and bid = '{$request['bid']}'")->fetch_assoc();

									if($check_batch_canceled == null || $check_batch_canceled == 0 || empty($check_batch_canceled)){
										array_push($response['data']['batch'], array('id'=>$batch['id'],'batch'=>$batch['batch'],'item_id'=>'0','sessions'=>'0'));
									}
									else if ($check_batch_canceled != null && !empty($check_batch_canceled) && intval($check_batch_canceled['status']) == 3) {
										// check if rescheduled .
										$rescheduled_batch = $conn->query("SELECT `b`.`batch`,`b`.`id`, bt.start_time, bt.end_time FROM batch AS b INNER JOIN batch_time AS bt ON `b`.`id` = `bt`.`batch_id` WHERE `b`.`parent_id` = '{$batch['id']}' AND `b`.`inactive` = '0' AND `b`.`park` = '0' AND `b`.`bid` = '{$request['bid']}' AND `b`.`start_date` <= '".date('Y-m-d', strtotime($request['datetime']))."' AND `b`.`end_date` >= '".date('Y-m-d', strtotime($request['datetime']))."'")->fetch_assoc();

										if(!empty($rescheduled_batch)){
											$rescheduled_batch['item_id'] = $row['item_id'];
											$rescheduled_batch['sessions'] = 1;	
											array_push($response['data']['batch'], $rescheduled_batch);
										}
									}
								}
							}
						}
					}
					
					if(!empty($response['data']['batch'])){

						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
						
					}
					else{
						
						$response['error'] = 1;
						$response['error_msg'] = 'not found';
						$response['message'] = ' Batches not found';
					}

					$conn->close();
				}
				else
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];

				return json_encode($response);
			}
		}

		public function selectBatchSessionAtt($request) {
			$session = new session;
			$dbname = $session->check_session($request);
			$response = [];

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$conn = $this->connect($dbname);
					$datesArr = [];
					$minStart = date('Y-m-d');
					$maxEnd = date('Y-m-d');

					//Get Sessions Data
					$sql_invoice_range = $conn->query("SELECT `iiv`.`start_date`, `iiv`.`end_date`, `iiv`.`sessions`, `iiv`.`sessions_used`, `iiv`.`batch_id`, `iiv`.`category`, `iiv`.`category_id`, `iiv`.`service`, `iiv`.`service_id`, group_concat(DISTINCT `bt`.`day_code` ORDER BY `bt`.`day_code` ASC) AS `day_code`,  group_concat(DISTINCT `bt`.`start_time` ORDER BY `bt`.`day_code` ASC) AS `start_time`,  group_concat(DISTINCT `bt`.`end_time` ORDER BY `bt`.`day_code` ASC) AS `end_time` FROM `invoice_invoiceitem_view` `iiv` join `batch_time` `bt` on (`iiv`.`batch_id` = `bt`.`batch_id`) WHERE `iiv`.`contact_id` = '{$request['contact_id']}' AND `iiv`.`batch_id` != 0 AND `iiv`.`sessions` > 0 AND `iiv`.`park` = 0 GROUP BY `iiv`.`item_id`");

					while ($invoice_range = $sql_invoice_range->fetch_assoc()) {
						//loop between dates
						$sessions_counted = 1;
						$startDate = $invoice_range['start_date'];
						if (strtotime($startDate) < strtotime($minStart)) {
							$minStart = $startDate;
						}
						$days = explode(",", $invoice_range['day_code']);
						$startTimes = explode(",", $invoice_range['start_time']);
						$endTimes = explode(",", $invoice_range['end_time']);
						while (date('Ymd', strtotime($startDate)) <= date('Ymd',strtotime($invoice_range['end_date']))) {
							if ($sessions_counted > $invoice_range['sessions']) break;


							if (in_array(date('w', strtotime($startDate)), $days)) {
								$index = array_search(date('w', strtotime($startDate)), $days);
								$present = $conn->query("SELECT `id` FROM `attendance` WHERE `contact_id` = '{$request['contact_id']}' AND `negative` = 0 AND `status` = '1' AND `park` = 0 AND `batch_id` = '{$invoice_range['batch_id']}' AND `date` = '{$startDate}'")->num_rows;
								array_push($datesArr, ['date'=>$startDate,'day_code'=>$invoice_range['day_code'],'sessions'=>$invoice_range['sessions'],'sessions_used'=>$sessions_counted,'start_time'=>$startTimes[$index],'end_time'=>$endTimes[$index],'is_present'=>$present,'is_event'=>'0','is_holiday'=>'0','category'=>$invoice_range['category'],'service'=>$invoice_range['service'],'event'=>'']);
								$sessions_counted++;
							}

							$startDate = date('Y-m-d',strtotime('+1 days ', strtotime($startDate)));
						}
						if (strtotime($startDate) > strtotime($maxEnd)) {
							$maxEnd = $startDate;
						}
					}

					$start_date = date('Y-m-d', strtotime('-6 months'));
					$end_date = date('Y-m-d', strtotime('+6 months'));
					if (strtotime($start_date) < strtotime($minStart)) {
						$minStart = $start_date;
					}
					if (strtotime($end_date) > strtotime($maxEnd)) {
						$maxEnd = $end_date;
					}
					$events = [];
					//Get Events
					$sql_events = $conn->query("SELECT `id`, `name`, `image`, `date`, `start`, `end` FROM `events` WHERE `date` >= '{$start_date}' AND `date` <= '{$end_date}' AND `park` = '0' AND `bid` IN ('0','".get_bid()."')");
					while ($event = $sql_events->fetch_assoc()) {
						// while (date('Ymd', strtotime($start_date)) <= date('Ymd', strtotime($end_date))) {
							// if ($start_date == $event['date']) { //Add event
								array_push($events, ['date'=>$event['date'],'day_code'=>'','sessions'=>'0','sessions_used'=>'0','start_time'=>$event['start'],'end_time'=>$event['end'],'is_present'=>'0','is_event'=>'1','is_holiday'=>'0','category'=>'','service'=>'','event'=>$event['name'],'image'=>$event['image']]);
							// }
							// $start_date = date('Y-m-d', strtotime('+1 days', strtotime($start_date)));
						// }
					}

					$holidays = [];
					//Get Holidays
					$sql_holidays = $conn->query("SELECT `id`, `date`, `title`,`every_year` FROM `holiday` WHERE `park` = '0' AND `date` BETWEEN '{$start_date}' AND '{$end_date}'");
					while ($holiday = $sql_holidays->fetch_assoc()) {
						array_push($holidays, ['date'=>$holiday['date'],'day_code'=>'','sessions'=>'0','sessions_used'=>'0','start_time'=>'','end_time'=>'','is_present'=>'0','is_event'=>'0','is_holiday'=>'1','category'=>'','service'=>'','event'=>'','title'=>'','holiday'=>$holiday['title'],'image'=>'']);
					}

					$data = [];
					//loop between start and end date
					while (strtotime($minStart) <= strtotime($maxEnd)) {
						$subData = [];
						//search for datesArr
						foreach ($datesArr as $key => $item) {
							if ($minStart == $item['date']) {
								if (sizeof($subData) == 0) $subData[] = ["date_only"=>1,"date"=>$item['date']];
								$item['date_only'] = 0;
								array_push($subData, $item);
							}
						}
						foreach ($events as $key => $item) {
							if ($minStart == $item['date']) {
								if (sizeof($subData) == 0) $subData[] = ["date_only"=>1,"date"=>$item['date']];
								$item['date_only'] = 0;
								array_push($subData, $item);
							}
						}
						foreach ($holidays as $key => $item) {
							if ($minStart == $item['date']) {
								if (sizeof($subData) == 0) $subData[] = ["date_only"=>1,"date"=>$item['date']];
								$item['date_only'] = 0;
								array_push($subData, $item);
							}
						}

						if (sizeof($subData) > 0) {
							$data = array_merge($data, $subData);
						}

						$minStart = date('Y-m-d', strtotime('+1 days', strtotime($minStart)));
					}


					$response = ['data'=>$data,'error'=>0,'error_msg'=>'','message'=>''];
					$conn->close();
				} else {
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];
				}
				return json_encode($response);
			}
		}

		public function selectSessions($request) {
			// $session = new session;
			// $dbname = $session->check_session($request);
			// $response = [];

			// if(empty($dbname))
			// 	return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			// else{
			// 	if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
			// 		$conn = $this->connect($dbname);
			// 		$datesArr = [];

			// 		//Get data based on attendance with batch
			// 		$att_sql = $conn->query("SELECT `a`.`id`, `a`.`date`, `b`.`batch` AS `title`, `b`.`id` AS `batch_id`, `a`.`in_time` AS `start_time`, `a`.`out_time` AS `end_time`, (case when `a`.`status` = '0' then 'Red' else 'Green' end) AS `color`, (case when `a`.`status` = '0' then 'Booked' else 'Attended' end) AS `status` FROM `attendance` `a` join `batch` `b` on (`a`.`batch_id` = `b`.`id`) WHERE `a`.`contact_id` = '{$request['contact_id']}' AND `a`.`negative` = '0' AND `a`.`status` IN ('0','1') AND `a`.`park` = '0' AND `a`.`date` BETWEEN '{$request['start_date']}' AND '{$request['end_date']}' ORDER BY `a`.`date` ASC");
			// 		while ($att = $att_sql->fetch_assoc()) {
			// 			array_push($datesArr, $att);
			// 		}

			// 		//Get Holidays
			// 		// $sql_holidays = $conn->query("SELECT `id`, `date`, `title`, '00:00:00' AS `start_time`, '23:59:59' AS `end_time`, 'Grey' AS `color` FROM `holiday` WHERE `park` = '0' AND `date` BETWEEN '{$request['start_date']}' AND '{$request['end_date']}'");
			// 		// while ($holiday = $sql_holidays->fetch_assoc()) {
			// 		// 	array_push($datesArr, $holiday);
			// 		// }

			// 		//Get flag for batch selection
			// 		$settings = $conn->query("SELECT `get_all_batch` FROM `attendance_settings` WHERE `bid` = '".get_bid()."'")->fetch_assoc();
			// 		if (!isset($settings['get_all_batch']))
			// 			$settings['get_all_batch'] = '0';

			// 		$whereCondition = "";
			// 		$packageArr = [];
			// 		//Get Future batches to book
			// 		$sql_sessions = $conn->query("SELECT DISTINCT `ii`.`category_id`, `ii`.`batch_id`, `ii`.`service_id`, `ii`.`start_date`, `ii`.`end_date` FROM `invoice` `i` join `invoice_item` `ii` on (`i`.`id` = `ii`.`invoice_id`) WHERE `i`.`contact_id` = '{$request['contact_id']}' AND `ii`.`end_date` > now() AND `ii`.`sessions` > 0 AND `ii`.`sessions` > `ii`.`sessions_used` AND `i`.`park` = '0'");
			// 		while ($sessions = $sql_sessions->fetch_assoc()) {
			// 			if (($settings['get_all_batch'] == '1' && strlen($whereCondition) == 0) || ($settings['get_all_batch'] != '1')) {
			// 				$whereCondition .= (strlen($whereCondition) > 0 ? ',' : '') . ($settings['get_all_batch'] == '0' ? "'{$sessions['batch_id']}'" : ($settings['get_all_batch'] == '2' ? "'{$sessions['category_id']}'" : ""));
			// 			}
			// 			array_push($packageArr, $sessions);
			// 			// if ($settings['get_all_batch'] == '1') break;
			// 		}

			// 		switch ($settings['get_all_batch']) {
			// 			case '0': //Get only respective batch
			// 				// $whereClause = " AND `b`.`id` = '{$sessions['batch_id']}'";
			// 				$whereCondition = " AND `b`.`id` IN ({$whereCondition})";
			// 				break;
			// 			case '1': //Get all batches
			// 				break;
			// 			case '2': //Get batches with same category
			// 				// $whereClause = " AND `b`.`category_id` = '{$sessions['category_id']}'";
			// 				$whereCondition = " AND `b`.`category_id` IN ({$whereCondition})";
			// 				break;
			// 		}

			// 		$batch_sql = $conn->query("SELECT `b`.`batch`,`b`.`id`, GROUP_CONCAT(`bt`.`day_code` ORDER BY `bt`.`id` ASC) AS `day_code`, GROUP_CONCAT(`bt`.`start_time` ORDER BY `bt`.`id`) AS `start_time`, GROUP_CONCAT(`bt`.`end_time` ORDER BY `bt`.`id`) AS `end_time` FROM `batch` `b` join `batch_time` `bt` on (`b`.`id` = `bt`.`batch_id`) WHERE `park` = '0' AND `bid` = '".get_bid()."' {$whereCondition} GROUP BY `b`.`id`");
			// 		// var_dump($packageArr);
			// 		while ($batch = $batch_sql->fetch_assoc()) {
			// 			//Iterate through start to end date
			// 			$start = date('Y-m-d');
			// 			$end = $request['end_date'];
			// 			while (strtotime($start) < strtotime($end)) {
			// 				//Check if user has marked for that batch.
			// 				$day = date('w', strtotime($start));
			// 				// if (in_array($day, $))
			// 				$start = date('Y-m-d', strtotime('+ 1 days ', strtotime($start)));
			// 			}					
			// 		}

			// 		$conn->close();
			// 		$response = ['data'=>$datesArr,'error'=>0,'error_msg'=>'','message'=>''];
			// 	} else {
			// 		$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];
			// 	}
			// 	return json_encode($response);
			// }
		}

		public function shiftInvoice($conn, $contact_id, $item_id, $date) {
			//Get all active invoices of provided contact
			$data_sql = $conn->query("SELECT `item_id`, `start_date`, `end_date`, `sessions`, `sessions_used`, `batch_id` FROM `invoice_invoiceitem_view` WHERE `contact_id` = '{$contact_id}' AND `start_date` <= '{$date}' AND `end_date` >= '{$date}' AND (`sessions` = 0 OR (`sessions` > 0 AND `sessions` > `sessions_used`)) AND `park` = 0 AND `item_park` = 0 ORDER BY `start_date` ASC LIMIT 2");
			if ($data_sql->num_rows > 1) {
				$existingData = array("start_date" => "", "end_date" => "", "sessions" => 0, "sessions_used" => 0, "batch_id" => 0, "days" => array(), "item_id" => 0);
				$newData = array("start_date" => "", "end_date" => "", "sessions" => 0, "sessions_used" => 0, "batch_id" => 0, "days" => array(), "item_id" => 0);
				while ($data = $data_sql->fetch_assoc()) {
					if ($data['item_id'] == $item_id) {
						$existingData['start_date'] = $data['start_date'];
						$existingData['end_date'] = $data['end_date'];
						$existingData['sessions'] = (int)$data['sessions'];
						$existingData['sessions_used'] = (int)$data['sessions_used'];
						$existingData['batch_id'] = $data['batch_id'];
						$existingData['item_id'] = $data['item_id'];
					} else {
						$newData['start_date'] = $data['start_date'];
						$newData['end_date'] = $data['end_date'];
						$newData['sessions'] = (int)$data['sessions'];
						$newData['sessions_used'] = (int)$data['sessions_used'];
						$newData['batch_id'] = $data['batch_id'];
						$newData['item_id'] = $data['item_id'];
					}
				}
				//Return if system has punched attendance for new item
				if ($newData['item_id'] == $item_id) return;
				//Get Both batch days
				$data_sql = $conn->query("SELECT `day_code`, `batch_id` FROM `batch_time` WHERE `batch_id` IN ('{$existingData['batch_id']}','{$newData['batch_id']}')");
				while($data = $data_sql->fetch_assoc()) {
					if ($data['batch_id'] == $existingData['batch_id']) {
						array_push($existingData['days'], (int)$data['day_code']);
					}
					if ($data['batch_id'] == $newData['batch_id']) {
						array_push($newData['days'], (int)$data['day_code']);
					}
				}
				//Get Start date
				$newData['start_date'] = $this->getDate($newData['days'], $existingData['end_date'], 1);
				$newData['end_date'] = $this->getDate($newData['days'], $newData['start_date'], $newData['sessions']);

				$conn->query("UPDATE `invoice_item` SET `start_date` = '{$newData['start_date']}', `end_date` = '{$newData['end_date']}' WHERE `id` = '{$newData['item_id']}'");
			}
			//Else dont do anything...
		}

		private function getDate($days, $start_date, $count) {
			$i = 0;
			while ($i < $count) {
				$i++;
				$start_date = date('Y-m-d', strtotime('+1 day', strtotime($start_date)));
				$date = date('Ymd', strtotime($start_date));
				$day = date('w', strtotime($start_date));
				$failSafe = 0;
				while (!in_array($day, $days) && $failSafe < 14) {
					$start_date = date('Y-m-d', strtotime('+1 day', strtotime($start_date)));
					$day = date('w', strtotime($start_date));
					$failSafe++;
				}
			}
			return $start_date;
		}

		public function markInvalid($request) {
			$session = new session;
			$dbname = $session->check_session($request);
			$response = [];

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),3)) {
					$conn = $this->connect($dbname);
					if (isset($request['id']) && !empty($request['id'])) {
						$paySalQuery = ", `pay_sal` = 0";
						if (isset($request['pay_sal']) && $request['pay_sal']) {
							$paySalQuery = ", `pay_sal` = 1";
						}
						$request['comment'] = $conn->escape_string($request['comment']);
						$updated = $conn->query("UPDATE `attendance` SET `invalid` = 1, `comment` = '{$request['comment']}' {$paySalQuery} WHERE `id` = '{$request['id']}'");
						if ($updated) {
							// Increment not pay sal for member if pay_sal is marked true
							if ($request['pay_sal']) {
								$conn->query("UPDATE `member` SET `pending_not_pay_sal_attendance` = `pending_not_pay_sal_attendance` + 1 WHERE `contact_id` = '{$request['contact_id']}'");     
							}                            
							// Get Invoice Details
							$invoice_item = $conn->query("SELECT `batch_id`, `start_date`, `end_date`, `sessions`, `sessions_used` FROM `invoice_item` WHERE `id` = '{$request['item_id']}'")->fetch_assoc();
							$batch_sql = $conn->query("SELECT `day_code` FROM `batch_time` WHERE `batch_id` = '{$invoice_item['batch_id']}'");
							$batch_days = [];
							while ($batch = $batch_sql->fetch_assoc()) {
								array_push($batch_days, $batch['day_code']);
							}
							if(date($invoice_item['end_date']) < date('Y-m-d')){//extend end dates only for past end dates not for future end dates as check_end_session.php covers up that point
								$invoice_item['end_date'] = $this->getDate($batch_days, $invoice_item['end_date'], 1);
							}
							$invoice_item['sessions'] = $invoice_item['sessions']  + 1;
							// Update Invoice Details
							$conn->query("UPDATE `invoice_item` SET `end_date` = '{$invoice_item['end_date']}', `sessions` = {$invoice_item['sessions']} WHERE `id` = '{$request['item_id']}'");
							$conn->query("INSERT INTO `invoice_change_log`(`invoice_id`, `from_item_id`, `change`, `comment`, `created_by`) VALUES ('{$request['invoice_id']}','{$request['item_id']}','1','Extension given due to invalid session on {$request['date']}.\nComment: {$request['comment']}','".get_uid()."')");
							$response = ['data' => [], 'error'=> 0,  'error_msg' => '', 'message' => 'Attendance Marked Invalid'];
						} else {
							$response = ['data' => [], 'error'=> 1,  'error_msg' => 'Update Failed', 'message' => 'Oops!! Something went wrong'];
						}
					} else {
						$response = ['data' => [], 'error'=> 1,  'error_msg' => 'Please Send Attendance ID', 'message' => 'Please Send Attendance ID'];
					}
				} else {
					$response = ['data'=>[],'error'=>3,'error_msg'=>'access denied','message'=>'You dont have enough privileges'];
				}
				return json_encode($response);
			}
		}

		public function total_login_time($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				// if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$conn = $this->connect($dbname);
					
					$build = new querybuilder;
					$query = "SELECT `contact_id`, SEC_TO_TIME( SUM( TIME_TO_SEC( valid_hrs ) ) ) AS `total_valid_worked_time`, SUM( TIME_TO_SEC( valid_hrs ) ) AS  `total_valid_worked_time_sec`, SUM(`valid_day`) AS `valid_day`,SUM(`invalid_day`) AS `invalid_day`, monthly_salary, workshift_time FROM emp_attendance_view WHERE `contact_id` = '{$request['contact']}' AND (`date` BETWEEN '{$request['start_date']}' AND '{$request['end_date']}') AND DAYNAME(`date`) != 'Sunday'";
					// $query = "SELECT `contact_id`,(SEC_TO_TIME( SUM( TIME_TO_SEC( valid_hrs ) ) ),0) AS `total_valid_worked_time`, ROUND(SUM( TIME_TO_SEC( valid_hrs ) )) AS  `total_valid_worked_time_sec`, SUM(`valid_day`) AS `valid_day`,SUM(`invalid_day`) AS `invalid_day`, monthly_salary, workshift_time FROM emp_attendance_view WHERE `contact_id` = '{$request['contact']}' AND (`date` BETWEEN '{$request['start_date']}' AND '{$request['end_date']}')";

					$result = $conn->query($query);

					// var_dump($query);exit;

					if($result->num_rows){
						$response['data'] = array();
						while ($row = $result->fetch_assoc()){
							$request['monthly_salary'] = $row['monthly_salary'];
							// round( $this->time_in_sec($row['workshift_time'])* intval($row['valid_day'])/3600,2);

							// total valid time in sec
							$max_valid_time_sec = $this->time_in_sec($row['workshift_time']) * intval($row['valid_day']);
							$max_valid_time_hrs = $this->time_in_sec($row['workshift_time'])/3600;
							// echo $max_valid_time_hrs;
							// total valid worked time in sec
							$total_valid_worked_time_sec = $row['total_valid_worked_time_sec'];

							// regularise total work time
							if($total_valid_worked_time_sec >= $max_valid_time_sec){
								$total_valid_worked_time_sec = $max_valid_time_sec;
							}

							if (!function_exists('format_time')) {
								function format_time($t,$f=':') // t = seconds, f = separator 
								{
									return sprintf("%02d%s%02d%s%02d", floor($t/3600), $f, ($t/60)%60, $f, $t%60);
								}
							}

							$max_valid_time_hrs_str =  format_time((int)$max_valid_time_sec); // 00:11:25

							$total_valid_worked_time_days = !empty($max_valid_time_hrs) ? round(((int)$total_valid_worked_time_sec/3600)/$max_valid_time_hrs,2) : 0;
							$daily_wage = !empty($request['monthly_salary']) ? $this->calculate_daily_wage($request) : 0;
							$total_salary = round($total_valid_worked_time_days * $daily_wage,2);
							// echo $total_salary; exit;
							$response['data']['total_salary'] = $total_salary;
							$response['data']['max_valid_time_hrs'] = $max_valid_time_hrs_str;
							array_push($response['data'],$row);
						}
						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
					}
					else{
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'No valid hours done';
						$response['message'] = 'Not found';
					}

					$conn->close();
				}
			return json_encode($response);
		}


		public function total_current_login($request){
			$session = new session;
			$dbname = $session->check_session($request);

			if(empty($dbname))
				return json_encode(['error'=>2,'error_msg'=>'could not find db','message'=>'check session id or token']);
			else{
				// if($session->access(!empty(get_uid())?get_uid():$request['uid'],$this->module('Attendance'),1)){
					$conn = $this->connect($dbname);
					
					$build = new querybuilder;
					$query = "SELECT `contact_id`, in_time, out_time, `status`, `total_time` FROM emp_attendance_view WHERE `contact_id` = '{$request['contact']}' AND `date` = CURDATE() AND park = 0";

					$result = $conn->query($query);

					// var_dump($query);exit;

					if($result->num_rows){
						$response['data'] =array();
						while ($row = $result->fetch_assoc())
							array_push($response['data'],$row);
						$response['error'] = 0;
						$response['error_msg'] = '';
						$response['message'] = '';
					}
					else{
						$response['data'] = array();
						$response['error'] = 1;
						$response['error_msg'] = 'Not punched in';
						$response['message'] = 'Not found';
					}

					$conn->close();
				}
			return json_encode($response);
		}

		
		private function calculate_daily_wage($request) {
			$d = date_parse_from_format("Y-m-d", $request['start_date']);
			// $daily_wage = $employee['salary'] / cal_days_in_month(CAL_GREGORIAN,$d["month"],$d["year"]);
	
			$workdays = array();
			$type = CAL_GREGORIAN;
			$month = date($d["month"]); // Month ID, 1 through to 12.
			$year = date($d["year"]); // Year in 4 digit 2009 format.
			$day_count = cal_days_in_month($type, $month, $year); // Get the amount of days
	
			//loop through all days
			for ($i = 1; $i <= $day_count; $i++) {
	
					$date = $year.'/'.$month.'/'.$i; //format date
					$get_name = date('l', strtotime($date)); //get week day
					$day_name = substr($get_name, 0, 3); // Trim day name to 3 chars
	
					//if not a Sunday add day to array
					if($day_name != 'Sun'){
						$workdays[] = $i;
					}
	
			}
			//print_r($workdays);
			//echo sizeof($workdays);
			// need to check pulic holidays also for that month.
			
			$days_to_pay = sizeof($workdays);//excluding Sundays and Holidays
			$daily_wage = round(($request['monthly_salary'] / $days_to_pay),2); // upto 2 decimal values
			return $daily_wage;
		}

		private function time_in_sec($time = "0"){  //time converted in seconds
			$seconds = 0;
			$time_in_sec = $time;
			$parsed = date_parse($time_in_sec);
			$seconds = $parsed['hour'] * 3600 + $parsed['minute'] * 60 + $parsed['second'];
			return $seconds;
		}
	}


?>
