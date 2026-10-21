<?php
// Batch -> member -> attendance end-to-end scenarios. Started by run.sh only.
// Each scenario replays the request the admin page sends (payload shapes are
// copied from pf/js/batch.js, pf/js/invoice.js and
// pf/js/controllers/batchAttendanceController.js) and then checks the rows
// that really landed in the disposable database.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!preg_match('/^ccc_database_test_bflow_[a-f0-9]{8}$/D', (string)getenv('BFLOW_TENANT_DB'))) {
    fwrite(STDERR, "flow.php must be started by run.sh\n");
    exit(2);
}
require __DIR__ . '/fixture.php';
require __DIR__ . '/lib.php';

$port = (int)getenv('BFLOW_PORT');
$db = new BflowDb();
$t = new BflowSuite((string)getenv('BFLOW_FILTER'));
$op = BflowClient::login($port, BFLOW_STAFF['operator']['user_id']);
$viewer = BflowClient::login($port, BFLOW_STAFF['viewer']['user_id']);
$nobody = BflowClient::login($port, BFLOW_STAFF['nobody']['user_id']);

$today = date('Y-m-d');
$allDays = [];
$GLOBALS['allDays'] = &$allDays;
for ($d = 0; $d <= 6; $d++) {
    $allDays[] = ['day_code' => (string)$d, 'start_time' => '07:00:00', 'end_time' => '08:00:00'];
}
$state = ['batch' => 0, 'batch2' => 0, 'members' => []];
$mobile = 9100000000;

// ---------------------------------------------------------------- helpers --

/** pf/js/batch.js save: insert */
$createBatch = function (string $name, array $overrides = []) use ($op, $today, $allDays) {
    $body = array_merge([
        'batch' => $name, 'display_name' => $name, 'category_id' => '1', 'start_date' => $today, 'end_date' => '',
        'training_assign' => 0, 'demo_class' => '0', 'bid' => '1', 'rate_multiplier' => '1', 'fixed_amt' => '0',
        'whatsapp_link' => '', 'limit' => '20', 'offline' => '1', 'venue_id' => '1', 'private' => '0', 'international' => '0',
        'on_web' => '0', 'on_web_zoom' => '0', 'on_web_link' => '', 'parent_id' => '0',
        'contact_id' => [(string)BFLOW_STAFF['trainer1']['contact_id']], 'batch_time' => $allDays,
    ], $overrides);
    if ($body['end_date'] === '') {
        // pf/js/batch.js:687 - generated end date, or 31 Dec fifty years ahead when the category has no master sessions.
        $end = $op->call('batchSessionRoute.php', 'generateEndDate', ['category_id' => $body['category_id'], 'start_date' => $body['start_date'], 'batch_time' => $body['batch_time']]);
        $body['end_date'] = (int)($end['json']['error'] ?? 1) === 0 ? $end['json']['data']['end_date'] : (date('Y') + 50) . '-12-31';
    }
    return $op->call('batchRoute.php', 'insert', $body);
};

/** Member modal (contactRoute insert) followed by the invoice page (invoiceRoute insert). */
$addMember = function (string $name, int $serviceId, int $sessions, string $start, string $end, int $batchId, BflowClient $as = null) use ($op, &$mobile, $today, $db) {
    $categoryId=(int)$db->value('SELECT category_id FROM service WHERE id='.$serviceId);
    $as = $as ?: $op;
    $mobile++;
    $contact = api($as->call('contactRoute.php', 'insert', ['fname' => 'BFLOW', 'lname' => $name, 'mobile' => (string)$mobile, 'country_code' => '91', 'bid' => '1', 'contact_group_id' => '1']), 0, "contact {$name}");
    $contactId = (int)($contact['data']['contact_id'] ?? $contact['data']['id'] ?? 0);
    check($contactId > 0, "contact insert returned no id: " . json_encode($contact));
    $invoice = $as->call('invoiceRoute.php', 'insert', [
        'contact_id' => (string)$contactId, 'bid' => '1', 'quotation' => 0, 'date' => $today, 'amount' => 0, 'tax' => 0, 'tax_amount' => 0,
        'total' => 0, 'balance' => 0, 'notes' => '', 'category_id' => (string)$categoryId, 'employee_id' => 0,
        'invoice_item' => [[
            'category_id' => (string)$categoryId, 'service_id' => (string)$serviceId, 'offer_id' => '0', 'start_date' => $start, 'end_date' => $end,
            'batch_id' => (string)$batchId, 'sessions' => (string)$sessions, 'sessions_used' => '0', 'item_amount' => 0, 'item_discount' => 0,
            'referral_code' => '', 'promo_code' => '', 'item_tax' => 0, 'item_tax_amount' => 0, 'item_total' => 0, 'item_tax_type' => '0',
            'free_trial' => '0', 'module_id' => '0', 'billing_cycle' => '0',
        ]],
        'payment' => [['amount' => 0, 'pay_mode' => '1', 'date' => $today, 'reminder' => $today, 'clear' => 1, 'comment' => 'BFLOW zero-value test']],
    ]);
    $json = api($invoice, 0, "invoice {$name}");
    $item = (int)($json['data']['item_ids'][0] ?? 0);
    return ['contact_id' => $contactId, 'item_id' => $item, 'name' => $name];
};

/** batchAttendanceController.js selectMembers -> memberRoute selectBatchMember */
$roster = function (int $batchId, string $date, int $sessionId = 0, BflowClient $as = null) use ($op) {
    $as = $as ?: $op;
    // The page takes module_id from the batch list row it opened (batchAttendanceController.js:1053).
    $batch = $op->call('batchRoute.php', 'select', ['col' => ['id', 'module_id'], 'filter' => [['id', '=', (string)$batchId]]]);
    $moduleId = (string)($batch['json']['data'][0]['module_id'] ?? '0');
    return $as->call('memberRoute.php', 'selectBatchMember', [
        'batch_id' => (string)$batchId, 'date' => $date, 'attendance_session_id' => $sessionId,
        'col' => ['code', 'contact_id', 'item_id', 'fullname', 'session_consumed', 'sessions', 'sessions_used', 'end_date'],
        'filter' => [['item_park', '=', '0'], ['park', '=', '0'], ['batch_id', '=', (string)$batchId], ['start_date', '<=', $date], ['end_date', '>=', $date], ['module_id', 'IN', ['0', $moduleId]]],
        'order' => [['fullname', 'asc']],
    ]);
};
$rosterIds = function (array $response): array {
    if ((int)($response['json']['error'] ?? -1) === 1) return [];
    $json = api($response, 0, 'roster');
    $ids = [];
    foreach ($json['data'] as $row) $ids[(int)$row['contact_id']] = $row;
    return $ids;
};

/** batchAttendanceController.js punch(): $present = contact ids ticked, everyone else on the roster absent. */
$punch = function (int $batchId, string $date, array $present, int $sessionId = 0, array $extra = [], BflowClient $as = null, bool $checkMonth = true) use ($op, $roster, $rosterIds) {
    $as = $as ?: $op;
    $list = [];
    foreach ($rosterIds($roster($batchId, $date, $sessionId, $as)) as $id => $row) {
        $row['type'] = 'M';
        $row['selected'] = in_array($id, $present, true);
        $row['negative'] = $row['selected'] ? 0 : 1;
        $row['skip'] = false;
        $row['inactive'] = (bool)$row['inactive'];
        $list[] = $row;
    }
    foreach ($extra as $row) $list[] = $row;
    return $as->call('attendanceRoute.php', 'insertBatchAttendance', [
        's_id' => $sessionId, 'contacts' => $list, 'asst_trainer_id' => '', 'bid' => (string)$as->bid,
        'time' => '07:05:00', 'date' => $date, 'batch_id' => (string)$batchId, 'check_month' => $checkMonth,
    ]);
};
$used = function (int $itemId) use ($db): int {
    return (int)$db->value("SELECT sessions_used FROM invoice_item WHERE id = {$itemId}");
};
$att = function (int $contactId, int $batchId, string $date, int $sessionId = 0) use ($db): array {
    return $db->rows("SELECT id, negative, session, type, park, status FROM attendance WHERE contact_id = {$contactId} AND batch_id = {$batchId} AND date = '{$date}' AND session_id = {$sessionId} ORDER BY id");
};
$setAbsentDeduction = function (int $value) use ($db) {
    $db->exec("UPDATE attendance_settings SET deduct_session_absentee = {$value} WHERE bid = 1");
};

// ------------------------------------------------------ access control --

$t->scenario('AUTH-01', 'request without a login is refused', function () use ($op) {
    api($op->anonymous('batchRoute.php', 'select', ['col' => ['id']]), 2, 'anonymous batch select');
    api($op->anonymous('attendanceRoute.php', 'insertBatchAttendance', ['contacts' => []]), 2, 'anonymous punch');
});
$t->scenario('AUTH-02', 'read-only staff cannot create a batch', function () use ($viewer, $allDays, $today) {
    api($viewer->call('batchRoute.php', 'insert', ['batch' => 'BFLOW Viewer Batch', 'category_id' => '1', 'bid' => '1', 'start_date' => $today, 'batch_time' => $allDays]), 3, 'viewer insert');
});
$t->scenario('AUTH-03', 'staff without permissions cannot read batches or rosters', function () use ($nobody) {
    api($nobody->call('batchRoute.php', 'select', ['col' => ['id'], 'filter' => [['bid', '=', '1']]]), 3, 'nobody batch select');
    api($nobody->call('memberRoute.php', 'selectBatchMember', ['batch_id' => '1', 'col' => ['contact_id'], 'filter' => [['batch_id', '=', '1']]]), 3, 'nobody roster');
});
$t->scenario('AUTH-04', 'read-only staff cannot punch attendance', function () use ($viewer, $today) {
    $response = $viewer->call('attendanceRoute.php', 'insertBatchAttendance', ['s_id' => 0, 'contacts' => [], 'bid' => '1', 'time' => '07:00:00', 'date' => $today, 'batch_id' => '1', 'check_month' => true]);
    $json = api($response, 3, 'viewer punch');
    same('access denied', $json['error_msg'] ?? '', 'viewer punch error_msg');
});
$t->scenario('AUTH-05', 'an anonymous caller cannot act by naming a client_id and a staff uid', function () use ($op, $allDays, $today) {
    $response = $op->anonymous('batchRoute.php', 'select', ['client_id' => (string)BFLOW_CLIENT_ID, 'col' => ['id'], 'filter' => [['bid', '=', '1']]], ['uid: ' . BFLOW_STAFF['operator']['user_id'], 'bid: 1']);
    check((int)($response['json']['error'] ?? 0) === 2, 'anonymous request with client_id + uid header was served: ' . substr($response['body'], 0, 160));
});
$t->scenario('AUTH-06', 'a read-only login cannot borrow another user\'s permissions with a uid header', function () use ($viewer, $port) {
    $forged = clone $viewer;
    $forged->userId = BFLOW_STAFF['operator']['user_id'];
    $response = $forged->call('batchRoute.php', 'park', ['park_value' => '0', 'd' => [['id', '=', '0']]]);
    check((int)($response['json']['error'] ?? 0) === 3, 'viewer session with forged uid header got write access: ' . substr($response['body'], 0, 160));
});

// ------------------------------------------------------------ batches --

$t->scenario('BATCH-01', 'end date: generated from master sessions, refused for a category without them', function () use ($op, $today) {
    $json = api($op->call('batchSessionRoute.php', 'generateEndDate', ['category_id' => '2', 'start_date' => $today, 'batch_time' => [['day_code' => date('w'), 'start_time' => '07:00:00', 'end_time' => '08:00:00']]]), 0, 'generateEndDate (syllabus category)');
    same(date('Y-m-d', strtotime($today . ' +11 weeks')), $json['data']['end_date'], 'weekly batch with 12 master sessions ends 11 weeks later');
    $json = api($op->call('batchSessionRoute.php', 'generateEndDate', ['category_id' => '1', 'start_date' => $today, 'batch_time' => []]), 1, 'generateEndDate (no master sessions)');
    same('Unable to generate End Date, session master not found', $json['message'], 'message (the page then uses 31 Dec, 50 years ahead)');
});
$t->scenario('BATCH-02', 'create a batch with trainer and daily timings', function () use ($op, $db, $createBatch, &$state) {
    $json = api($createBatch('BFLOW Morning Dance'), 0, 'insert batch');
    $id = (int)$json['data']['batch_id'];
    check($id > 0, 'no batch id returned');
    $state['batch'] = $id;
    same(7, $db->value("SELECT COUNT(*) FROM batch_time WHERE batch_id = {$id}"), 'batch_time rows');
    same(BFLOW_STAFF['trainer1']['contact_id'], $db->value("SELECT contact_id FROM batch_employee WHERE batch_id = {$id}"), 'trainer');
    $list = api($op->call('batchRoute.php', 'select', ['col' => ['id', 'batch'], 'filter' => [['bid', '=', '1'], ['park', '=', '0']]]), 0, 'batch list');
    check(in_array($id, array_map('intval', array_column($list['data'], 'id')), true), 'new batch missing from the batch list');
});
$t->scenario('BATCH-03', 'syllabus category: the end date must cover exactly its master sessions', function () use ($createBatch, $db, $today, &$state) {
    $json = api($createBatch('BFLOW Syllabus Short', ['category_id' => '2', 'end_date' => date('Y-m-d', strtotime('+3 days'))]), 1, 'insert with too few class days');
    check(stripos($json['message'], 'Master sessions not matching') === 0, 'message: ' . $json['message']);
    $json = api($createBatch('BFLOW Syllabus Batch', ['category_id' => '2']), 0, 'insert with generated end date');
    $state['syllabus'] = (int)$json['data']['batch_id'];
});
$t->scenario('BATCH-04', 'a duplicate batch name in the same branch and category is refused', function () use ($createBatch, $db) {
    $before = (int)$db->value('SELECT COUNT(*) FROM batch');
    $json = api($createBatch('BFLOW Morning Dance'), 1, 'duplicate insert');
    same('already exist', $json['error_msg'], 'error_msg');
    same($before, $db->value('SELECT COUNT(*) FROM batch'), 'batch count');
});
$t->scenario('BATCH-05', 'duplicate-name check used by the batch form detects an existing name', function () use ($op, &$state) {
    api($op->call('batchRoute.php', 'checkDuplicateBatch', ['batch' => 'BFLOW Morning Dance', 'bid' => '1', 'category_id' => '1']), 1, 'checkDuplicateBatch for an existing name');
    api($op->call('batchRoute.php', 'checkDuplicateBatch', ['batch' => 'BFLOW Brand New', 'bid' => '1', 'category_id' => '1']), 0, 'checkDuplicateBatch for a new name');
    api($op->call('batchRoute.php', 'checkDuplicateBatch', ['batch' => 'BFLOW Morning Dance', 'bid' => '1', 'category_id' => '1', 'batch_id' => (string)$state['batch']]), 0, 'checkDuplicateBatch while editing that same batch');
    api($op->call('batchRoute.php', 'checkDuplicateBatchDisplayName', ['display_name' => 'BFLOW Morning Dance', 'bid' => '1', 'category_id' => '1']), 1, 'display-name check for an existing name');
});
$t->scenario('BATCH-06', 'second batch (same timings, other trainer) for transfers', function () use ($createBatch, &$state) {
    $json = api($createBatch('BFLOW Evening Dance', ['contact_id' => [(string)BFLOW_STAFF['trainer2']['contact_id']]]), 0, 'insert second batch');
    $state['batch2'] = (int)$json['data']['batch_id'];
});
/** pf/js/batch.js edit save: update (same u[] list and order as the page). */
$updateBatch = function (int $id, array $changes) use ($op, $db) {
    $b = $db->row("SELECT * FROM batch WHERE id = {$id}");
    $values = array_merge([
        'category_id' => $b['category_id'], 'batch' => $b['batch'], 'display_name' => $b['display_name'], 'start_date' => $b['start_date'], 'end_date' => $b['end_date'],
        'contact_id' => array_column($db->rows("SELECT contact_id FROM batch_employee WHERE batch_id = {$id} AND park = 0"), 'contact_id'),
        'batch_time' => $db->rows("SELECT day_code, start_time, end_time FROM batch_time WHERE batch_id = {$id} AND park = 0"),
        'rate_multiplier' => $b['rate_multiplier'], 'fixed_amt' => $b['fixed_amt'], 'whatsapp_link' => $b['whatsapp_link'], 'limit' => $b['limit'], 'offline' => $b['offline'],
        'venue_id' => $b['venue_id'], 'private' => $b['private'], 'international' => $b['international'], 'on_web' => $b['on_web'], 'training_assign' => 0,
        'on_web_zoom' => $b['on_web_zoom'], 'on_web_link' => $b['on_web_link'], 'demo_class' => $b['demo_class'],
    ], $changes);
    $u = [];
    foreach ($values as $col => $val) $u[] = ['col' => $col, 'val' => $val];
    return $op->call('batchRoute.php', 'update', ['u' => $u, 'filter' => [['id', '=', (string)$id], ['bid', '=', '1']]]);
};
$t->scenario('BATCH-07', 'edit a batch: rename, limit, timings, trainer', function () use ($updateBatch, $db, &$state) {
    $id = $state['batch2'];
    api($updateBatch($id, ['batch' => 'BFLOW Evening Dance Renamed', 'limit' => '15', 'contact_id' => [(string)BFLOW_STAFF['trainer1']['contact_id']],
        'batch_time' => [['day_code' => '1', 'start_time' => '18:00:00', 'end_time' => '19:00:00']]]), 0, 'update batch');
    $row = $db->row("SELECT batch, `limit` FROM batch WHERE id = {$id}");
    same('BFLOW Evening Dance Renamed', $row['batch'], 'name');
    same('15', $row['limit'], 'limit');
    same([['day_code' => '1', 'start_time' => '18:00:00']], $db->rows("SELECT day_code, start_time FROM batch_time WHERE batch_id = {$id} AND park = 0"), 'timings');
    same(BFLOW_STAFF['trainer1']['contact_id'], $db->value("SELECT GROUP_CONCAT(contact_id) FROM batch_employee WHERE batch_id = {$id} AND park = 0"), 'trainers');
    // put the daily timings back for the transfer scenarios
    api($updateBatch($id, ['batch_time' => $GLOBALS['allDays'], 'contact_id' => [(string)BFLOW_STAFF['trainer2']['contact_id']]]), 0, 'restore timings');
});
$t->scenario('BATCH-08', 'a batch without members can be moved to another category', function () use ($updateBatch, $db, &$state) {
    $id = $state['batch2'];
    $db->exec("INSERT INTO category (id, category, type, master_category_id, bid, park) VALUES (3, 'BFLOW Other Dance', 0, 1, 1, 0)");
    api($updateBatch($id, ['category_id' => '3']), 0, 'category change');
    same('3', $db->value("SELECT category_id FROM batch WHERE id = {$id}"), 'category after edit');
    api($updateBatch($id, ['category_id' => '1']), 0, 'category change back');
});
$t->scenario('BATCH-08b', 'turning "show on web" on does not create a training-assignment row', function () use ($updateBatch, $db, &$state) {
    $id = $state['batch2'];
    $updateBatch($id, ['on_web' => '1']);
    $rows = (int)$db->value("SELECT COUNT(*) FROM batch_training WHERE batch_id = {$id} AND park = 0");
    $updateBatch($id, ['on_web' => '0']);
    same(0, $rows, 'batch_training rows after enabling on_web');
});
$t->scenario('BATCH-09', 'trainer double-booking check finds the clash (branch setting check_emp_dup = 1)', function () use ($op, $db, $today, $allDays, &$state) {
    $db->exec("UPDATE batch_settings SET check_emp_dup = 1 WHERE bid = 1");
    $response = $op->call('batchRoute.php', 'checkBatchEmp', ['contact_id' => [(string)BFLOW_STAFF['trainer1']['contact_id']], 'timing' => $allDays, 'batch_id' => '0', 'start_date' => $today, 'end_date' => date('Y-m-d', strtotime('+30 days'))]);
    check($response['json'] !== null, 'checkBatchEmp is not JSON: ' . substr($response['body'], 0, 200));
    $db->exec("UPDATE batch_settings SET check_emp_dup = 0 WHERE bid = 1");
    check((int)$response['json']['error'] === 1 && !empty($response['json']['data']), 'trainer clash not reported: ' . substr($response['body'], 0, 200));
});
$t->scenario('BATCH-10', 'trash and restore a batch', function () use ($op, $db, $createBatch) {
    $id = (int)api($createBatch('BFLOW Trash Me'), 0, 'insert')['data']['batch_id'];
    api($op->call('batchRoute.php', 'park', ['park_value' => '1', 'd' => [['id', 'IN', [(string)$id]]]]), 0, 'trash');
    same('1', $db->value("SELECT park FROM batch WHERE id = {$id}"), 'park after trash');
    api($op->call('batchRoute.php', 'park', ['park_value' => '0', 'd' => [['id', 'IN', [(string)$id]]]]), 0, 'restore');
    same('0', $db->value("SELECT park FROM batch WHERE id = {$id}"), 'park after restore');
    $db->exec("UPDATE batch SET park = 1 WHERE id = {$id}");
});
$t->scenario('BATCH-11', 'routed actions do not crash (delete, selectSessionActivity)', function () use ($op) {
    foreach (['delete', 'selectSessionActivity', 'selectBatchWiseSummaryReport'] as $action) {
        $response = $op->call('batchRoute.php', $action, ['d' => [['id', '=', '0']]]);
        check($response['json'] !== null, "batchRoute {$action}: HTTP {$response['status']} non-JSON (method missing)");
    }
});

$t->scenario('BATCH-12', 'names with apostrophes and quotes can be saved and edited', function () use ($createBatch, $updateBatch, $db) {
    $json = api($createBatch("BFLOW Kids' Dance \"Junior\""), 0, 'insert with apostrophe and quotes');
    $id = (int)$json['data']['batch_id'];
    same("BFLOW Kids' Dance \"Junior\"", $db->value("SELECT batch FROM batch WHERE id = {$id}"), 'saved name');
    api($updateBatch($id, ['batch' => "BFLOW Kids' Dance \"Senior\"", 'whatsapp_link' => "https://chat.example/it's"]), 0, 'edit with apostrophe and quotes');
    same("BFLOW Kids' Dance \"Senior\"", $db->value("SELECT batch FROM batch WHERE id = {$id}"), 'edited name');
    $db->exec("UPDATE batch SET park = 1 WHERE id = {$id}");
});
$t->scenario('BATCH-13', 'attendance page lists only batches running on that date and weekday', function () use ($op, $createBatch, $db, $today, &$state) {
    $list = function (string $date) use ($op) {
        // batchAttendanceController.js:993
        $json = $op->call('batchRoute.php', 'select', ['col' => ['contact_id', 'code', 'fullname', 'start_time', 'end_time', 'day_code', 'id', 'batch', 'module', 'offline', 'title', 'module_id', 'session_id'],
            'filter' => [['start_date', '<=', $date], ['inactive', '=', '0'], ['end_date', '>=', $date], ['day_code', '=', date('w', strtotime($date))], ['park', '=', '0'], ['bid', '=', '1'], ['training_assign', '=', 0], ['demo_class', '=', 0], ['date', 'IN', ['0000-00-00', $date]], ['contact_id', '!=', '0']]])['json'];
        return array_map('intval', array_column($json['data'] ?? [], 'id'));
    };
    $ended = (int)api($createBatch('BFLOW Ended', ['start_date' => date('Y-m-d', strtotime('-60 days')), 'end_date' => date('Y-m-d', strtotime('-1 day'))]), 0, 'ended batch')['data']['batch_id'];
    $demo = (int)api($createBatch('BFLOW Demo', ['demo_class' => '1']), 0, 'demo batch')['data']['batch_id'];
    $other = date('w', strtotime('+1 day'));
    $tomorrowOnly = (int)api($createBatch('BFLOW Tomorrow Only', ['batch_time' => [['day_code' => $other, 'start_time' => '09:00:00', 'end_time' => '10:00:00']]]), 0, 'other-weekday batch')['data']['batch_id'];
    $ids = $list($today);
    check(in_array($state['batch'], $ids, true), 'running batch missing');
    check(!in_array($ended, $ids, true), 'ended batch listed');
    check(!in_array($demo, $ids, true), 'demo batch listed');
    check(!in_array($tomorrowOnly, $ids, true), 'batch of another weekday listed');
    check(in_array($tomorrowOnly, $list(date('Y-m-d', strtotime('+1 day'))), true), 'other-weekday batch missing on its day');
    $db->exec("UPDATE batch SET park = 1 WHERE id IN ({$ended}, {$demo}, {$tomorrowOnly})");
});
$t->scenario('BATCH-14', 'a batch saved without trainer or timings is refused', function () use ($createBatch, $db) {
    $response = $createBatch('BFLOW No Trainer', ['contact_id' => [], 'batch_time' => []]);
    $id = (int)($response['json']['data']['batch_id'] ?? 0);
    if ($id) $db->exec("UPDATE batch SET park = 1 WHERE id = {$id}");
    check($id === 0, 'batch without trainer and timings was created (it never shows on the attendance page)');
});
$t->scenario('BATCH-15', 'holidays are skipped when the end date is generated (syllabus category)', function () use ($op, $db, $today) {
    $holiday = date('Y-m-d', strtotime($today . ' +1 week'));
    $db->exec("INSERT INTO holiday (date, title, park) VALUES ('{$holiday}', 'BFLOW holiday', 0)");
    $json = api($op->call('batchSessionRoute.php', 'generateEndDate', ['category_id' => '2', 'start_date' => $today, 'batch_time' => [['day_code' => date('w'), 'start_time' => '07:00:00', 'end_time' => '08:00:00']]]), 0, 'generateEndDate');
    $db->exec("DELETE FROM holiday WHERE title = 'BFLOW holiday'");
    same(date('Y-m-d', strtotime($today . ' +12 weeks')), $json['data']['end_date'], 'end date moves one week for one holiday');
});
$t->scenario('BATCH-16', 'time-slot check counts only batches of this branch running on that date', function () use ($op, $db, $createBatch) {
    $date = date('Y-m-d', strtotime('next wednesday'));
    $slot = function (array $extra = []) use ($op, $date) {
        return $op->call('batchRoute.php', 'checkBatchOverlap', array_merge(['day_code' => 3, 'date' => $date, 'start_time' => '05:00:00', 'end_time' => '06:00:00', 'bid' => '1', 'batch_id' => 0], $extra));
    };
    $ids = [];
    foreach ([['BFLOW Old Same Branch', 1, '2020-01-01', '2020-12-31'], ['BFLOW Other Branch', 2, '2020-01-01', '2099-12-31']] as [$name, $bid, $start, $end]) {
        $db->exec("INSERT INTO batch (batch, category_id, start_date, end_date, bid, park) VALUES ('{$name}', 1, '{$start}', '{$end}', {$bid}, 0)");
        $ids[] = $id = (int)$db->value('SELECT LAST_INSERT_ID()');
        $db->exec("INSERT INTO batch_time (batch_id, day_code, start_time, end_time) VALUES ({$id}, 3, '05:00:00', '06:00:00')");
        $db->exec("INSERT INTO batch_employee (batch_id, contact_id) VALUES ({$id}, 5)");
    }
    api($slot(), 0, 'slot taken only by an ended batch and another branch');
    $running = (int)api($createBatch('BFLOW Early Wednesday', ['contact_id' => [(string)BFLOW_STAFF['trainer2']['contact_id']], 'batch_time' => [['day_code' => '3', 'start_time' => '05:30:00', 'end_time' => '06:30:00']]]), 0, 'running batch')['data']['batch_id'];
    $ids[] = $running;
    $json = api($slot(), 1, 'slot taken by a running batch of this branch');
    same('Time slot is busy. Do you want to continue ??', $json['message'], 'message');
    api($slot(['date' => date('Y-m-d', strtotime('-400 days'))]), 0, 'same slot on a date before the running batch started');
    $db->exec('UPDATE batch SET park = 1 WHERE id IN (' . implode(',', $ids) . ')');
});
$t->scenario('BATCH-17', 'public batch listing: schedule only for anonymous callers, full data for logged-in staff', function () use ($op, $db) {
    $db->exec("UPDATE contact SET mobile = '9000000004', email = 'trainer.one@bflow.invalid' WHERE id = 4");
    $db->exec("UPDATE batch SET whatsapp_link = 'https://chat.bflow.invalid/secret' WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM batch WHERE park = 0) x)");
    try {
        $cols = ['id', 'batch', 'display_name', 'start_time', 'end_time', 'day_code', 'fullname', 'mobile', 'email', 'whatsapp_link', 'zoom_id', 'contact_id'];
        $anon = $op->anonymous('batchRoute.php', 'selectWeb', ['col' => $cols, 'filter' => [['park', '=', '0'], ['mobile', '!=', '']]]);
        $json = api($anon, 0, 'anonymous listing');
        check(count($json['data']) > 0 && isset($json['data'][0]['batch']), 'anonymous listing lost the batch names');
        foreach (['9000000004', 'trainer.one@bflow.invalid', 'chat.bflow.invalid'] as $secret) {
            check(strpos($anon['body'], $secret) === false, "anonymous listing contains {$secret}");
        }
        $injected = $op->anonymous('batchRoute.php', 'selectWeb', ['col' => ['id'], 'filter' => [['batch', '=', "x' OR '1'='1"]], 'limit' => '1; DROP TABLE batch']);
        check($injected['json'] !== null && (int)$injected['json']['error'] === 1, 'quoted filter value was not treated as plain text: ' . substr($injected['body'], 0, 120));
        $staff = $op->call('batchRoute.php', 'selectWeb', ['col' => $cols, 'filter' => [['park', '=', '0']]]);
        check(strpos($staff['body'], '9000000004') !== false, 'logged-in support page no longer receives trainer mobiles');
        $forged = $op->anonymous('batchRoute.php', 'selectWeb', ['client_id' => (string)BFLOW_CLIENT_ID, 'col' => $cols, 'filter' => [['park', '=', '0']]], ['uid: ' . BFLOW_STAFF['operator']['user_id'], 'bid: 1']);
        check(strpos($forged['body'], '9000000004') === false, 'client_id + uid header unlocked the full listing');
    } finally {
        $db->exec("UPDATE contact SET mobile = '', email = '' WHERE id = 4");
    }
});

// ------------------------------------------------------------ members --

$t->scenario('MEM-01', 'add a member with a 12-class membership to the batch', function () use ($addMember, $db, $today, &$state) {
    $m = $addMember('Asha', 1, 12, $today, date('Y-m-d', strtotime('+90 days')), $state['batch']);
    $state['members']['asha'] = $m;
    check($m['item_id'] > 0, 'no invoice item id returned');
    $member = $db->row("SELECT code, status, bid FROM member WHERE contact_id = {$m['contact_id']}");
    check($member !== null, 'member row not created');
    same('1', $member['status'], 'member status');
    $item = $db->row("SELECT batch_id, sessions, sessions_used FROM invoice_item WHERE id = {$m['item_id']}");
    same($state['batch'], $item['batch_id'], 'item batch');
    same('12', $item['sessions'], 'item sessions');
    same('0', $item['sessions_used'], 'item sessions_used');
});
$t->scenario('MEM-02', 'add an unlimited member, a 2-class member, an expired and a future member', function () use ($addMember, $today, &$state) {
    $state['members']['ravi'] = $addMember('Ravi', 2, 0, $today, date('Y-m-d', strtotime('+90 days')), $state['batch']);
    $state['members']['meena'] = $addMember('Meena', 3, 2, $today, date('Y-m-d', strtotime('+90 days')), $state['batch']);
    $state['members']['old'] = $addMember('Expired', 1, 12, date('Y-m-d', strtotime('-120 days')), date('Y-m-d', strtotime('-1 day')), $state['batch']);
    $state['members']['future'] = $addMember('Future', 1, 12, date('Y-m-d', strtotime('+7 days')), date('Y-m-d', strtotime('+97 days')), $state['batch']);
});
$t->scenario('MEM-03', 'roster shows only members with a membership valid on the class date', function () use ($roster, $rosterIds, $today, &$state) {
    $ids = $rosterIds($roster($state['batch'], $today));
    foreach (['asha', 'ravi', 'meena'] as $key) {
        check(isset($ids[$state['members'][$key]['contact_id']]), "{$key} missing from today's roster");
        same(false, (bool)$ids[$state['members'][$key]['contact_id']]['inactive'], "{$key} inactive flag");
    }
    check(!isset($ids[$state['members']['old']['contact_id']]), 'expired member is on the roster');
    check(!isset($ids[$state['members']['future']['contact_id']]), 'future member is on the roster');
    $future = $rosterIds($roster($state['batch'], date('Y-m-d', strtotime('+8 days'))));
    check(isset($future[$state['members']['future']['contact_id']]), 'future member missing once the membership starts');
});
$t->scenario('MEM-03b', 'a member of a syllabus-category batch appears on its roster', function () use ($addMember, $roster, $rosterIds, $today, &$state) {
    $m = $addMember('Syllabus', 4, 12, $today, date('Y-m-d', strtotime('+90 days')), $state['syllabus']);
    $response = $roster($state['syllabus'], $today);
    check(isset($rosterIds($response)[$m['contact_id']]), 'member missing from the syllabus batch roster (module_id filter)');
    same(1, count(array_filter($response['json']['data'], function ($r) use ($m) { return (int)$r['contact_id'] === $m['contact_id']; })), 'roster rows for the member');
});
$t->scenario('BATCH-08c', 'a batch with members keeps its category and the page is told why', function () use ($updateBatch, $db, &$state) {
    $id = $state['batch'];
    $json = api($updateBatch($id, ['category_id' => '3', 'limit' => '25']), 1, 'category change with members');
    same('category locked', $json['error_msg'], 'error_msg');
    same('1', $db->value("SELECT category_id FROM batch WHERE id = {$id}"), 'category');
    same('20', $db->value("SELECT `limit` FROM batch WHERE id = {$id}"), 'other fields of the refused edit');
    api($updateBatch($id, ['category_id' => '1', 'limit' => '20']), 0, 'edit with the unchanged category');
});
$t->scenario('MEM-04', 'a second invoice for the same contact does not create a second member', function () use ($op, $db, $today, &$state) {
    $m = $state['members']['ravi'];
    $before = (int)$db->value("SELECT COUNT(*) FROM member WHERE contact_id = {$m['contact_id']}");
    $op->call('invoiceRoute.php', 'insert', [
        'contact_id' => (string)$m['contact_id'], 'bid' => '1', 'quotation' => 0, 'date' => $today, 'amount' => 0, 'tax' => 0, 'tax_amount' => 0, 'total' => 0, 'balance' => 0, 'notes' => '', 'category_id' => '1',
        'invoice_item' => [['category_id' => '1', 'service_id' => '2', 'offer_id' => '0', 'start_date' => date('Y-m-d', strtotime('+91 days')), 'end_date' => date('Y-m-d', strtotime('+180 days')), 'batch_id' => (string)$state['batch'], 'sessions' => '0', 'item_amount' => 0, 'item_discount' => 0, 'item_tax' => 0, 'item_tax_amount' => 0, 'item_total' => 0, 'item_tax_type' => '0', 'free_trial' => '0', 'module_id' => '0', 'billing_cycle' => '0', 'referral_code' => '', 'promo_code' => '']],
        'payment' => [['amount' => 0, 'pay_mode' => '1', 'date' => $today, 'reminder' => $today, 'clear' => 1, 'comment' => '']],
    ]);
    same($before, $db->value("SELECT COUNT(*) FROM member WHERE contact_id = {$m['contact_id']}"), 'member rows for the contact');
});
$t->scenario('MEM-05', 'invoice line created through the session-less app path is still eligible', function () use ($db, &$state) {
    $empty = (int)$db->value("SELECT COUNT(*) FROM invoice WHERE employee_id IS NULL OR employee_id = '' OR employee_id = 0");
    same(0, $empty, 'invoices saved without a sales employee (invisible in invoice_invoiceitem_view)');
});

// --------------------------------------------------------- attendance --

$t->scenario('ATT-01', 'punch class: present member deducts one class, absent member none, trainer recorded', function () use ($punch, $used, $att, $db, $today, &$state) {
    $asha = $state['members']['asha'];
    $meena = $state['members']['meena'];
    $trainer = ['contact_id' => (string)BFLOW_STAFF['trainer1']['contact_id'], 'type' => 'E', 'selected' => true, 'skip' => false, 'inactive' => false, 'code' => 'E4', 'fullname' => 'BFLOW Trainer One'];
    $json = api($punch($state['batch'], $today, [$asha['contact_id']], 0, [$trainer]), 0, 'punch');
    same('Batch attendance punched', $json['message'], 'message');
    same(1, $used($asha['item_id']), 'Asha classes used');
    same(0, $used($meena['item_id']), 'Meena (absent) classes used');
    same('0', $att($asha['contact_id'], $state['batch'], $today)[0]['negative'] ?? null, 'Asha present');
    same('1', $att($meena['contact_id'], $state['batch'], $today)[0]['negative'] ?? null, 'Meena absent');
    same('E', $att(BFLOW_STAFF['trainer1']['contact_id'], $state['batch'], $today)[0]['type'] ?? null, 'trainer row');
    same(1, $db->value("SELECT COUNT(*) FROM attendance_event WHERE person_id = {$asha['contact_id']}"), 'attendance events for Asha');
});
$t->scenario('ATT-02', 'punching the same class again changes nothing and says so', function () use ($punch, $used, $att, $today, &$state) {
    $asha = $state['members']['asha'];
    $response = $punch($state['batch'], $today, [$asha['contact_id']]);
    $json = api($response, 1, 'second punch');
    same('No new attendance saved; review individual results', $json['message'], 'message');
    same(1, $used($asha['item_id']), 'Asha classes used');
    same(1, count($att($asha['contact_id'], $state['batch'], $today)), 'Asha attendance rows');
});
$t->scenario('ATT-03', 'a second class on the same day (session 2) is recorded and deducted separately', function () use ($punch, $used, $att, $today, &$state) {
    $asha = $state['members']['asha'];
    api($punch($state['batch'], $today, [$asha['contact_id']], 2), 0, 'second session');
    same(2, $used($asha['item_id']), 'Asha classes used after two sessions');
    same(1, count($att($asha['contact_id'], $state['batch'], $today, 2)), 'session 2 rows');
});
$t->scenario('ATT-04', 'unlimited membership is recorded without deduction', function () use ($punch, $used, $att, $today, &$state) {
    $ravi = $state['members']['ravi'];
    api($punch($state['batch'], $today, [$ravi['contact_id']], 3), 0, 'punch unlimited');
    same(0, $used($ravi['item_id']), 'Ravi classes used');
    same('0', $att($ravi['contact_id'], $state['batch'], $today, 3)[0]['negative'] ?? null, 'Ravi present');
});
$t->scenario('ATT-05', 'absence deducts a class only when the branch setting says so', function () use ($punch, $used, $setAbsentDeduction, $today, &$state) {
    $meena = $state['members']['meena'];
    $setAbsentDeduction(1);
    try {
        api($punch($state['batch'], $today, [], 4), 0, 'punch with everyone absent');
        same(1, $used($meena['item_id']), 'Meena classes used after a deducted absence');
    } finally {
        $setAbsentDeduction(0);
    }
});
$t->scenario('ATT-06', 'a used-up membership drops off the roster and cannot be punched', function () use ($punch, $used, $roster, $rosterIds, $op, $today, &$state) {
    $meena = $state['members']['meena'];
    api($punch($state['batch'], $today, [$meena['contact_id']], 5), 0, 'last class');
    same(2, $used($meena['item_id']), 'Meena classes used');
    $row = $rosterIds($roster($state['batch'], $today, 6))[$meena['contact_id']] ?? null;
    check($row === null || $row['inactive'], 'used-up member is shown as an active roster entry');
    // A stale page (or a tampered request) still sends her as present:
    $response = $op->call('attendanceRoute.php', 'insertBatchAttendance', ['s_id' => 6, 'bid' => '1', 'time' => '07:00:00', 'date' => $today, 'batch_id' => (string)$state['batch'], 'check_month' => true,
        'contacts' => [['contact_id' => (string)$meena['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$meena['item_id'], 'code' => '', 'fullname' => 'BFLOW Meena']]]);
    check((int)($response['json']['error'] ?? 0) !== 0, 'exhausted member was punched');
    same(2, $used($meena['item_id']), 'Meena classes used after refused punch');
});
$t->scenario('ATT-07', 'previous-month attendance is refused for normal staff', function () use ($punch, $att, &$state) {
    $date = date('Y-m-d', strtotime('first day of last month'));
    $asha = $state['members']['old']; // membership ran until yesterday, so it covers last month
    $response = $punch($state['batch'], $date, [], 0, [['contact_id' => (string)$asha['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$asha['item_id'], 'code' => '', 'fullname' => 'BFLOW Asha']]);
    check((int)($response['json']['error'] ?? 0) !== 0 || $response['json'] === null, 'previous-month punch accepted');
    same(0, count($att($asha['contact_id'], $state['batch'], $date)), 'previous-month rows');
});
$t->scenario('ATT-08', 'previous-month restriction is enforced by the server, not by the browser flag', function () use ($punch, $att, &$state) {
    $date = date('Y-m-d', strtotime('first day of last month'));
    $asha = $state['members']['old']; // membership ran until yesterday, so it covers last month
    $punch($state['batch'], $date, [], 0, [['contact_id' => (string)$asha['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$asha['item_id'], 'code' => '', 'fullname' => 'BFLOW Asha']], null, false);
    same(0, count($att($asha['contact_id'], $state['batch'], $date)), 'previous-month rows with check_month=false sent by the client');
});
$t->scenario('ATT-09', 'a batch of this branch cannot be punched from another branch', function () use ($op, $port, $att, $today, &$state) {
    $other = BflowClient::login($port, BFLOW_STAFF['operator']['user_id'], 2);
    $ravi = $state['members']['ravi'];
    $other->call('attendanceRoute.php', 'insertBatchAttendance', ['s_id' => 7, 'bid' => '2', 'time' => '07:00:00', 'date' => $today, 'batch_id' => (string)$state['batch'], 'check_month' => true,
        'contacts' => [['contact_id' => (string)$ravi['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$ravi['item_id'], 'code' => '', 'fullname' => 'BFLOW Ravi']]]);
    same(0, count($att($ravi['contact_id'], $state['batch'], $today, 7)), 'rows written from the other branch');
});
$t->scenario('ATT-10', 'a member cannot be punched as staff to skip the deduction', function () use ($op, $used, $today, &$state) {
    $asha = $state['members']['asha'];
    $before = $used($asha['item_id']);
    $op->call('attendanceRoute.php', 'insertBatchAttendance', ['s_id' => 8, 'bid' => '1', 'time' => '07:00:00', 'date' => $today, 'batch_id' => (string)$state['batch'], 'check_month' => true,
        'contacts' => [['contact_id' => (string)$asha['contact_id'], 'type' => 'E', 'selected' => true, 'skip' => false, 'inactive' => false, 'code' => '', 'fullname' => 'BFLOW Asha']]]);
    same($before + 1, $used($asha['item_id']), 'Asha classes used after a punch sent with type E');
});
$t->scenario('ATT-11', 'two simultaneous punches of the same class record one attendance and one deduction', function () use ($op, $used, $att, $today, &$state, $port) {
    $ravi = $state['members']['ravi'];
    $asha = $state['members']['asha'];
    $body = json_encode(['session_id' => $op->sessionId, 's_id' => 9, 'bid' => '1', 'time' => '07:00:00', 'date' => $today, 'batch_id' => (string)$state['batch'], 'check_month' => true,
        'contacts' => [['contact_id' => (string)$asha['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$asha['item_id'], 'code' => '', 'fullname' => 'BFLOW Asha']]]);
    $before = $used($asha['item_id']);
    $multi = curl_multi_init();
    $handles = [];
    for ($i = 0; $i < 4; $i++) {
        $h = curl_init("http://127.0.0.1:{$port}/routes/attendanceRoute.php?action=insertBatchAttendance");
        curl_setopt_array($h, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Content-Type: application/json', "uid: {$op->userId}", 'bid: 1']]);
        curl_multi_add_handle($multi, $h);
        $handles[] = $h;
    }
    do { curl_multi_exec($multi, $running); curl_multi_select($multi); } while ($running);
    same(1, count($att($asha['contact_id'], $state['batch'], $today, 9)), 'attendance rows after 4 concurrent submits');
    same($before + 1, $used($asha['item_id']), 'deductions after 4 concurrent submits');
});

// ------------------------------------------------- corrections & cancel --

$t->scenario('FIX-01', 'delete (trash) a present attendance gives the class back; restore takes it again', function () use ($op, $db, $used, $today, &$state) {
    $asha = $state['members']['asha'];
    $row = $db->row("SELECT id FROM attendance WHERE contact_id = {$asha['contact_id']} AND batch_id = {$state['batch']} AND date = '{$today}' AND session_id = 2 AND park = 0");
    $before = $used($asha['item_id']);
    api($op->call('attendanceRoute.php', 'park', ['park_value' => '1', 'comment' => 'BFLOW wrong class', 'check_month' => true, 'd' => [['id', '=', $row['id']]]]), 0, 'trash attendance');
    same($before - 1, $used($asha['item_id']), 'classes used after trash');
    api($op->call('attendanceRoute.php', 'park', ['park_value' => '0', 'comment' => 'BFLOW restore', 'check_month' => true, 'd' => [['id', '=', $row['id']]]]), 0, 'restore attendance');
    same($before, $used($asha['item_id']), 'classes used after restore');
});
$t->scenario('FIX-02', 'restoring the only attendance of a fresh membership deducts it again', function () use ($op, $db, $used, $addMember, $punch, $today, &$state) {
    $m = $addMember('Kiran', 1, 12, $today, date('Y-m-d', strtotime('+90 days')), $state['batch2']);
    api($punch($state['batch2'], $today, [$m['contact_id']]), 0, 'punch Kiran');
    $id = $db->value("SELECT id FROM attendance WHERE contact_id = {$m['contact_id']} AND park = 0");
    api($op->call('attendanceRoute.php', 'park', ['park_value' => '1', 'comment' => 'x', 'check_month' => true, 'd' => [['id', '=', $id]]]), 0, 'trash');
    same(0, $used($m['item_id']), 'after trash');
    api($op->call('attendanceRoute.php', 'park', ['park_value' => '0', 'comment' => 'x', 'check_month' => true, 'd' => [['id', '=', $id]]]), 0, 'restore');
    same(1, $used($m['item_id']), 'after restore');
});
$t->scenario('FIX-03', 'changing present to absent in Edit Attendance gives the class back', function () use ($op, $db, $used, $today, &$state) {
    $asha = $state['members']['asha'];
    $row = $db->row("SELECT id FROM attendance WHERE contact_id = {$asha['contact_id']} AND batch_id = {$state['batch']} AND date = '{$today}' AND session_id = 0 AND park = 0");
    $before = $used($asha['item_id']);
    api($op->call('attendanceRoute.php', 'update', ['u' => [['col' => 'negative', 'val' => '1'], ['col' => 'comment', 'val' => 'BFLOW was absent']], 'filter' => [['id', '=', $row['id']]], 'bid' => '1']), 0, 'edit attendance');
    same($before - 1, $used($asha['item_id']), 'classes used after marking absent');
});
$t->scenario('FIX-04', 'cancel a class day and undo the cancellation', function () use ($op, $db, &$state) {
    $date = date('Y-m-d', strtotime('+1 day'));
    api($op->call('attendanceRoute.php', 'batchLog', ['bid' => '1', 'status' => '2', 'date' => $date, 'batch_id' => (string)$state['batch'], 'source' => '4']), 0, 'cancel class');
    $id = $db->value("SELECT id FROM attendance WHERE type = 'B' AND status = 2 AND batch_id = {$state['batch']} AND date = '{$date}' AND park = 0");
    check($id !== null, 'cancel log row not written');
    api($op->call('batchRoute.php', 'undoBatchCancel', ['id' => $id, 'user_id' => (string)$op->userId, 'comment' => 'BFLOW undo']), 0, 'undo cancel');
    same(0, $db->value("SELECT COUNT(*) FROM attendance WHERE id = {$id} AND park = 0"), 'active cancel log after undo');
});
$t->scenario('FIX-05', 'no member attendance can be punched for a cancelled class day', function () use ($op, $db, $punch, $att, &$state) {
    $date = date('Y-m-d', strtotime('+2 days'));
    api($op->call('attendanceRoute.php', 'batchLog', ['bid' => '1', 'status' => '2', 'date' => $date, 'batch_id' => (string)$state['batch'], 'source' => '4']), 0, 'cancel class');
    $ravi = $state['members']['ravi'];
    $punch($state['batch'], $date, [$ravi['contact_id']]);
    same(0, count(array_filter($att($ravi['contact_id'], $state['batch'], $date), function ($r) { return $r['type'] === 'M'; })), 'member rows on a cancelled day');
});

// ------------------------------------------- reschedule / class number --

$t->scenario('LIFE-01', 'reschedule one class to another day: child batch, roster and deduction from the parent membership', function () use ($op, $db, $used, $roster, $rosterIds, $punch, $att, &$state) {
    $parent = $state['batch'];
    $date = date('Y-m-d', strtotime('+3 days'));
    $new = date('Y-m-d', strtotime('+4 days'));
    // rebatchController.js: slot check, insert with parent_id + rebatch, then the "shifted" log (status 3)
    $op->call('batchRoute.php', 'checkBatchOverlap', ['day_code' => date('w', strtotime($new)), 'start_time' => '17:00:00', 'end_time' => '18:00:00', 'bid' => '1']);
    $json = api($op->call('batchRoute.php', 'insert', ['category_id' => '1', 'batch' => 'BFLOW Morning Dance Rescheduled', 'parent_id' => (string)$parent,
        'contact_id' => [(string)BFLOW_STAFF['trainer1']['contact_id']], 'batch_time' => [['day_code' => date('w', strtotime($new)), 'start_time' => '17:00:00', 'end_time' => '18:00:00']],
        'start_date' => $new, 'end_date' => $new, 'bid' => '1', 'rate_multiplier' => '1', 'offline' => '1', 'venue_id' => '1', 'rebatch' => true]), 0, 'reschedule insert');
    $child = (int)$json['data']['batch_id'];
    $state['child'] = $child;
    api($op->call('attendanceRoute.php', 'batchLog', ['status' => '3', 'date' => $date, 'batch_id' => (string)$parent, 'source' => '4', 'new_batch_id' => (string)$child, 'bid' => '1']), 0, 'shift log');
    same((string)$parent, $db->value("SELECT parent_id FROM batch WHERE id = {$child}"), 'child parent_id');
    $ravi = $state['members']['ravi'];
    check(isset($rosterIds($roster($child, $new))[$ravi['contact_id']]), "parent's member missing from the rescheduled class roster");
    api($punch($child, $new, [$ravi['contact_id']]), 0, 'punch rescheduled class');
    same('0', $att($ravi['contact_id'], $child, $new)[0]['negative'] ?? null, 'present in the rescheduled class');
});
$t->scenario('LIFE-02', 'the same class cannot be rescheduled twice to the same date', function () use ($op, $db, &$state) {
    $new = date('Y-m-d', strtotime('+4 days'));
    $json = api($op->call('batchRoute.php', 'insert', ['category_id' => '1', 'batch' => 'BFLOW Morning Dance Rescheduled', 'parent_id' => (string)$state['batch'],
        'contact_id' => [(string)BFLOW_STAFF['trainer1']['contact_id']], 'batch_time' => [['day_code' => date('w', strtotime($new)), 'start_time' => '17:00:00', 'end_time' => '18:00:00']],
        'start_date' => $new, 'end_date' => $new, 'bid' => '1', 'rate_multiplier' => '1', 'offline' => '1', 'venue_id' => '1', 'rebatch' => true]), 1, 'second reschedule');
    same('already exist', $json['error_msg'], 'error_msg');
});
$t->scenario('LIFE-03', 'class number: forward needs the trainer attendance, moves once per date, next attendance uses it', function () use ($op, $db, $punch, $addMember, $att, &$state) {
    $batch = $state['batch2'];
    $date = date('Y-m-d', strtotime('-1 day'));
    $db->exec("UPDATE batch SET start_date='$date' WHERE id=$batch");
    $before = (int)$db->value("SELECT completed_sessions FROM batch WHERE id = {$batch}");
    $forward = function () use ($op, $batch, $date, $before) {
        // batchClassLevelForwardController.js btnClick('confirm')
        return $op->call('batchRoute.php', 'forwardClass', ['u' => [['col' => 'completed_sessions', 'val' => $before + 1]], 'batch_date' => $date, 'to_class_no' => $before + 1, 'from_class_no' => $before, 'forward' => 1, 'batch_id' => (string)$batch, 'filter' => [['id', '=', (string)$batch]]]);
    };
    api($forward(), 1, 'forward before the trainer attendance exists');
    $trainer = ['contact_id' => (string)BFLOW_STAFF['trainer2']['contact_id'], 'type' => 'E', 'selected' => true, 'skip' => false, 'inactive' => false, 'code' => 'E5', 'fullname' => 'BFLOW Trainer Two'];
    api($punch($batch, $date, [], 0, [$trainer]), 0, 'trainer attendance for yesterday');
    api($forward(), 0, 'forward');
    same($before + 1, $db->value("SELECT completed_sessions FROM batch WHERE id = {$batch}"), 'completed sessions');
    same(1, $db->value("SELECT COUNT(*) FROM class_forward_log WHERE batch_id = {$batch} AND batch_date = '{$date}'"), 'forward log rows');
    api($forward(), 2, 'second forward for the same date');
    same($before + 1, $db->value("SELECT completed_sessions FROM batch WHERE id = {$batch}"), 'completed sessions after the refused second forward');
    $m = $addMember('ClassNo', 1, 12, date('Y-m-d', strtotime('-2 days')), date('Y-m-d', strtotime('+60 days')), $batch);
    api($punch($batch, date('Y-m-d'), [$m['contact_id']], 1), 0, 'punch today');
    same((string)($before + 2), $db->value("SELECT class_no FROM attendance WHERE contact_id = {$m['contact_id']} AND batch_id = {$batch} AND park = 0"), 'class number recorded with the attendance');
});
$t->scenario('LIFE-04', 'forwarding the class of a rescheduled (child) batch moves the parent batch number', function () use ($op, $db, $punch, &$state) {
    $child = $state['child'];
    $parent = $state['batch'];
    $date = date('Y-m-d', strtotime('+4 days'));
    $before = (int)$db->value("SELECT completed_sessions FROM batch WHERE id = {$parent}");
    $trainer = ['contact_id' => (string)BFLOW_STAFF['trainer1']['contact_id'], 'type' => 'E', 'selected' => true, 'skip' => false, 'inactive' => false, 'code' => 'E4', 'fullname' => 'BFLOW Trainer One'];
    $punch($child, $date, [], 5, [$trainer]);
    api($op->call('batchRoute.php', 'forwardClass', ['u' => [['col' => 'completed_sessions', 'val' => $before + 1]], 'batch_date' => $date, 'to_class_no' => $before + 1, 'from_class_no' => $before, 'forward' => 1, 'batch_id' => (string)$child, 'filter' => [['id', '=', (string)$child]]]), 0, 'forward child');
    same($before + 1, $db->value("SELECT completed_sessions FROM batch WHERE id = {$parent}"), 'parent completed sessions');
});
$t->scenario('LIFE-05', 'set class number and level', function () use ($op, $db, &$state) {
    $db->exec("INSERT INTO level (id, level) VALUES (7, 'BFLOW Level 2')");
    // batchClassLevelController.js btnClick('confirm')
    api($op->call('batchRoute.php', 'update', ['u' => [['col' => 'completed_sessions', 'val' => '20'], ['col' => 'level_id', 'val' => '7']], 'filter' => [['id', '=', (string)$state['batch2']]]]), 0, 'level update');
    $row = $db->row("SELECT completed_sessions, level_id FROM batch WHERE id = {$state['batch2']}");
    same(['completed_sessions' => '20', 'level_id' => '7'], $row, 'class number and level');
});
$t->scenario('LIFE-06', 'a trashed batch takes no attendance and says why', function () use ($op, $db, $addMember, $createBatch, $today) {
    $id = (int)api($createBatch('BFLOW Closing'), 0, 'insert')['data']['batch_id'];
    $m = $addMember('Closing', 1, 12, $today, date('Y-m-d', strtotime('+30 days')), $id);
    api($op->call('batchRoute.php', 'park', ['park_value' => '1', 'd' => [['id', 'IN', [(string)$id]]]]), 0, 'trash');
    $response = $op->call('attendanceRoute.php', 'insertBatchAttendance', ['s_id' => 0, 'bid' => '1', 'time' => '07:00:00', 'date' => $today, 'batch_id' => (string)$id, 'check_month' => true,
        'contacts' => [['contact_id' => (string)$m['contact_id'], 'type' => 'M', 'selected' => true, 'skip' => false, 'inactive' => false, 'item_id' => (string)$m['item_id'], 'code' => '', 'fullname' => 'BFLOW Closing']]]);
    check((int)($response['json']['error'] ?? 0) !== 0, 'trashed batch accepted attendance');
    same(0, $db->value("SELECT COUNT(*) FROM attendance WHERE batch_id = {$id} AND type = 'M'"), 'rows on a trashed batch');
});

// ---------------------------------------------------------- transfers --

$t->scenario('XFER-01', 'transfer a member to another batch: roster moves, classes used carry over', function () use ($op, $db, $used, $roster, $rosterIds, $today, &$state) {
    $asha = $state['members']['asha'];
    $before = $used($asha['item_id']);
    api($op->call('invoiceRoute.php', 'updateInvoiceItem', ['u' => [['col' => 'batch_id', 'val' => (string)$state['batch2']], ['col' => 'item_change', 'val' => '4']], 'filter' => [['id', '=', (string)$asha['item_id']]]]), 0, 'move item');
    api($op->call('memberRoute.php', 'insertBatchTransfer', ['from_id' => (string)$state['batch'], 'to_id' => (string)$state['batch2'], 'item_id' => (string)$asha['item_id'], 'bid' => '1', 'comment' => 'BFLOW transfer']), 0, 'transfer log');
    $date = date('Y-m-d', strtotime('+2 days'));
    check(!isset($rosterIds($roster($state['batch'], $date))[$asha['contact_id']]), 'still on the old roster');
    check(isset($rosterIds($roster($state['batch2'], $date))[$asha['contact_id']]), 'missing from the new roster');
    same($before, $used($asha['item_id']), 'classes used after transfer');
    same(1, $db->value("SELECT COUNT(*) FROM batch_transfer WHERE item_id = {$asha['item_id']}"), 'transfer log rows');
});

exit($t->finish());
