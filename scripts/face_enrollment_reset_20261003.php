<?php
declare(strict_types=1);

// One-off, scoped biometric reset. Invoke only from CLI; keep snapshots outside the web root.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/classes/dbconnection.class.php';

$mode = $argv[1] ?? '';
$path = $argv[2] ?? '';
if (!in_array($mode, ['inspect', 'backup', 'restore-test', 'reset', 'verify', 'restore-gallery'], true)) {
    fwrite(STDERR, "Usage: php ccc-face-reset-20261003.php inspect|backup|restore-test|reset|verify|restore-gallery [snapshot]\n");
    exit(2);
}
$db = (new dbconnection())->connect('ccc_database');
if (!$db instanceof mysqli) throw new RuntimeException('Database unavailable');
$db->set_charset('utf8mb4');

function rows(mysqli $db, string $sql): array {
    $result = $db->query($sql);
    if (!$result instanceof mysqli_result) throw new RuntimeException('Query failed');
    return $result->fetch_all(MYSQLI_ASSOC);
}
function scalar(mysqli $db, string $sql): int {
    $r = rows($db, $sql);
    return (int)array_values($r[0] ?? [0])[0];
}
function encodedRows(array $rows): array {
    return array_map(static function (array $row): array {
        return array_map(static fn($v) => $v === null ? null : base64_encode((string)$v), $row);
    }, $rows);
}
function decodedRows(array $rows): array {
    return array_map(static function (array $row): array {
        return array_map(static function ($v) {
            if ($v === null) return null;
            $s = base64_decode((string)$v, true);
            if ($s === false) throw new RuntimeException('Corrupt snapshot value');
            return $s;
        }, $row);
    }, $rows);
}
function ids(array $values): string {
    if (!$values) throw new RuntimeException('Empty reset scope');
    return implode(',', array_map('intval', $values));
}
function scope(mysqli $db): array {
    $bindings = rows($db, 'SELECT bs.id subject_id,m.id member_id FROM biometric_subject bs JOIN member m ON m.contact_id=bs.contact_id WHERE m.bid=2 ORDER BY bs.id,m.id');
    $subjects = array_map('intval', array_column($bindings, 'subject_id'));
    $members = array_map('intval', array_column($bindings, 'member_id'));
    if (count($bindings) !== 4 || count(array_unique($subjects)) !== 4 || count(array_unique($members)) !== 4) {
        throw new RuntimeException('Expected exactly four unique branch-2 biometric subjects and members');
    }
    return ['subjects' => $subjects, 'members' => $members];
}
function snapshot(mysqli $db, array $scope): array {
    $m = ids($scope['members']);
    $s = ids($scope['subjects']);
    $queries = [
        'face_template' => "SELECT * FROM face_template WHERE member_id IN ($m) ORDER BY id",
        'face_identity_reference' => "SELECT * FROM face_identity_reference WHERE subject_id IN ($s) ORDER BY id",
        'biometric_consent' => "SELECT * FROM biometric_consent WHERE member_id IN ($m) ORDER BY id",
        'face_enrollment_capture_session' => "SELECT * FROM face_enrollment_capture_session WHERE member_id IN ($m) ORDER BY id",
        'attendance_scan' => "SELECT * FROM attendance_scan WHERE id IN (SELECT scan_id FROM attendance_scan_roster WHERE member_id IN ($m)) AND status='ACTIVE' ORDER BY id",
    ];
    $tables = [];
    foreach ($queries as $table => $query) $tables[$table] = encodedRows(rows($db, $query));
    return ['version' => 1, 'scope' => $scope, 'tables' => $tables];
}
function loadSnapshot(string $path): array {
    if ($path === '' || !is_file($path)) throw new RuntimeException('Snapshot missing');
    $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($data['version'] ?? null) !== 1 || !isset($data['scope'], $data['tables'])) throw new RuntimeException('Snapshot version invalid');
    return $data;
}
function counts(array $snapshot): array {
    $out = [];
    foreach ($snapshot['tables'] as $table => $data) $out[$table] = count($data);
    return $out;
}
function ensureSafe(mysqli $db, array $scope): void {
    $m = ids($scope['members']);
    $unexpired = scalar($db, "SELECT COUNT(DISTINCT s.id) FROM attendance_scan s JOIN attendance_scan_roster r ON r.scan_id=s.id WHERE r.member_id IN ($m) AND s.status='ACTIVE' AND s.expires_at>NOW()");
    $accepted = scalar($db, "SELECT COUNT(*) FROM recognition_attempt a JOIN attendance_scan s ON s.id=a.scan_id JOIN attendance_scan_roster r ON r.scan_id=s.id WHERE r.member_id IN ($m) AND s.status='ACTIVE' AND a.decision='ACCEPT'");
    if ($unexpired || $accepted) throw new RuntimeException('Active scan or accepted attempt present; reset halted');
}
function acquire(mysqli $db, array $scope): void {
    foreach ($scope['members'] as $member) {
        $name = 'face_enrollment_' . substr(hash('sha256', (string)$member), 0, 48);
        $stmt = $db->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $locked = (int)$result->fetch_row()[0];
        $result->free();
        $stmt->close();
        if ($locked !== 1) throw new RuntimeException('Enrollment lock unavailable');
    }
}
function release(mysqli $db, array $scope): void {
    foreach ($scope['members'] as $member) {
        $name = 'face_enrollment_' . substr(hash('sha256', (string)$member), 0, 48);
        $stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $result = $stmt->get_result();
        $result->free();
        $stmt->close();
    }
}

$scope = scope($db);
if ($mode === 'inspect') {
    ensureSafe($db, $scope);
    echo json_encode(['scope' => ['subjects' => count($scope['subjects']), 'members' => count($scope['members'])],
        'rows' => counts(snapshot($db, $scope)),
        'nonempty_template_embeddings' => scalar($db, 'SELECT COUNT(*) FROM face_template WHERE member_id IN (' . ids($scope['members']) . ") AND encrypted_embedding<>''"),
        'nonempty_reference_payloads' => scalar($db, 'SELECT COUNT(*) FROM face_identity_reference WHERE subject_id IN (' . ids($scope['subjects']) . ") AND (encrypted_embedding<>'' OR encrypted_portrait<>'')"),
        'authorized_consents' => scalar($db, 'SELECT COUNT(*) FROM biometric_consent WHERE member_id IN (' . ids($scope['members']) . ") AND consent_status='PROGRAM_AUTHORIZED'"),
        'active_capture_sessions' => scalar($db, 'SELECT COUNT(*) FROM face_enrollment_capture_session WHERE member_id IN (' . ids($scope['members']) . ") AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW')"),
        'audit_enrollment_attempts' => scalar($db, 'SELECT COUNT(*) FROM face_enrollment_attempt WHERE branch_id=2'),
        'audit_capture_evidence' => scalar($db, 'SELECT COUNT(*) FROM face_capture_evidence')], JSON_PRETTY_PRINT), "\n";
    exit;
}
if ($mode === 'backup') {
    if ($path === '' || file_exists($path)) throw new RuntimeException('Snapshot path must be new');
    ensureSafe($db, $scope);
    $snap = snapshot($db, $scope);
    $body = json_encode($snap, JSON_THROW_ON_ERROR);
    $fh = fopen($path, 'x');
    if (!$fh) throw new RuntimeException('Could not create snapshot');
    chmod($path, 0600);
    if (fwrite($fh, $body) !== strlen($body)) throw new RuntimeException('Snapshot write incomplete');
    fflush($fh); fclose($fh);
    echo json_encode(['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path), 'rows' => counts($snap)]), "\n";
    exit;
}
$backup = loadSnapshot($path);
if ($backup['scope'] !== $scope) throw new RuntimeException('Snapshot scope mismatch');
if ($mode === 'restore-test') {
    foreach ($backup['tables'] as $table => $items) {
        if (!preg_match('/^[a-z_]+$/', $table)) throw new RuntimeException('Invalid snapshot table');
        $temp = 'tmp_face_reset_' . $table;
        $db->query("CREATE TEMPORARY TABLE `$temp` LIKE `$table`");
        foreach (decodedRows($items) as $row) {
            $columns = array_keys($row);
            $marks = implode(',', array_fill(0, count($columns), '?'));
            $sql = "INSERT INTO `$temp` (`" . implode('`,`', $columns) . "`) VALUES ($marks)";
            $stmt = $db->prepare($sql);
            $params = array_values($row);
            $stmt->bind_param(str_repeat('s', count($params)), ...$params);
            $stmt->execute();
        }
        $restored = encodedRows(rows($db, "SELECT * FROM `$temp` ORDER BY id"));
        if ($restored !== $items) throw new RuntimeException("Restore rehearsal mismatch: $table");
    }
    echo json_encode(['restore_rehearsal' => 'PASS', 'sha256' => hash_file('sha256', $path), 'rows' => counts($backup)]), "\n";
    exit;
}
if ($mode === 'reset') {
    acquire($db, $scope);
    try {
        $db->begin_transaction();
        ensureSafe($db, $scope);
        if (snapshot($db, $scope) !== $backup) throw new RuntimeException('Live rows changed since backup');
        $m = ids($scope['members']); $s = ids($scope['subjects']);
        $db->query("UPDATE face_template SET status='REVOKED',revoked_at=COALESCE(revoked_at,NOW()),encrypted_embedding='' WHERE member_id IN ($m) AND status<>'REVOKED'");
        $db->query("UPDATE face_template SET encrypted_embedding='' WHERE member_id IN ($m) AND encrypted_embedding<>''");
        $db->query("UPDATE face_identity_reference SET status=CASE WHEN status IN ('ACTIVE','PENDING_ENROLLMENT','VALIDATION_ONLY','QUARANTINED') THEN 'REVOKED' ELSE status END,encrypted_embedding='',encrypted_portrait='' WHERE subject_id IN ($s)");
        $db->query("UPDATE biometric_consent SET consent_status='WITHDRAWN',withdrawn_at=NOW() WHERE member_id IN ($m) AND consent_status='PROGRAM_AUTHORIZED'");
        $db->query("UPDATE face_enrollment_capture_session SET status='CANCELLED',reference_id=NULL,cleared_at=NOW() WHERE member_id IN ($m) AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW')");
        $db->query("UPDATE attendance_scan SET status='CANCELLED',cancelled_at=NOW() WHERE id IN (SELECT scan_id FROM attendance_scan_roster WHERE member_id IN ($m)) AND status='ACTIVE' AND expires_at<=NOW()");
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    } finally { release($db, $scope); }
}
if ($mode === 'restore-gallery') {
    acquire($db, $scope);
    try {
        $db->begin_transaction();
        $m = ids($scope['members']); $s = ids($scope['subjects']);
        $current = snapshot($db, $scope);
        foreach (['face_template','face_identity_reference','biometric_consent'] as $table) {
            $expectedIds = array_column(decodedRows($backup['tables'][$table]), 'id');
            $currentIds = array_column(decodedRows($current['tables'][$table]), 'id');
            if ($expectedIds !== $currentIds) throw new RuntimeException('New enrollment rows exist; restore refused');
        }
        if (scalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id IN ($m) AND (encrypted_embedding<>'' OR status='ACTIVE')")
            || scalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE subject_id IN ($s) AND (encrypted_embedding<>'' OR encrypted_portrait<>'')")
            || scalar($db, "SELECT COUNT(*) FROM biometric_consent WHERE member_id IN ($m) AND consent_status='PROGRAM_AUTHORIZED'")) {
            throw new RuntimeException('Fresh biometric data exists; restore refused');
        }
        foreach (['face_template','face_identity_reference','biometric_consent'] as $table) {
            foreach (decodedRows($backup['tables'][$table]) as $row) {
                $id = $row['id']; unset($row['id']);
                $updates = implode(',', array_map(static fn($column) => "`$column`=?", array_keys($row)));
                $stmt = $db->prepare("UPDATE `$table` SET $updates WHERE id=?");
                $params = array_values($row); $params[] = $id;
                $stmt->bind_param(str_repeat('s', count($params)), ...$params);
                $stmt->execute(); $stmt->close();
            }
        }
        $restored = snapshot($db, $scope);
        foreach (['face_template','face_identity_reference','biometric_consent'] as $table) {
            if ($restored['tables'][$table] !== $backup['tables'][$table]) throw new RuntimeException('Gallery restore verification failed');
        }
        $db->commit();
        echo json_encode(['gallery_restore'=>'PASS','scan_and_capture_sessions'=>'remain_cancelled']), "\n";
    } catch (Throwable $e) { $db->rollback(); throw $e; }
    finally { release($db, $scope); }
    exit;
}
if (in_array($mode, ['reset','verify'], true)) {
    $m = ids($scope['members']); $s = ids($scope['subjects']);
    $checks = [
        'template_payloads' => scalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id IN ($m) AND encrypted_embedding<>''"),
        'active_templates' => scalar($db, "SELECT COUNT(*) FROM face_template WHERE member_id IN ($m) AND status='ACTIVE'"),
        'reference_payloads' => scalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE subject_id IN ($s) AND (encrypted_embedding<>'' OR encrypted_portrait<>'')"),
        'active_references' => scalar($db, "SELECT COUNT(*) FROM face_identity_reference WHERE subject_id IN ($s) AND status IN ('ACTIVE','PENDING_ENROLLMENT')"),
        'authorized_consents' => scalar($db, "SELECT COUNT(*) FROM biometric_consent WHERE member_id IN ($m) AND consent_status='PROGRAM_AUTHORIZED'"),
        'open_capture_sessions' => scalar($db, "SELECT COUNT(*) FROM face_enrollment_capture_session WHERE member_id IN ($m) AND status IN ('ACTIVE','PENDING_ADMIN_REVIEW')"),
        'active_scans' => scalar($db, "SELECT COUNT(DISTINCT sc.id) FROM attendance_scan sc JOIN attendance_scan_roster r ON r.scan_id=sc.id WHERE r.member_id IN ($m) AND sc.status='ACTIVE'"),
    ];
    if (array_sum($checks) !== 0) throw new RuntimeException('Reset verification failed: ' . json_encode($checks));
    if (scalar($db, 'SELECT COUNT(*) FROM face_enrollment_attempt WHERE branch_id=2') !== 4 || scalar($db, 'SELECT COUNT(*) FROM face_capture_evidence') !== 158) throw new RuntimeException('Audit count changed');
    echo json_encode(['reset' => 'PASS', 'checks' => $checks, 'audit_enrollment_attempts' => 4, 'audit_capture_evidence' => 158]), "\n";
}
