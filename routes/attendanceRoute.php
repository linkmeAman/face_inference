<?php
	require_once "../inc/headers.php";
	require_once "../controllers/attendance.php";

	$response = array();
	$_POST = json_decode(file_get_contents("php://input"), true) ?? $_POST;

	require_once __DIR__.'/../classes/services/ModuleFlowRuntime.php';
	$_POST = is_array($_POST) ? $_POST : [];
	ModuleFlowRuntime::start('attendance', (string)($_GET['action'] ?? ''), $_POST);

    if(in_array($_GET['action']??'', ['insert','insertBio'],true)){require_once __DIR__.'/../classes/services/ModuleAttendanceEntry.php';if($_GET['action']==='insert')echo json_encode(ModuleAttendanceEntry::record($_POST));else{$results=[];foreach($_POST['i']??[] as $person)$results[]=ModuleAttendanceEntry::record(array_merge($_POST,$person));echo json_encode(ModuleFlowRuntime::response(0,'Device attendance processed','',['results'=>$results]));}exit;}

	if(in_array($_GET['action']??'', ['update','park','delete'],true)){
		require_once __DIR__.'/../classes/services/AttendanceLedger.php';
		echo json_encode(AttendanceLedger::handle($_GET['action'],$_POST));exit;
	}

	switch ($_GET['action']) {
		case 'insert':
			$att = new attendance;
			echo $att->insert_attendance($_POST);
			break;

		case 'insertBio':
			$att = new attendance;
			echo $att->insert_attendance_via_bio($_POST);
			break;

		case 'insertBatchAttendance':
			$att = new attendance;
			echo $att->insert_batch_attendance($_POST);
			break;

		case 'insertBatchAttendanceApp':
			$att = new attendance;
			echo $att->insert_batch_attendance_app($_POST);
			break;

		case 'batchLog':
			$att = new attendance;
			echo $att->insert_batch_log($_POST);
			break;

		case 'update':
			$att = new attendance;
			echo $att->update_attendance($_POST);
			break;

		case 'delete':
			$att = new attendance;
			echo method_exists($att, 'delete_attendance') ? $att->delete_attendance($_POST) : $att->park_attendance($_POST);
			break;

		case 'park':
			$att = new attendance;
			echo $att->park_attendance($_POST);
			break;

		case 'select':
			$att = new attendance;
			echo $att->select_attendance($_POST);
			break;

		case 'search':
			$att = new attendance;
			echo $att->search($_POST);
			break;

		case 'hasBatch':
			$att = new attendance;
			echo $att->has_batch($_POST);
			break;

		case 'selectBatchSessionAtt':
			$att = new attendance;
			echo $att->selectBatchSessionAtt($_POST);
			break;

		case 'markInvalid':
			$att = new attendance;
			echo $att->markInvalid($_POST);
			break;
		
		case 'insertZoomLog':
			$att = new attendance;
			echo $att->insert_zoom_log($_POST);
			break;

		case 'totalTime':
			$att = new attendance;
			echo $att->total_login_time($_POST);
			break;

		case 'totalCurrentLogin':
			$att = new attendance;
			echo $att->total_current_login($_POST);
			break;
		
		default:
			$response['data'] = array();
			$response['error'] = 1;
			$response['error_msg'] = 'request not match';
			$response['message'] = 'Bad request';
			echo json_encode($response);
			break;
	}

	

?>
