<?php

declare(strict_types=1);

/** FACE-ENR-01 route contract, created before the enrollment route. */

$root = dirname(__DIR__, 2);
$controller = $root . '/controllers/faceEnrollment.php';
$route = $root . '/routes/faceEnrollmentRoute.php';

if (!is_file($controller) || !is_file($route)) {
    fwrite(STDERR, "FAIL FACE-ENR-01 route contract: enrollment route/controller are missing (expected pre-change baseline).\n");
    exit(1);
}

$controllerSource = file_get_contents($controller);
$routeSource = file_get_contents($route);
foreach (['enrollFace', 'withdrawFace', 'teacher_confirmed_member', 'FaceEnrollmentService', '(int)($request["member_id"] ?? 0) < 1'] as $needle) {
    if (strpos((string)$controllerSource, $needle) === false) {
        fwrite(STDERR, "FAIL FACE-ENR-01 route contract: controller missing {$needle}.\n");
        exit(1);
    }
}
if (strpos((string)$routeSource, 'multipart') === false && strpos((string)$routeSource, '_FILES') === false) {
    fwrite(STDERR, "FAIL FACE-ENR-01 route contract: route does not accept frame uploads.\n");
    exit(1);
}
if (preg_match('/error_log\s*\([^)]*(frames|embedding|secret)/i', (string)$controllerSource)) {
    fwrite(STDERR, "FAIL FACE-ENR-01 route contract: raw biometric material appears in logging.\n");
    exit(1);
}

fwrite(STDOUT, "PASS FACE-ENR-01 enrollment route contract\n");
exit(0);
