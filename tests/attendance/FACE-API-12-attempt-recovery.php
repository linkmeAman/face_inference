<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers/face-api-recovery-fixture.php';
date_default_timezone_set('UTC');
try { $db = recoveryDb(); }
catch (Throwable $error) { fwrite(STDERR, 'BLOCKED: ' . $error->getMessage() . PHP_EOL); exit(2); }
$cases = [
    'same_completed_attempt_keeps_nonce' => function () use ($db): void {
        $f = new RecoveryFixture($db); $first = $f->accepted(); $second = $f->attempt();
        recoveryAssert(($second['decision'] ?? '') === 'ACCEPT', 'completed attempt with original nonce must return stored result');
        recoveryAssert($f->provider->attemptCalls === 1, 'completed retry must not infer again');
        recoveryAssert($first['contact_id'] === $second['contact_id'], 'completed retry must keep identity');
        recoveryAssert((int)$f->scalar('SELECT COUNT(*) FROM recognition_attempt') === 1, 'one attempt row');
    },
    'changed_payload_conflicts' => function () use ($db): void {
        $f = new RecoveryFixture($db); $f->accepted();
        $result = $f->attempt('p0-attempt', $f->freshNonce(), ['changed-a', 'changed-b', 'changed-c']);
        recoveryAssert(($result['reason'] ?? '') === 'IDEMPOTENCY_CONFLICT', 'same attempt ID with changed frames must conflict');
        recoveryAssert($f->provider->attemptCalls === 1, 'conflict must not infer');
    },
    'status_finds_attempt' => function () use ($db): void {
        $f = new RecoveryFixture($db); $f->accepted();
        // Proposed optional trailing arguments are intentionally ignored by current PHP.
        $result = $f->service->getFaceScanStatus($f->scan['scan_id'], $f->scan['scan_token'], 9001, 'p0-attempt', $f->binding);
        recoveryAssert(($result['attempt']['state'] ?? '') === 'RECORDED', 'status must identify the requested completed attempt');
        recoveryAssert($f->provider->attemptCalls === 1, 'status must not infer');
    },
    'exactly_three_frames' => function () use ($db): void {
        $f = new RecoveryFixture($db);
        // Frame-count contract must not depend on the nonce feature flag.
        putenv('FACE_CAPTURE_NONCE_REQUIRED=0');
        try { $result = $f->attempt('p0-one-frame', null, ['frame-a']); }
        finally { putenv('FACE_CAPTURE_NONCE_REQUIRED=1'); }
        recoveryAssert(($result['reason'] ?? '') === 'FRAME_COUNT', 'one frame must fail the unconditional three-frame contract');
        recoveryAssert($f->provider->attemptCalls === 0, 'invalid frame count must not infer');
    },
    'temporary_readiness_failure_keeps_scan' => function () use ($db): void {
        $f = new RecoveryFixture($db, new RecoveryClassifiedProvider()); $f->provider->ready = false;
        $result = $f->attempt();
        recoveryAssert($f->scalar('SELECT status FROM attendance_scan LIMIT 1') === 'ACTIVE', 'classified temporary outage must retain active scan');
        recoveryAssert(($result['retryable'] ?? false) === true, 'classified temporary outage must advertise recovery');
        recoveryAssert($f->provider->deleteCalls === 0, 'temporary outage must not delete sidecar scan');
    },
    'legacy_ready_provider_works' => function () use ($db): void {
        $f = new RecoveryFixture($db); $f->accepted();
        recoveryAssert(!is_callable([$f->provider, 'readinessStatus']), 'legacy fixture must not acquire optional method');
        recoveryAssert($f->provider->attemptCalls === 1, 'legacy provider should infer normally');
    },
    'legacy_unready_provider_stops' => function () use ($db): void {
        $f = new RecoveryFixture($db); $f->provider->ready = false; $result = $f->attempt();
        recoveryAssert(($result['reason'] ?? '') === 'SYSTEM_NOT_READY', 'unclassified false readiness stays terminal');
        recoveryAssert($f->provider->attemptCalls === 0, 'unready legacy provider must not infer');
    },
    'owner_browser_nonce_and_closed_scan_guards' => function () use ($db): void {
        $f = new RecoveryFixture($db);
        recoveryAssert(($f->attempt('wrong-owner', null, null, 9002)['reason'] ?? '') === 'SCAN_OWNER', 'wrong owner rejected');
        recoveryAssert(($f->attempt('wrong-browser', null, null, 9001, str_repeat('b', 64))['reason'] ?? '') === 'REJECT_REPLAY', 'wrong browser rejected');
        $f->accepted();
        recoveryAssert(($f->attempt('different-id')['reason'] ?? '') === 'REJECT_REPLAY', 'used nonce with different ID rejected');
        $db->query("UPDATE attendance_scan SET status = 'CANCELLED'");
        recoveryAssert(($f->attempt('cancelled')['reason'] ?? '') === 'SCAN_CANCELLED', 'cancelled scan rejected');
        $db->query("UPDATE attendance_scan SET status = 'ACTIVE', expires_at = '2000-01-01 00:00:00'");
        recoveryAssert(($f->attempt('expired')['reason'] ?? '') === 'SCAN_EXPIRED', 'expired scan rejected');
    },
    'recognition_never_commits_attendance_or_deductions' => function () use ($db): void {
        $f = new RecoveryFixture($db); $result = $f->accepted();
        recoveryAssert(($result['effect'] ?? '') === 'SELECT_PRESENT', 'recognition selects only');
        recoveryAssert((int)$f->scalar('SELECT COUNT(*) FROM attendance') === 0, 'no attendance write');
        recoveryAssert((int)$f->scalar('SELECT COUNT(*) FROM attendance_event') === 0, 'no attendance event write');
        recoveryAssert((int)$f->scalar('SELECT SUM(sessions_used) FROM invoice_item') === 2, 'no invoice item deduction');
        recoveryAssert((int)$f->scalar('SELECT SUM(sessions_used) FROM invoice_invoiceitem_module_view') === 2, 'no membership deduction');
    },
];
$passed = true;
foreach ($cases as $name => $test) $passed = recoveryCase($name, $test) && $passed;
$db->close();
exit($passed ? 0 : 1);
