<?php
require_once '../inc/headers.php';
require_once '../controllers/faceExperimentStatus.php';
$request = json_decode(file_get_contents('php://input'), true);
$request = is_array($request) ? $request : (is_array($_POST) ? $_POST : []);
if (($_GET['action'] ?? 'status') !== 'status') { echo json_encode(['error' => 1, 'error_msg' => 'Invalid action', 'data' => []]); exit; }
echo (new faceExperimentStatus())->status($request);
