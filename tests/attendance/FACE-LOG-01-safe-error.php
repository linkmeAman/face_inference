<?php

declare(strict_types=1);

/** Safe PHP face-error logging test, created before the logger implementation. */

$root = dirname(__DIR__, 2);
$loggerPath = $root . '/classes/services/FaceErrorLogger.php';
if (!is_file($loggerPath)) {
    fwrite(STDERR, "FAIL FACE-LOG-01 safe logger: FaceErrorLogger.php is missing (expected pre-change baseline).\n");
    exit(1);
}
require_once $loggerPath;

$safe = FaceErrorLogger::sanitize([
    'scan_id' => 'scan-1',
    'frames' => ['raw-frame'],
    'embedding' => [0.1, 0.2],
    'FACE_INFERENCE_SHARED_SECRET' => 'secret',
    'reason' => 'TIMEOUT',
]);
foreach (['raw-frame', '0.1', 'secret'] as $forbidden) {
    if (strpos(json_encode($safe), $forbidden) !== false) {
        fwrite(STDERR, "FAIL FACE-LOG-01 safe logger: sensitive value was retained.\n");
        exit(1);
    }
}
if (($safe['reason'] ?? null) !== 'TIMEOUT') {
    fwrite(STDERR, "FAIL FACE-LOG-01 safe logger: safe diagnostic reason was lost.\n");
    exit(1);
}
fwrite(STDOUT, "PASS FACE-LOG-01 safe error logger\n");
exit(0);
