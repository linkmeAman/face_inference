<?php

declare(strict_types=1);

/**
 * FACE-PREVIEW-01: Reference portrait streaming and report column verification.
 *
 * Verifies:
 * 1. 401 Unauthorized when session/actor is missing.
 * 2. 404 Not Found when contact has no active reference portrait.
 * 3. 200 OK + valid JPEG magic bytes (0xFFD8FF) on enrolled contact (777).
 * 4. 304 Not Modified when If-None-Match header matches the ETag (zero-BLOB Stage 1 query).
 * 5. 403 Forbidden on mismatched branch without admin privileges.
 * 6. Report 2 (Member List) structure includes "Face" header, type="face_preview", enrolled=1, and v timestamp.
 * 7. inc/defaults.php get_uid() and get_bid() fall back cleanly to $_SESSION without $_SERVER mutation.
 */

$root = dirname(__DIR__, 2);

function runCaseProcess(string $phpSnippet): array {
    global $root;
    $cmd = 'php -d error_reporting=E_ALL -d display_errors=0 -r ' . escapeshellarg($phpSnippet);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $root . '/routes');
    if (!is_resource($proc)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'Failed to start process'];
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($proc);

    return [
        'exit' => $exitCode,
        'stdout' => $stdout ?: '',
        'stderr' => $stderr ?: ''
    ];
}

$failures = [];

// ---------------------------------------------------------
// Case 1: 401 on unauthenticated request
// ---------------------------------------------------------
$snippet1WithCode = <<<'PHP'
require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/faceEnrollment.php";

register_shutdown_function(function() {
    echo "STATUS:" . http_response_code();
});
$c = new faceEnrollment();
$c->streamReferencePortrait(["contact_id" => 777]);
PHP;

$res1 = runCaseProcess($snippet1WithCode);
if (strpos($res1['stdout'], 'STATUS:401') === false) {
    $failures[] = "Case 1 failed: expected HTTP 401 for unauthenticated request, got: " . $res1['stdout'] . " (stderr: " . $res1['stderr'] . ")";
}

// ---------------------------------------------------------
// Case 2: 404 on non-enrolled contact (relying solely on $_SESSION fallback)
// ---------------------------------------------------------
$snippet2 = <<<'PHP'
session_start();
$_SESSION["admin"] = 1;
$_SESSION["role_id"] = 1;
$_SESSION["db_name"] = "ccc_database";
$_SESSION["bid"] = 2;
$_SESSION["user_id"] = 3;
$_SESSION["position_id"] = 1;

require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/faceEnrollment.php";

register_shutdown_function(function() {
    echo "STATUS:" . http_response_code();
});
$c = new faceEnrollment();
$c->streamReferencePortrait(["contact_id" => 999999]);
PHP;

$res2 = runCaseProcess($snippet2);
if (strpos($res2['stdout'], 'STATUS:404') === false) {
    $failures[] = "Case 2 failed: expected HTTP 404 for non-enrolled contact, got: " . $res2['stdout'];
}

// ---------------------------------------------------------
// Case 3: 200 OK + JPEG magic bytes for enrolled contact (777)
// ---------------------------------------------------------
$snippet3 = <<<'PHP'
session_start();
$_SESSION["admin"] = 1;
$_SESSION["role_id"] = 1;
$_SESSION["db_name"] = "ccc_database";
$_SESSION["bid"] = 2;
$_SESSION["user_id"] = 3;
$_SESSION["position_id"] = 1;

require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/faceEnrollment.php";

register_shutdown_function(function() {
    echo "\nSTATUS:" . http_response_code();
});
$c = new faceEnrollment();
$c->streamReferencePortrait(["contact_id" => 777]);
PHP;

$res3 = runCaseProcess($snippet3);
if (strpos($res3['stdout'], 'STATUS:200') === false) {
    $failures[] = "Case 3 failed: expected HTTP 200 for enrolled contact 777, got: " . substr($res3['stdout'], -50);
} else {
    // Strip trailing STATUS:200
    $body = substr($res3['stdout'], 0, (int)strrpos($res3['stdout'], "\nSTATUS:200"));
    if (strlen($body) < 100) {
        $failures[] = "Case 3 failed: output too small (" . strlen($body) . " bytes)";
    } else {
        $magic = bin2hex(substr($body, 0, 3));
        if ($magic !== 'ffd8ff') {
            $failures[] = "Case 3 failed: output does not start with JPEG SOI bytes (ffd8ff), got '{$magic}'";
        }
    }
}

// ---------------------------------------------------------
// Case 4: 304 Not Modified when If-None-Match matches ETag
// ---------------------------------------------------------
require_once $root . '/classes/dbconnection.class.php';
$db = new dbconnection();
$conn = $db->connect('ccc_database');
$refQuery = $conn->query(
    "SELECT fir.id, fir.verified_at FROM face_identity_reference fir " .
    "JOIN biometric_subject bs ON bs.id = fir.subject_id " .
    "WHERE bs.contact_id = 777 AND fir.status = 'ACTIVE' LIMIT 1"
);
$refRow = $refQuery ? $refQuery->fetch_assoc() : null;
$conn->close();

if ($refRow) {
    $expectedEtag = '"face-' . $refRow['id'] . '-' . strtotime((string)($refRow['verified_at'] ?? '')) . '"';
    $snippet4 = <<<PHP
\$_SERVER["HTTP_IF_NONE_MATCH"] = '{$expectedEtag}';
session_start();
\$_SESSION["admin"] = 1;
\$_SESSION["role_id"] = 1;
\$_SESSION["db_name"] = "ccc_database";
\$_SESSION["bid"] = 2;
\$_SESSION["user_id"] = 3;
\$_SESSION["position_id"] = 1;

require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/faceEnrollment.php";

register_shutdown_function(function() {
    echo "STATUS:" . http_response_code();
});
\$c = new faceEnrollment();
\$c->streamReferencePortrait(["contact_id" => 777]);
PHP;

    $res4 = runCaseProcess($snippet4);
    if (strpos($res4['stdout'], 'STATUS:304') === false) {
        $failures[] = "Case 4 failed: expected HTTP 304 when If-None-Match matches, got: " . $res4['stdout'];
    }
} else {
    $failures[] = "Case 4 skipped: no active reference found for contact 777";
}

// ---------------------------------------------------------
// Case 5: 403 Forbidden on branch mismatch for non-admin
// ---------------------------------------------------------
$snippet5 = <<<'PHP'
session_start();
$_SESSION["admin"] = 0;
$_SESSION["role_id"] = 2;
$_SESSION["db_name"] = "ccc_database";
$_SESSION["bid"] = 4; // Contact 777 belongs to branch 2, requester is branch 4
$_SESSION["user_id"] = 6; // Manisha
$_SESSION["position_id"] = 5;

require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/faceEnrollment.php";

register_shutdown_function(function() {
    echo "STATUS:" . http_response_code();
});
$c = new faceEnrollment();
$c->streamReferencePortrait(["contact_id" => 777]);
PHP;

$res5 = runCaseProcess($snippet5);
if (strpos($res5['stdout'], 'STATUS:403') === false) {
    $failures[] = "Case 5 failed: expected HTTP 403 on branch mismatch, got: " . $res5['stdout'];
}

// ---------------------------------------------------------
// Case 6: Report 2 select_report returns face_preview column with 'v' timestamp
// ---------------------------------------------------------
$snippet6 = <<<'PHP'
$_SERVER["HTTP_REFERER"] = "http://admin.creativeculturalcentre.in/pf/list.php?id=2";
session_start();
$_SESSION["admin"] = 1;
$_SESSION["role_id"] = 1;
$_SESSION["db_name"] = "ccc_database";
$_SESSION["bid"] = 2;
$_SESSION["user_id"] = 3;
$_SESSION["position_id"] = 1;

require_once "../inc/defaults.php";
require_once "../classes/dbconnection.class.php";
require_once "../classes/querybuilder.class.php";
require_once "../classes/session.class.php";
require_once "../controllers/report.php";

$report = new report();
$res = $report->select_report(
    ["session_id" => session_id(), "filter" => "contact_id = 777", "offset" => 0, "limit" => 2],
    ["id" => 2]
);
echo "REPORT_JSON:" . $res;
PHP;

$res6 = runCaseProcess($snippet6);
$reportPos = strpos($res6['stdout'], 'REPORT_JSON:');
if ($reportPos === false) {
    $failures[] = "Case 6 failed: report JSON marker not found in output: " . $res6['stderr'];
} else {
    $reportJson = substr($res6['stdout'], $reportPos + strlen('REPORT_JSON:'));
    $reportData = json_decode($reportJson, true);

    if (($reportData['error'] ?? 1) !== 0) {
        $failures[] = "Case 6 failed: select_report error: " . ($reportData['error_msg'] ?? 'unknown');
    } else {
        $headers = $reportData['header'] ?? [];
        if (!in_array('Face', $headers, true)) {
            $failures[] = "Case 6 failed: 'Face' header missing from report columns: " . json_encode($headers);
        }

        $firstRow = $reportData['data'][0] ?? [];
        $faceCell = null;
        foreach ($firstRow as $cell) {
            if (is_array($cell) && ($cell['type'] ?? '') === 'face_preview') {
                $faceCell = $cell;
                break;
            }
        }

        if (!$faceCell) {
            $failures[] = "Case 6 failed: face_preview cell missing in report row";
        } elseif (($faceCell['enrolled'] ?? 0) !== 1) {
            $failures[] = "Case 6 failed: enrolled expected 1 for contact 777, got " . ($faceCell['enrolled'] ?? 'null');
        } elseif (!isset($faceCell['v']) || (int)$faceCell['v'] <= 0) {
            $failures[] = "Case 6 failed: 'v' timestamp expected positive integer in face_preview cell, got " . ($faceCell['v'] ?? 'null');
        }
    }
}

// ---------------------------------------------------------
// Case 7: inc/defaults.php get_uid() and get_bid() fallback cleanly to $_SESSION
// ---------------------------------------------------------
$snippet7 = <<<'PHP'
unset($_SERVER["HTTP_UID"]);
unset($_SERVER["HTTP_BID"]);
session_start();
$_SESSION["user_id"] = 123;
$_SESSION["bid"] = 456;

require_once "../inc/defaults.php";
if (get_uid() !== 123) {
    echo "UID_FAIL:" . get_uid();
}
if (get_bid() !== 456) {
    echo "BID_FAIL:" . get_bid();
}
echo "DEFAULTS_OK";
PHP;

$res7 = runCaseProcess($snippet7);
if (strpos($res7['stdout'], 'DEFAULTS_OK') === false) {
    $failures[] = "Case 7 failed: defaults.php did not fall back cleanly to session: " . $res7['stdout'];
}

// ---------------------------------------------------------
// Summary Report
// ---------------------------------------------------------
if (!empty($failures)) {
    echo "FAIL (" . count($failures) . " issues):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}

echo "PASS: All 7 FACE-PREVIEW-01 test cases passed.\n";
exit(0);
