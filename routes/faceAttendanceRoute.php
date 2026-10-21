<?php
	require_once "../inc/headers.php";
	require_once "../controllers/faceAttendance.php";

	$request = json_decode(file_get_contents("php://input"), true);
	$request = is_array($request) ? $request : (is_array($_POST) ? $_POST : []);
	// Multipart camera requests carry their action in the form body. Keep the
	// query-string form for existing API callers, but do not rely on it for a
	// live preview request.
	$action = (string)($_GET['action'] ?? ($request['action'] ?? ''));
	if ($action === 'recognizeFaceAttempt' && isset($_FILES['frames'])) {
		$request['frames'] = [];
		$files = $_FILES['frames'];
		$tmpNames = is_array($files['tmp_name']) ? $files['tmp_name'] : [$files['tmp_name']];
		foreach ($tmpNames as $tmpName) {
			if (is_string($tmpName) && is_uploaded_file($tmpName)) {
				$frame = file_get_contents($tmpName);
				if (is_string($frame)) {
					$request['frames'][] = $frame;
				}
			}
		}
	}
	if ($action === 'previewFace' && isset($_FILES['frame']['tmp_name'])) {
		$tmpName = $_FILES['frame']['tmp_name'];
		if (is_string($tmpName) && is_uploaded_file($tmpName)) {
			$frame = file_get_contents($tmpName);
			if (is_string($frame)) {
				$request['frame'] = $frame;
			}
		}
	}
	$controller = new faceAttendance;
	echo $controller->handle($action, $request);
