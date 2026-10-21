<?php

declare(strict_types=1);

/**
 * FACE-ML-03 private inference availability check.
 *
 * Read-only: this verifies the loopback health contract and never sends a
 * camera frame, initializes a scan, or writes application data.
 */

$url = rtrim(getenv('FACE_INFERENCE_URL') ?: 'http://127.0.0.1:6120', '/');
$context = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
$response = @file_get_contents($url . '/health', false, $context);
$statusLine = $http_response_header[0] ?? '';
$status = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;
$body = is_string($response) ? json_decode($response, true) : null;

if ($status !== 200 || !is_array($body) || ($body['status'] ?? '') !== 'ok') {
    fwrite(STDERR, "FAIL FACE-ML-03 service availability: health endpoint unavailable (HTTP {$status}).\n");
    exit(1);
}

if (empty($body['model']['model_ready']) || empty($body['model_integrity_verified'])) {
    fwrite(STDERR, "FAIL FACE-ML-03 service availability: private service is reachable but model is not ready.\n");
    exit(1);
}

fwrite(STDOUT, "PASS FACE-ML-03 private inference health/model readiness\n");
