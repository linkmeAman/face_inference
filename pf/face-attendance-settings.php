<?php
// Face Attendance Settings: one administrator page over the EXISTING rollout policy.
// It adds no role or service: access is the same administrator check as face-pilot-config.php (position 1),
// every change is enforced by BrowserFacePolicy on the server, and LIVE can only be saved when the policy confirms it.
ob_start();
include 'inc/config.php'; // login/session check and template defaults
require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';
require_once dirname(__DIR__) . '/classes/services/BrowserFaceService.php';
require_once dirname(__DIR__) . '/classes/services/BrowserFaceRolloutAdmin.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$adminId = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
if (!isset($_SESSION['position_id']) || (int)$_SESSION['position_id'] !== 1 || $adminId < 1) {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        BrowserFaceAudit::write('face_rollout_rejected', ['actor_id' => $adminId, 'tenant' => (string)($_SESSION['db_name'] ?? ''), 'operation' => 'page', 'result' => 'rejected', 'error_code' => 'NOT_ADMIN', 'persistence_succeeded' => false]);
    }
    ob_end_clean();
    http_response_code(403);
    die('<h1>403 Forbidden</h1><p>Administrator access only.</p>');
}

$database = (string)($_SESSION['db_name'] ?? '');
if (!preg_match('/^[A-Za-z0-9_]{1,60}$/D', $database)) { ob_end_clean(); http_response_code(400); die('Invalid session.'); }
if (empty($_SESSION['face_settings_csrf'])) $_SESSION['face_settings_csrf'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['face_settings_csrf'];
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// ---- data the page and the writer both rely on ------------------------------------------------------------
$allowedBranches = [];
foreach ((array)($_SESSION['branch'] ?? []) as $b) if ((int)($b['id'] ?? 0) > 0) $allowedBranches[] = (int)$b['id'];
if (!$allowedBranches && (int)($_SESSION['bid'] ?? 0) > 0) $allowedBranches[] = (int)$_SESSION['bid'];
$allowedBranches = array_values(array_unique($allowedBranches));
$db = (new dbconnection())->connect($database);
$db->set_charset('utf8mb4');
// Strict mysqli mode throws on any problem; this page is read-only reporting and must degrade, not die.
$read = function (string $sql) use ($db) { try { return $db->query($sql); } catch (Throwable $e) { return false; } };
$in = $allowedBranches ? implode(',', array_map('intval', $allowedBranches)) : '0';
$branchNames = [];
if (($r = $read("SELECT id,name FROM branch WHERE id IN ($in)"))) while ($row = $r->fetch_assoc()) $branchNames[(int)$row['id']] = $row['name'];
$batches = [];$validBatches = [];
if (($r = $read("SELECT id,bid,batch FROM batch WHERE bid IN ($in) AND park=0 AND inactive=0 AND end_date>=CURDATE() ORDER BY bid,batch"))) {
    while ($row = $r->fetch_assoc()) { $batches[(int)$row['bid']][] = ['id' => (int)$row['id'], 'name' => $row['batch']]; $validBatches[(int)$row['bid']][] = (int)$row['id']; }
}
$matching = BrowserFacePolicy::load();
$rollout = new BrowserFaceRolloutAdmin(FaceAttendanceConfig::getEnv('FACE_WEB_ROLLOUT_FILE'));
$state = $rollout->read();
$config = $state['ok'] ? $state['config'] : ['version' => BrowserFaceRolloutAdmin::VERSION, 'enabled' => false, 'default_mode' => 'DISABLED', 'scopes' => []];
$branchId = (int)($_GET['branch'] ?? $_POST['branch'] ?? ($_SESSION['bid'] ?? 0));
if (!in_array($branchId, $allowedBranches, true)) $branchId = $allowedBranches[0] ?? 0;

// ---- per-batch enrollment figures, read only (no biometric data is touched) --------------------------------
$key = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY'); $keyId = FaceAttendanceConfig::getEnv('FACE_EMBEDDING_KEY_ID');
$services = [];
$serviceFor = function (int $branch) use (&$services, $db, $key, $keyId, $adminId, $matching) {
    if (!array_key_exists($branch, $services)) {
        try { $services[$branch] = ($key !== '' && $keyId !== '') ? new BrowserFaceService($db, new FaceEmbeddingCipher($key, $keyId), $adminId, $branch, $matching) : null; }
        catch (Throwable $e) { $services[$branch] = null; }
    }
    return $services[$branch];
};
$statCache = [];
$statsFor = function (int $branch, int $batch) use ($read, $serviceFor, &$statCache): ?array {
    if (array_key_exists($branch . ':' . $batch, $statCache)) return $statCache[$branch . ':' . $batch];
    $service = $serviceFor($branch);
    if (!$service) return $statCache[$branch . ':' . $batch] = null;
    try { $persons = $service->roster(['batch_id' => $batch, 'attendance_date' => date('Y-m-d')])['persons']; } catch (Throwable $e) { return $statCache[$branch . ':' . $batch] = null; }
    $ids = array_map(fn($p) => (int)$p['id'], $persons);
    $states = [];
    if ($ids) { $q = $read('SELECT member_id,state FROM face_web_enrollment WHERE member_id IN (' . implode(',', $ids) . ')'); while ($q && ($row = $q->fetch_assoc())) $states[(int)$row['member_id']] = $row['state']; }
    $s = ['ids' => $ids, 'eligible' => count($ids), 'pending' => 0, 'active' => 0, 'duplicate' => 0];
    foreach ($persons as $p) {
        $st = $states[(int)$p['id']] ?? '';
        if ($st === 'PENDING') $s['pending']++;
        if ($st === 'ACTIVE' || (int)$p['enrolled'] > 0) $s['active']++;
        if ($st === 'REJECTED_DUPLICATE') $s['duplicate']++;
    }
    $s['saved'] = $s['pending'] + $s['active'];
    $s['not_enrolled'] = max(0, $s['eligible'] - $s['saved']);
    return $statCache[$branch . ':' . $batch] = $s;
};
$testingBranch = function (int $branch) use ($database, $config, $matching): bool {
    $bare = ['version' => BrowserFaceRolloutAdmin::VERSION, 'enabled' => true, 'default_mode' => $config['default_mode'] ?? 'SHADOW', 'testing_evaluation' => $config['testing_evaluation'] ?? null, 'scopes' => []];
    return BrowserFaceRolloutAdmin::effectiveMode($bare, $database, $branch, 1, $matching) === 'TEST';
};

// ---- change requests: handled before any output, then redirected (Post/Redirect/Get) -----------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $fail = function (string $code) use ($adminId, $database, $branchId) {
        BrowserFaceAudit::write('face_rollout_rejected', ['actor_id' => $adminId, 'tenant' => $database, 'operation' => 'page', 'result' => 'rejected', 'error_code' => $code, 'persistence_succeeded' => false]);
        $_SESSION['face_settings_flash'] = ['ok' => false, 'title' => 'Unable to Save', 'lines' => [BrowserFaceRolloutAdmin::message('INVALID_REQUEST')]];
        header('Location: face-attendance-settings.php?branch=' . $branchId, true, 303); exit;
    };
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? '')) || ($origin !== '' && (parse_url($origin, PHP_URL_HOST) . ((parse_url($origin, PHP_URL_PORT)) ? ':' . parse_url($origin, PHP_URL_PORT) : '')) !== ($_SERVER['HTTP_HOST'] ?? ''))) $fail('CSRF');

    $op = (string)($_POST['op'] ?? '');
    $targets = []; $action = 'set'; $flags = ['enrollment' => false, 'scanning' => false, 'automatic' => false];
    $ints = fn($v) => array_values(array_filter(array_map('intval', (array)$v), fn($n) => $n > 0));
    $mode = (string)($_POST['mode'] ?? 'SHADOW');
    if ($mode === 'DISABLED') $flags = ['enrollment' => false, 'scanning' => false, 'automatic' => false];
    elseif ($mode === 'LIVE') $flags = ['enrollment' => true, 'scanning' => true, 'automatic' => true];
    else $flags = ['enrollment' => !empty($_POST['enrollment']), 'scanning' => !empty($_POST['scanning']), 'automatic' => false];
    switch ($op) {
        case 'apply_batches': foreach ($ints($_POST['batches'] ?? []) as $b) $targets[] = ['branch_id' => $branchId, 'batch_id' => $b]; break;
        case 'apply_branch': $targets[] = ['branch_id' => $branchId, 'batch_id' => 0]; break;
        case 'stop_batches': $action = 'stop'; foreach ($ints($_POST['batches'] ?? []) as $b) $targets[] = ['branch_id' => $branchId, 'batch_id' => $b]; break;
        case 'reset_batches': $action = 'reset'; foreach ($ints($_POST['batches'] ?? []) as $b) $targets[] = ['branch_id' => $branchId, 'batch_id' => $b]; break;
        case 'reset_branches': $action = 'reset'; foreach ($ints($_POST['branches'] ?? []) as $b) $targets[] = ['branch_id' => $b, 'batch_id' => 0]; break;
        case 'stop_branches': $action = 'stop'; foreach ($ints($_POST['branches'] ?? []) as $b) $targets[] = ['branch_id' => $b, 'batch_id' => 0]; break;
        case 'enable_branches': $flags = ['enrollment' => true, 'scanning' => true, 'automatic' => false]; foreach ($ints($_POST['branches'] ?? []) as $b) $targets[] = ['branch_id' => $b, 'batch_id' => 0]; break;
        case 'preset_scan_all': $flags = ['enrollment' => true, 'scanning' => true, 'automatic' => false]; foreach ($allowedBranches as $b) $targets[] = ['branch_id' => $b, 'batch_id' => 0]; break;
        case 'preset_enroll_all': $flags = ['enrollment' => true, 'scanning' => false, 'automatic' => false]; foreach ($allowedBranches as $b) $targets[] = ['branch_id' => $b, 'batch_id' => 0]; break;
        case 'stop_all': case 'resume_all': $action = $op; break;
        default: $fail('OP');
    }
    // LIVE readiness per requested batch, computed from persisted state at the moment of the request.
    $blockers = [];
    if ($flags['automatic'] && $action === 'set') foreach ($targets as $t) if ($t['batch_id'] > 0) {
        $stat = $statsFor($t['branch_id'], $t['batch_id']);
        $blockers[$t['branch_id']][$t['batch_id']] = $stat === null ? 'LIVE_UNVERIFIED' : BrowserFaceRolloutAdmin::liveBlocker($matching, $testingBranch($t['branch_id']), (int)$stat['active']);
    }
    $result = $rollout->apply(['action' => $action, 'admin_id' => $adminId, 'database' => $database, 'targets' => $targets, 'matching' => $matching,
        'allowed_branches' => $allowedBranches, 'valid_batches' => $validBatches, 'live_blockers' => $blockers, 'expected_sha' => (string)($_POST['cfg'] ?? '')] + $flags);
    $lines = [];
    if ($result['saved'] && $result['changed']) {
        $applied = count(array_filter($result['changes'], fn($c) => $c['result'] === 'applied'));
        $lines[] = in_array($action, ['stop_all', 'resume_all'], true) ? 'Applied to all branches.' : 'Applied to ' . $applied . ($applied === 1 ? ' item.' : ' items.');
        if (!$result['audit_ok']) $lines[] = 'The change was saved, but the activity log could not be written. Please tell your system administrator.';
        if (!$result['history_ok']) $lines[] = 'The change was saved, but a history copy could not be kept.';
    } else $lines[] = $result['message'];
    $_SESSION['face_settings_flash'] = ['ok' => (bool)$result['saved'], 'title' => $result['saved'] ? ($result['changed'] ? 'Changes Saved' : 'No Changes Needed') : 'Unable to Save', 'lines' => $lines];
    header('Location: face-attendance-settings.php?branch=' . $branchId, true, 303);
    exit;
}
$flash = $_SESSION['face_settings_flash'] ?? null; unset($_SESSION['face_settings_flash']);
ob_end_clean();

// ---- labels ----------------------------------------------------------------------------------------------------
$modeLabel = ['DISABLED' => 'Off', 'CAPTURE_ONLY' => 'Enrollment only', 'SHADOW' => 'Enrollment and scanning', 'TEST' => 'Testing', 'LIVE' => 'Automatic attendance'];
$enrollOn = fn($m) => in_array($m, ['CAPTURE_ONLY', 'SHADOW', 'TEST', 'LIVE'], true);
$scanOn = fn($m) => in_array($m, ['SHADOW', 'TEST', 'LIVE'], true);
$yes = fn($b) => $b ? '<span class="label label-success">On</span>' : '<span class="label label-default">Off</span>';
$matchingReady = !empty($matching['ready']) && !empty($matching['report_sha256']);
$liveReasons = BrowserFaceRolloutAdmin::matchingReasons($matching, FaceAttendanceConfig::getEnv('FACE_WEB_CALIBRATION_FILE'));
$globalOn = ($config['enabled'] ?? true) === true;

// ---- branch and batch figures ---------------------------------------------------------------------------------
$branchRows = [];$tenantActive = 0;$tenantPending = 0;$tenantDup = 0;
foreach ($allowedBranches as $b) {
    $ids = []; $modes = [];
    foreach ($batches[$b] ?? [] as $batch) {
        $modes[BrowserFaceRolloutAdmin::effectiveMode($config, $database, $b, $batch['id'], $matching)] = true;
        if ($s = $statsFor($b, $batch['id'])) foreach ($s['ids'] as $id) $ids[$id] = true;
    }
    if (empty($batches[$b])) $modes = ['NO_BATCHES' => true];
    $bScope = BrowserFaceRolloutAdmin::scopeMode($config, $database, $b, 0);
    $bEnabled = $globalOn && ($bScope !== 'DISABLED') && (array_keys($modes) !== ['DISABLED']);
    $row = ['id' => $b, 'name' => $branchNames[$b] ?? ('Branch ' . $b), 'modes' => array_keys($modes), 'scope' => $bScope, 'enabled' => $bEnabled, 'eligible' => count($ids), 'pending' => 0, 'active' => 0, 'duplicate' => 0];
    if ($ids) {
        $q = $read('SELECT state,COUNT(*) n FROM face_web_enrollment WHERE member_id IN (' . implode(',', array_keys($ids)) . ') GROUP BY state');
        while ($q && ($x = $q->fetch_assoc())) { if ($x['state'] === 'PENDING') $row['pending'] = (int)$x['n']; if ($x['state'] === 'ACTIVE') $row['active'] = (int)$x['n']; if ($x['state'] === 'REJECTED_DUPLICATE') $row['duplicate'] = (int)$x['n']; }
    }
    $row['saved'] = $row['pending'] + $row['active']; $row['not_enrolled'] = max(0, $row['eligible'] - $row['saved']);
    $branchRows[] = $row;
}
foreach (['PENDING' => 'tenantPending', 'ACTIVE' => 'tenantActive', 'REJECTED_DUPLICATE' => 'tenantDup'] as $st => $var) {
    $q = $read("SELECT COUNT(*) FROM face_web_enrollment WHERE state='" . $st . "'"); $$var = $q ? (int)$q->fetch_row()[0] : 0;
}
$batchRows = [];
foreach ($batches[$branchId] ?? [] as $batch) {
    $m = BrowserFaceRolloutAdmin::effectiveMode($config, $database, $branchId, $batch['id'], $matching);
    $scopeMode = BrowserFaceRolloutAdmin::scopeMode($config, $database, $branchId, $batch['id']);
    $s = $statsFor($branchId, $batch['id']);
    $blocker = $s === null ? 'LIVE_UNVERIFIED' : BrowserFaceRolloutAdmin::liveBlocker($matching, $testingBranch($branchId), (int)$s['active']);
    $batchRows[] = ['id' => $batch['id'], 'name' => $batch['name'], 'mode' => $m, 'scope' => $scopeMode, 'stats' => $s, 'blocker' => $blocker];
}
$branchScope = BrowserFaceRolloutAdmin::scopeMode($config, $database, $branchId, 0);
$last = $config['last_change'] ?? null;
$lastUser = null;
if (is_array($last) && preg_match('/^admin:(\d+)$/D', (string)($last['by'] ?? ''), $lm)) {
    try {
        $central = (new dbconnection())->connect('ccc_central');
        $q = $central->prepare('SELECT fname,lname FROM user WHERE id=? LIMIT 1'); $uid = (int)$lm[1]; $q->bind_param('i', $uid); $q->execute();
        $lastUser = $q->get_result()->fetch_assoc() ?: null; $central->close();
    } catch (Throwable $e) { $lastUser = ['fname' => '', 'lname' => '']; } // lookup failure: fall back to the id, never claim a test
}
$template = ['name' => 'Creative Culture Center', 'version' => $template['version'] ?? '1.1.3', 'author' => 'CCC', 'title' => 'Face Attendance Settings | CCC', 'description' => 'Face attendance availability', 'header' => 'fixed-top', 'sidebar' => 'sticky', 'side_content' => '', 'page' => 'full-width', 'theme' => 'pink', 'active_page' => basename($_SERVER['PHP_SELF'])] + $template;
include 'inc/top.php';
include 'inc/side.php';
$num = fn($v) => $v === null ? '–' : (string)(int)$v;
?>
<style>
.face-settings .block { background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:16px 18px; margin:0 0 16px; }
.face-settings h1 { font-size:22px; margin:6px 0 14px; } .face-settings h2 { font-size:16px; margin:0 0 10px; font-weight:600; }
.face-settings table { width:100%; margin:0; } .face-settings th { font-size:11.5px; text-transform:uppercase; color:#475569; line-height:1.25; vertical-align:bottom !important; }
.face-settings td, .face-settings th { padding:8px 6px !important; vertical-align:middle !important; }
.face-settings { min-width:0; max-width:100%; } .face-settings .num { white-space:nowrap; } .face-settings th.num { white-space:normal; min-width:62px; }
.face-settings .table-wrap { overflow-x:auto; max-width:100%; -webkit-overflow-scrolling:touch; } .face-settings .num { text-align:right; font-variant-numeric:tabular-nums; }
.face-settings .muted { color:#64748b; font-size:12.5px; } .face-settings .controls { display:flex; flex-wrap:wrap; gap:10px 18px; align-items:center; margin:12px 0; }
.face-settings .controls label { margin:0; font-weight:500; } .face-settings .btn { min-height:38px; margin:2px 4px 2px 0; }
.face-settings .flash { border-radius:8px; padding:12px 16px; margin:0 0 16px; } .face-settings .flash.ok { background:#ecfdf5; border:1px solid #a7f3d0; color:#065f46; } .face-settings .flash.bad { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; }
.face-settings .flash strong { display:block; font-size:15px; } .face-settings dl { margin:0; display:grid; grid-template-columns:minmax(180px,260px) 1fr; gap:6px 14px; } .face-settings dt { color:#475569; font-weight:600; } .face-settings dd { margin:0; }
@media (max-width:640px){ .face-settings dl { grid-template-columns:1fr; } }
.face-settings .scroll-hint { display:none; margin:0 0 6px; }
@media (max-width:1100px){ .face-settings .scroll-hint { display:block; } }
@media (max-width:640px){ .face-settings .btn { white-space:normal; max-width:100%; text-align:left; } }
</style>
<div id="page-content" class="face-settings" ng-non-bindable>
 <h1>Face Attendance Settings</h1>
 <?php if ($flash): ?>
  <div class="flash <?= $flash['ok'] ? 'ok' : 'bad' ?>" role="status"><strong><?= $h($flash['title']) ?></strong><?php foreach ($flash['lines'] as $line): ?><div><?= $h($line) ?></div><?php endforeach; ?></div>
 <?php endif; ?>
 <?php if (!$state['ok'] || !$rollout->writable()): ?>
  <div class="flash bad" role="alert"><strong>Settings cannot be changed right now</strong><div><?= $h(BrowserFaceRolloutAdmin::message($state['ok'] ? 'NOT_WRITABLE' : $state['error'])) ?> Existing attendance and manual attendance are not affected.</div></div>
 <?php endif; ?>

 <div class="block">
  <h2>Overview</h2>
  <dl>
   <dt>Face attendance</dt><dd><?= $globalOn ? '<span class="label label-success">On</span>' : '<span class="label label-danger">Stopped for everyone</span>' ?> <span class="muted">Manual attendance always remains available.</span></dd>
   <dt>Automatic attendance</dt><dd><?= $matchingReady ? '<span class="label label-success">Automatic Attendance Available</span>' : '<span class="label label-warning">Automatic Attendance Not Ready</span> <span class="muted">Matching has not been approved yet, so face recognition cannot mark attendance by itself.</span>' ?></dd>
   <dt>Approved matching report</dt><dd><?= $matchingReady ? 'Yes' : 'No' ?></dd>
   <dt>Enrollments (all branches)</dt><dd><?= $num($tenantActive) ?> active &middot; <?= $num($tenantPending) ?> pending &middot; <?= $num($tenantDup) ?> duplicate conflicts</dd>
   <dt>Last change</dt><dd><?= $last ? $h(gmdate('d M Y H:i', strtotime((string)($last['at'] ?? 'now'))) . ' UTC ' . BrowserFaceRolloutAdmin::lastChangeLabel($last, $lastUser)) : 'No change has been made from this page yet.' ?></dd>
  </dl>
 </div>

 <form method="post" action="face-attendance-settings.php" class="block">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="cfg" value="<?= $h($state['sha'] ?? '') ?>"><input type="hidden" name="branch" value="<?= (int)$branchId ?>">
  <h2>Branches</h2>
  <p class="muted scroll-hint">Swipe or scroll sideways to see every column &rarr;</p>
  <div class="table-wrap"><table class="table table-condensed"><thead><tr><th><input type="checkbox" id="all-branches" aria-label="Select all branches"></th><th>Branch</th><th>Face attendance</th><th class="num">Eligible members</th><th class="num">Saved faces</th><th class="num">Pending</th><th class="num">Active</th><th class="num">Duplicate conflicts</th><th class="num">Not enrolled</th><th></th></tr></thead><tbody>
  <?php foreach ($branchRows as $br):
    $modes = $br['modes'];
    if (!$globalOn || $br['scope'] === 'DISABLED' || $modes === ['DISABLED']) {
        $label = 'Off';
    } elseif ($modes === ['NO_BATCHES']) {
        $label = !empty($br['scope']) && $br['scope'] !== 'DISABLED' ? ($modeLabel[$br['scope']] ?? $br['scope']) . ' (no batches)' : 'No current batches';
    } elseif (count($modes) === 1) {
        $label = $modeLabel[$modes[0]] ?? $modes[0];
    } else {
        $label = 'Mixed';
    }
  ?>
   <tr<?= $br['id'] === $branchId ? ' style="background:#f8fafc"' : '' ?>>
    <td><input type="checkbox" class="pick-branch" name="branches[]" value="<?= (int)$br['id'] ?>"<?= !empty($br['enabled']) ? ' checked' : '' ?> aria-label="Select <?= $h($br['name']) ?>"></td>
    <td><strong><?= $h($br['name']) ?></strong></td><td><?= $h($label) ?></td>
    <td class="num"><?= $num($br['eligible']) ?></td><td class="num"><?= $num($br['saved']) ?></td><td class="num"><?= $num($br['pending']) ?></td><td class="num"><?= $num($br['active']) ?></td><td class="num"><?= $num($br['duplicate']) ?></td><td class="num"><?= $num($br['not_enrolled']) ?></td>
    <td><a href="face-attendance-settings.php?branch=<?= (int)$br['id'] ?>">Manage batches</a></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="controls">
   <button class="btn btn-success" name="op" value="enable_branches" type="submit">Enable selected branches</button>
   <button class="btn btn-danger" name="op" value="stop_branches" type="submit" onclick="return confirm('Turn off face attendance for the selected branches? Saved faces and attendance are kept.');">Disable selected branches</button>
   <button class="btn btn-primary" name="op" value="preset_scan_all" type="submit">Enable enrollment and scanning for all branches</button>
   <button class="btn btn-default" name="op" value="preset_enroll_all" type="submit">Enable enrollment only for all branches</button>
   <button class="btn btn-default" name="op" value="reset_branches" type="submit" onclick="return confirm('Remove the branch-wide setting for the selected branches and go back to the default? Batches with their own setting keep it.');">Reset selected branches to default</button>
  </div>
  <p class="muted">Counts come from saved records. A captured face is shown as Pending until it is approved for matching; only Active faces can be used for automatic attendance.</p>
 </form>

 <form method="post" action="face-attendance-settings.php" class="block" id="batch-form">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="cfg" value="<?= $h($state['sha'] ?? '') ?>"><input type="hidden" name="branch" value="<?= (int)$branchId ?>">
  <h2>Batches &mdash; <?= $h($branchNames[$branchId] ?? ('Branch ' . $branchId)) ?></h2>
  <?php if ($branchScope !== null): ?><p class="muted">This whole branch is set to: <strong><?= $h($modeLabel[$branchScope] ?? $branchScope) ?></strong>. Batches without their own setting follow it.</p><?php endif; ?>
  <p class="muted scroll-hint">Swipe or scroll sideways to see every column &rarr;</p>
  <div class="table-wrap"><table class="table table-condensed"><thead><tr><th><input type="checkbox" id="all-batches" aria-label="Select all batches"></th><th>Batch</th><th>Mode</th><th>Enrollment</th><th>Scanning</th><th>Automatic attendance</th><th class="num">Eligible</th><th class="num">Saved</th><th class="num">Pending</th><th class="num">Active</th><th class="num">Duplicates</th><th class="num">Not enrolled</th></tr></thead><tbody>
  <?php if (!$batchRows): ?><tr><td colspan="12" class="muted">No current batches in this branch.</td></tr><?php endif; ?>
  <?php foreach ($batchRows as $row): $s = $row['stats']; $m = $row['mode']; ?>
   <tr>
    <td><input type="checkbox" class="pick-batch" name="batches[]" value="<?= (int)$row['id'] ?>" aria-label="Select <?= $h($row['name']) ?>"></td>
    <td><strong><?= $h($row['name']) ?></strong><?= $row['scope'] !== null ? ' <span class="muted">(own setting)</span>' : '' ?></td>
    <td><?= $h($modeLabel[$m] ?? $m) ?><?= $row['scope'] === 'LIVE' && $m !== 'LIVE' ? '<div class="muted">Automatic attendance is requested but not active.</div>' : '' ?></td>
    <td><?= $yes($enrollOn($m)) ?></td><td><?= $yes($scanOn($m)) ?></td>
    <td><?= $m === 'LIVE' ? '<span class="label label-success">On</span>' : ($row['blocker'] === '' ? '<span class="label label-info">Available</span>' : '<span class="label label-default">Not ready</span><div class="muted">' . $h(str_replace('Automatic Attendance Not Ready: ', '', BrowserFaceRolloutAdmin::message($row['blocker']))) . '</div>') ?></td>
    <td class="num"><?= $num($s['eligible'] ?? null) ?></td><td class="num"><?= $num($s['saved'] ?? null) ?></td><td class="num"><?= $num($s['pending'] ?? null) ?></td><td class="num"><?= $num($s['active'] ?? null) ?></td><td class="num"><?= $num($s['duplicate'] ?? null) ?></td><td class="num"><?= $num($s['not_enrolled'] ?? null) ?></td>
   </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <div class="controls" role="radiogroup" aria-label="Operating mode">
   <strong>Setting:</strong>
   <label><input type="radio" name="mode" value="DISABLED"> Disabled</label>
   <label><input type="radio" name="mode" value="SHADOW" checked> Shadow <span class="muted">(recognition only, no automatic attendance)</span></label>
   <label><input type="radio" name="mode" value="LIVE"> Live <span class="muted">(automatic attendance)</span></label>
  </div>
  <div class="controls" id="shadow-options"><label><input type="checkbox" name="enrollment" value="1" checked> Face enrollment</label><label><input type="checkbox" name="scanning" value="1" checked> Scan Arrivals</label></div>
  <?php if (!$matchingReady): ?><div class="muted" id="live-reasons"><strong>Automatic Attendance Notice:</strong> Matching calibration has not been approved yet. Full automatic attendance requires an approved calibration report before recognition decisions can be applied without operator review:<ul><?php foreach ($liveReasons as $reason): ?><li><?= $h($reason) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="controls">
   <button class="btn btn-primary" name="op" value="apply_batches" type="submit">Apply to selected batches</button>
   <button class="btn btn-default" name="op" value="apply_branch" type="submit" onclick="return confirm('Apply this setting to the whole branch? Batches with their own setting keep it.');">Apply to whole branch</button>
   <button class="btn btn-danger" name="op" value="stop_batches" type="submit" onclick="return confirm('Stop face attendance for the selected batches? Saved faces and attendance are kept.');">Stop selected batches</button>
   <button class="btn btn-default" name="op" value="reset_batches" type="submit">Reset selected to default</button>
  </div>
 </form>

 <form method="post" action="face-attendance-settings.php" class="block">
  <input type="hidden" name="csrf" value="<?= $h($csrf) ?>"><input type="hidden" name="cfg" value="<?= $h($state['sha'] ?? '') ?>"><input type="hidden" name="branch" value="<?= (int)$branchId ?>">
  <h2>Stop immediately</h2>
  <p class="muted">Stops all new face enrollment and scanning in every branch right away. Saved attendance, saved faces and the activity history are kept, and manual attendance keeps working.</p>
  <?php if ($globalOn): ?><button class="btn btn-danger" name="op" value="stop_all" type="submit" onclick="return confirm('Stop all face attendance for every branch now?');">Stop all face attendance</button>
  <?php else: ?><button class="btn btn-success" name="op" value="resume_all" type="submit">Resume face attendance</button><?php endif; ?>
 </form>
</div>
<?php include 'inc/footer.php'; ?>
<script>
(function () {
  var all = document.getElementById('all-batches');
  if (all) all.addEventListener('change', function () { document.querySelectorAll('.pick-batch').forEach(function (c) { c.checked = all.checked; }); });
  var allBranches = document.getElementById('all-branches');
  if (allBranches) allBranches.addEventListener('change', function () { document.querySelectorAll('.pick-branch').forEach(function (c) { c.checked = allBranches.checked; }); });
  var form = document.getElementById('batch-form'), options = document.getElementById('shadow-options');
  if (!form || !options) return;
  var enroll = form.querySelector('[name=enrollment]'), scan = form.querySelector('[name=scanning]');
  function sync() {
    var mode = form.querySelector('[name=mode]:checked').value;
    var shadow = mode === 'SHADOW';
    enroll.disabled = scan.disabled = !shadow;
    if (mode === 'LIVE') { enroll.checked = scan.checked = true; } else if (mode === 'DISABLED') { enroll.checked = scan.checked = false; }
    if (shadow && scan.checked && !enroll.checked) enroll.checked = true;
  }
  form.addEventListener('change', function (e) { if (e.target === scan && scan.checked) enroll.checked = true; if (e.target === enroll && !enroll.checked) scan.checked = false; sync(); });
  sync();
})();
</script>
<?php include 'inc/bottom.php'; ?>
