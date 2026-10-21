<?php

/**
 * FACE-ENR-03: private enrollment inference runtime supervision.
 *
 * This test is read-only. It never sends frames or writes application data.
 */

$root = dirname(__DIR__, 2);
$unit = $root . '/services/face-inference/systemd/face-inference.service';
$failures = [];

if (!is_file($unit)) {
	$failures[] = 'restartable face-inference service definition is missing';
} else {
	$contents = (string)file_get_contents($unit);
	foreach ([
		'User=ccc-face-inference',
		'ExecStart=/var/lib/creativeculturalcentre/face-inference/venv/bin/python -m app.main',
		'Restart=on-failure',
		'RestartSec=10s',
		'OOMScoreAdjust=500',
		'NoNewPrivileges=true',
		'ProtectSystem=strict',
	] as $required) {
		if (strpos($contents, $required) === false) {
			$failures[] = "service definition is missing {$required}";
		}
	}
}

$url = rtrim((string)(getenv('FACE_INFERENCE_URL') ?: 'http://127.0.0.1:6120'), '/');
if (!preg_match('#^http://(?:127\.0\.0\.1|localhost)(?::[0-9]+)?$#', $url)) {
	$failures[] = 'FACE_INFERENCE_URL must remain loopback-only';
} elseif (!function_exists('curl_init')) {
	$failures[] = 'PHP cURL is unavailable';
} else {
	$curl = curl_init($url . '/health');
	curl_setopt_array($curl, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 2,
		CURLOPT_TIMEOUT => 5,
	]);
	$response = curl_exec($curl);
	$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
	curl_close($curl);
	$health = is_string($response) ? json_decode($response, true) : null;
	if ($status !== 200 || !is_array($health)) {
		$failures[] = "private health endpoint is unavailable (HTTP {$status})";
	} else {
		if (($health['model']['model_ready'] ?? false) !== true) {
			$failures[] = 'inference model is not ready';
		}
		if (($health['model_integrity_verified'] ?? false) !== true) {
			$failures[] = 'inference model integrity is not verified';
		}
		if (!array_key_exists('calibration_approved', $health) || !is_bool($health['calibration_approved'])) {
			$failures[] = 'Testing runtime calibration_approved must be a boolean';
		}
	}
}

if ($failures) {
	foreach ($failures as $failure) {
		fwrite(STDERR, "FAIL FACE-ENR-03 {$failure}\n");
	}
	fwrite(STDERR, count($failures) . " FACE-ENR-03 checks failed.\n");
	exit(1);
}

fwrite(STDOUT, "PASS FACE-ENR-03 restartable private inference runtime is healthy\n");
