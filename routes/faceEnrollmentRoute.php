<?php
	require_once "../inc/headers.php";
	require_once "../controllers/faceEnrollment.php";

	$action = (string)($_GET['action'] ?? '');
	$request = json_decode(file_get_contents("php://input"), true);
	$request = is_array($request) ? $request : (is_array($_POST) ? $_POST : []);
	if (empty($request['session_id']) && !empty($_COOKIE[session_name()])) {
		$request['session_id'] = $_COOKIE[session_name()];
	}
	if ($action === 'onboardReference') {
		if (isset($_FILES['portraits']['tmp_name'])) {
			$request['portraits'] = [];
			$tmpNames = is_array($_FILES['portraits']['tmp_name']) ? $_FILES['portraits']['tmp_name'] : [$_FILES['portraits']['tmp_name']];
			foreach ($tmpNames as $tmpName) {
				if (is_string($tmpName) && is_uploaded_file($tmpName)) {
					$frame = file_get_contents($tmpName);
					if (is_string($frame) && $frame !== '') {
						$request['portraits'][] = $frame;
					}
				}
			}
		} elseif (isset($_FILES['portrait']['tmp_name']) && is_uploaded_file($_FILES['portrait']['tmp_name'])) {
			$request['portrait'] = file_get_contents($_FILES['portrait']['tmp_name']);
		} elseif (!empty($request['portraits']) && is_array($request['portraits'])) {
			foreach ($request['portraits'] as $i => $frame) {
				if (is_string($frame) && strpos($frame, ';base64,') !== false) {
					$parts = explode(';base64,', $frame);
					$request['portraits'][$i] = base64_decode($parts[1]);
				}
			}
		} elseif (!empty($request['portrait']) && is_string($request['portrait'])) {
			if (strpos($request['portrait'], ';base64,') !== false) {
				$parts = explode(';base64,', $request['portrait']);
				$request['portrait'] = base64_decode($parts[1]);
			}
		}
	}
	if ($action === 'enrollFace') {
		if (isset($_FILES['burst_a'])) {
			$request['burst_a'] = [];
			$filesA = $_FILES['burst_a'];
			$tmpNamesA = is_array($filesA['tmp_name']) ? $filesA['tmp_name'] : [$filesA['tmp_name']];
			foreach ($tmpNamesA as $tmpName) {
				if (is_string($tmpName) && is_uploaded_file($tmpName)) {
					$frame = file_get_contents($tmpName);
					if (is_string($frame)) {
						$request['burst_a'][] = $frame;
					}
				}
			}
		} elseif (!empty($request['burst_a']) && is_array($request['burst_a'])) {
			foreach ($request['burst_a'] as $i => $frame) {
				if (is_string($frame) && strpos($frame, ';base64,') !== false) {
					$parts = explode(';base64,', $frame);
					$request['burst_a'][$i] = base64_decode($parts[1]);
				}
			}
		}

		if (isset($_FILES['burst_b'])) {
			$request['burst_b'] = [];
			$filesB = $_FILES['burst_b'];
			$tmpNamesB = is_array($filesB['tmp_name']) ? $filesB['tmp_name'] : [$filesB['tmp_name']];
			foreach ($tmpNamesB as $tmpName) {
				if (is_string($tmpName) && is_uploaded_file($tmpName)) {
					$frame = file_get_contents($tmpName);
					if (is_string($frame)) {
						$request['burst_b'][] = $frame;
					}
				}
			}
		} elseif (!empty($request['burst_b']) && is_array($request['burst_b'])) {
			foreach ($request['burst_b'] as $i => $frame) {
				if (is_string($frame) && strpos($frame, ';base64,') !== false) {
					$parts = explode(';base64,', $frame);
					$request['burst_b'][$i] = base64_decode($parts[1]);
				}
			}
		}

		if (isset($_FILES['frames'])) {
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
		} elseif (!empty($request['frames']) && is_array($request['frames'])) {
			foreach ($request['frames'] as $i => $frame) {
				if (is_string($frame) && strpos($frame, ';base64,') !== false) {
					$parts = explode(';base64,', $frame);
					$request['frames'][$i] = base64_decode($parts[1]);
				}
			}
		}
	}
	if ($action === 'checkEarlyEnrollmentIdentity') {
		$request['frames'] = [];
		if (isset($_FILES['frames']['tmp_name']) && is_array($_FILES['frames']['tmp_name'])) {
			foreach ($_FILES['frames']['tmp_name'] as $tmpName) {
				if (is_string($tmpName) && is_uploaded_file($tmpName)) {
					$request['frames'][] = file_get_contents($tmpName);
				}
			}
		}
	}
	if ($action === 'previewEnrollment' || $action === 'checkEnrollmentCandidate') {
		if (isset($_FILES['frame']['tmp_name'])) {
			$tmpName = $_FILES['frame']['tmp_name'];
			if (is_string($tmpName) && is_uploaded_file($tmpName)) {
				$frame = file_get_contents($tmpName);
				if (is_string($frame)) {
					$request['frame'] = $frame;
				}
			}
		} elseif (!empty($request['frame']) && is_string($request['frame'])) {
			if (strpos($request['frame'], ';base64,') !== false) {
				$parts = explode(';base64,', $request['frame']);
				$request['frame'] = base64_decode($parts[1]);
			}
		}
	}
	if ($action === 'getReferencePortrait') {
		if (empty($request['contact_id']) && !empty($_GET['contact_id'])) {
			$request['contact_id'] = (int)$_GET['contact_id'];
		}
		if (empty($request['member_id']) && !empty($_GET['member_id'])) {
			$request['member_id'] = (int)$_GET['member_id'];
		}
		if (empty($request['branch_id']) && !empty($_GET['branch_id'])) {
			$request['branch_id'] = (int)$_GET['branch_id'];
		}
		$controller = new faceEnrollment;
		$controller->streamReferencePortrait($request);
		exit;
	}
	$controller = new faceEnrollment;
	echo $controller->handle($action, $request);
