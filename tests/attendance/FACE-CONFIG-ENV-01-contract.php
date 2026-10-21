<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$nginx = (string)file_get_contents($root . '/nginx-face-params.conf');
$config = (string)file_get_contents($root . '/classes/services/FaceAttendanceConfig.php');
$route = (string)file_get_contents($root . '/routes/v1/face-attendance.php');
$scanService = (string)file_get_contents($root . '/classes/services/FaceAttendanceApiService.php');
$pilotSave = (string)file_get_contents($root . '/pf/face-pilot-config-save.php');
$env = (string)file_get_contents($root . '/.env');
$failures = [];

function faceConfigEnvCheck(bool $condition, string $message): void
{
	global $failures;
	if (!$condition) $failures[] = $message;
}

faceConfigEnvCheck(!preg_match('/^\s*fastcgi_param\s+FACE_/m', $nginx), 'Nginx still injects FACE_* FastCGI variables');
faceConfigEnvCheck(strpos($config, 'self::getEnv($envKey)') !== false, '.env values do not override legacy database flags');
faceConfigEnvCheck(strpos($config, "if (!\$loadedFromDb) {\n            foreach (self::COLUMN_MAP") === false, '.env override is incorrectly skipped when legacy database settings exist');
faceConfigEnvCheck(strpos($route, "FaceAttendanceConfig::getEnv('FACE_ATTENDANCE_ALLOWED_ORIGINS')") !== false, 'v1 origin policy does not use the shared environment loader');
faceConfigEnvCheck(strpos($scanService, "FaceAttendanceConfig::getEnv('FACE_ALLOWED_BRANCH_IDS')") !== false, 'branch allowlist does not use the shared environment loader');
faceConfigEnvCheck(strpos($scanService, "FaceAttendanceConfig::getEnv('FACE_CAPTURE_NONCE_REQUIRED')") !== false, 'capture nonce policy does not use the shared environment loader');
faceConfigEnvCheck(strpos($pilotSave, '/etc/nginx/') === false && strpos($pilotSave, 'fastcgi_param') === false, 'pilot settings endpoint still edits Nginx');
foreach (['FACE_ATTENDANCE_LIVE_WRITE', 'FACE_AUTO_LIVE_WRITE_BRANCH_IDS', 'FACE_CAPTURE_NONCE_REQUIRED', 'FACE_ALLOWED_BRANCH_IDS'] as $key) {
	faceConfigEnvCheck(preg_match('/^' . preg_quote($key, '/') . '=/m', $env) === 1, ".env is missing {$key}");
}

if ($failures) {
	fwrite(STDERR, "FAIL FACE-CONFIG-ENV-01 contract\n - " . implode("\n - ", $failures) . "\n");
	exit(1);
}
echo "PASS FACE-CONFIG-ENV-01 contract\n";
