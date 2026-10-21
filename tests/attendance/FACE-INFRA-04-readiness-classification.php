<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers/face-api-recovery-fixture.php';
require_once dirname(__DIR__, 2) . '/classes/clients/FaceInferenceClient.php';

// Real cURL transport, synthetic loopback server, no inference-service traffic.
if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !function_exists('curl_init')) {
    fwrite(STDERR, "BLOCKED: pcntl, posix and curl are required\n"); exit(2);
}
$listener = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if (!$listener) { fwrite(STDERR, "BLOCKED: loopback fixture cannot bind\n"); exit(2); }
$address = stream_socket_get_name($listener, false);
$pid = pcntl_fork();
if ($pid === -1) { fclose($listener); fwrite(STDERR, "BLOCKED: fixture fork failed\n"); exit(2); }
if ($pid === 0) {
    $deadline = microtime(true) + 40;
    while (microtime(true) < $deadline) {
        $socket = @stream_socket_accept($listener, 1);
        if (!$socket) continue;
        stream_set_timeout($socket, 3);
        $line = fgets($socket);
        while (($header = fgets($socket)) !== false && trim($header) !== '') {}
        $mode = explode('/', explode(' ', $line ?: '')[1] ?? '')[1] ?? '';
        $status = '200 OK'; $body = '{"status":"ready"}';
        if ($mode === 'unready') {
            $status = '503 Service Unavailable';
            $body = '{"status":"not_ready","model_ready":false,"identity_calibration_approved":false}';
        } elseif ($mode === 'busy') {
            $status = '503 Service Unavailable'; $body = '{"error":"CONCURRENCY_LIMIT_EXCEEDED"}';
        } elseif ($mode === 'malformed') { $body = 'not-json'; }
        elseif ($mode === 'timeout') { usleep(2300000); }
        @fwrite($socket, "HTTP/1.1 {$status}\r\nContent-Type: application/json\r\nConnection: close\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body);
        fclose($socket);
    }
    fclose($listener); exit(0);
}
fclose($listener);
$makeClient = function (string $mode) use ($address): FaceInferenceClient {
    return new FaceInferenceClient('http://' . $address . '/' . $mode, 'synthetic-fixture-token');
};
$passed = true;
try {
    $passed = recoveryCase('legacy_boolean_readiness_transport', function () use ($makeClient): void {
        recoveryAssert($makeClient('ready')->checkReadiness(), 'ready HTTP fixture must succeed');
        foreach (['unready', 'busy', 'malformed', 'timeout'] as $mode) {
            recoveryAssert(!$makeClient($mode)->checkReadiness(), $mode . ' must not report ready');
        }
    }) && $passed;
    $passed = recoveryCase('readiness_failure_classification', function () use ($makeClient): void {
        recoveryAssert(is_callable([$makeClient('ready'), 'readinessStatus']), 'concrete client must expose optional readiness classification');
        foreach ([['ready', true, false, 'READY'], ['unready', false, false, 'SYSTEM_NOT_READY'],
            ['busy', false, true, 'INFERENCE_BUSY'], ['malformed', false, false, 'SYSTEM_NOT_READY'],
            ['timeout', false, true, 'INFERENCE_TIMEOUT']] as [$mode, $ready, $retryable, $reason]) {
            $result = $makeClient($mode)->readinessStatus();
            recoveryAssert(($result['ready'] ?? null) === $ready && ($result['retryable'] ?? null) === $retryable
                && ($result['reason'] ?? '') === $reason, 'readiness classification mismatch: ' . $mode);
        }
    }) && $passed;
} finally {
    posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status);
}
$passed = recoveryCase('offline_transport_is_not_ready', function () use ($makeClient): void {
    recoveryAssert(!$makeClient('ready')->checkReadiness(), 'closed fixture port must be unavailable');
}) && $passed;
$passed = recoveryCase('offline_readiness_classification', function () use ($makeClient): void {
    $client = $makeClient('ready');
    recoveryAssert(is_callable([$client, 'readinessStatus']), 'concrete client must classify connection refusal');
    $result = $client->readinessStatus();
    recoveryAssert(($result['ready'] ?? null) === false && ($result['retryable'] ?? null) === true
        && ($result['reason'] ?? '') === 'INFERENCE_OFFLINE', 'connection refusal is retryable offline');
}) && $passed;
exit($passed ? 0 : 1);
