<?php
// Seeds the disposable databases of one run.sh invocation with the minimum
// configuration an institute needs before batches can be created: branches,
// staff logins/permissions, a category with master sessions, membership
// services and attendance/invoice/membership settings. All people are
// fictitious ("BFLOW ..."), all contact details blank.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$tenantDb = getenv('BFLOW_TENANT_DB');
$centralDb = getenv('BFLOW_CENTRAL_DB');
if (!preg_match('/^ccc_database_test_bflow_[a-f0-9]{8}$/D', (string)$tenantDb) || !preg_match('/^ccc_central_test_bflow_[a-f0-9]{8}$/D', (string)$centralDb)) {
    fwrite(STDERR, "seed.php must be started by run.sh\n");
    exit(2);
}
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$central = new mysqli('localhost', getenv('BFLOW_DB_USER'), getenv('BFLOW_DB_PASS'), $centralDb);
$tenant = new mysqli('localhost', getenv('BFLOW_DB_USER'), getenv('BFLOW_DB_PASS'), $tenantDb);
require __DIR__ . '/fixture.php';
require_once dirname(__DIR__,2).'/classes/services/ModuleCurrencySchema.php';
ModuleCurrencySchema::apply($tenant,true);
$central->query('ALTER TABLE user MODIFY password VARCHAR(255), MODIFY mpin VARCHAR(255)');
if(!$central->query("SHOW COLUMNS FROM user LIKE 'auth_epoch'")->num_rows)$central->query('ALTER TABLE user ADD COLUMN auth_epoch INT UNSIGNED NOT NULL DEFAULT 1');
$tenant->multi_query(file_get_contents(dirname(__DIR__,2).'/database/migrations/20261015_01_module_flow.sql'));
while($tenant->more_results()){$tenant->next_result();if($result=$tenant->store_result())$result->free();}


$now = date('Y-m-d H:i:s');

// ---- central: institute, module subscriptions, staff logins, permissions ----
$central->query("INSERT INTO client_db (id, inst_name, db_name, inst_type, parent_id, logo, inactive) VALUES (" . BFLOW_CLIENT_ID . ", 'BFLOW Test Institute', 'ccc_database', 0, 0, '', 0)");
$moduleIds = [];
foreach (BFLOW_MODULES as $name) {
    $row = $central->query("SELECT id FROM module WHERE module = '" . $central->real_escape_string($name) . "' AND park = 0")->fetch_assoc();
    if (!$row) { fwrite(STDERR, "module catalogue lacks {$name}\n"); exit(2); }
    $moduleIds[$name] = (int)$row['id'];
    $central->query("INSERT INTO client_module (client_id, module_id, active, module) VALUES (" . BFLOW_CLIENT_ID . ", {$row['id']}, 1, '" . $central->real_escape_string($name) . "')");
}
foreach (BFLOW_STAFF as $key => $staff) {
    $central->query("INSERT INTO user (id, fname, lname, admin, client_id, position_id, contact_id, type, inactive, park, signup, is_first_login, created_at) VALUES ({$staff['user_id']}, 'BFLOW', '{$staff['name']}', 0, " . BFLOW_CLIENT_ID . ", {$staff['position_id']}, {$staff['contact_id']}, 'E', 0, 0, 1, 0, '{$now}')");
    foreach ($staff['permissions'] as $bid => $level) {
        $cms = $central->query("SELECT id FROM client_module WHERE client_id = " . BFLOW_CLIENT_ID);
        while ($cm = $cms->fetch_assoc()) {
            $central->query("INSERT INTO user_permission (user_id, cm_id, permission, bid) VALUES ({$staff['user_id']}, {$cm['id']}, {$level}, {$bid})");
        }
    }
}

// ---- tenant: branches and institute settings ----
foreach (BFLOW_BRANCHES as $bid => $name) {
    $tenant->query("INSERT INTO branch (id, branch, branch_name_actual, name, type, park, timezone) VALUES ({$bid}, '{$name}', '{$name}', '{$name}', 0, 0, 'Asia/Kolkata')");
    $tenant->query("INSERT INTO attendance_settings (punch_dif, bio_type, bio_extension, skip_session, deduct_session_absentee, mark_absent, bid, created_at) VALUES ('00:05:00', 1, 0, 0, 0, 0, {$bid}, '{$now}')");
    $tenant->query("INSERT INTO invoice_settings (start_invoice, start_quotation, start_receipt, inquiry_convert, bid, created_at, allow_zero_payment) VALUES (1, 1, 1, 0, {$bid}, '{$now}', 1)");
    $tenant->query("INSERT INTO membership_settings (start_code, ex_code, ex_period, renewal, bid, created_at) VALUES ({$bid}000, 0, 0, 0, {$bid}, '{$now}')");
    $tenant->query("INSERT INTO batch_settings (batch, sessions, transfer, check_emp_dup, bid, create_by, created_at) VALUES (1, 1, 1, 0, {$bid}, 0, '{$now}')");
}
$tenant->query("INSERT INTO studio_setting (inter_branch_member, member_start_code, inter_branch_invoice, created_at) VALUES (0, 1000, 0, '{$now}')");
$tenant->query("INSERT INTO contact_group (id, contact_group) VALUES (1, 'Member'), (2, 'Employee')");
$tenant->query("INSERT INTO venue (id, venue, display_name, park, created_at) VALUES (1, 'BFLOW Studio', 'BFLOW Studio', 0, '{$now}')");

// ---- tenant: staff (operator, viewer, no-permission user, two trainers) ----
foreach (BFLOW_STAFF as $staff) {
    $tenant->query("INSERT INTO contact (id, contact_group_id, fname, lname, mobile, email, bid, park, created_at) VALUES ({$staff['contact_id']}, 2, 'BFLOW', '{$staff['name']}', '', '', 1, 0, '{$now}')");
    $tenant->query("INSERT INTO employee (id, contact_id, ecode, position_id, status, type, park, created_at) VALUES ({$staff['employee_id']}, {$staff['contact_id']}, 'E{$staff['employee_id']}', {$staff['position_id']}, 1, 0, 0, '{$now}')");
    $tenant->query("INSERT INTO employee_bid (employee_id, bid, created_at) VALUES ({$staff['employee_id']}, 1, '{$now}')");
}

// ---- tenant: what a batch is made of ----
foreach (BFLOW_CATEGORIES as $category) {
    // category -> master category -> module: session_master_view only sees master sessions through a module.
    $tenant->query("INSERT INTO category_master (id, master_category, park, created_at) VALUES ({$category['id']}, '{$category['name']}', 0, '{$now}')");
    $tenant->query("INSERT INTO category (id, category, type, master_category_id, bid, park, created_at) VALUES ({$category['id']}, '{$category['name']}', 0, {$category['id']}, 1, 0, '{$now}')");
    if ($category['master_sessions'] > 0) {
        $tenant->query("INSERT INTO module (id, module, category_id, park, created_at) VALUES ({$category['id']}, '{$category['name']} syllabus', {$category['id']}, 0, '{$now}')");
    }
    for ($n = 1; $n <= $category['master_sessions']; $n++) {
        $tenant->query("INSERT INTO session_master (category_id, module_id, title, park, created_at) VALUES ({$category['id']}, {$category['id']}, 'Class {$n}', 0, '{$now}')");
    }
}
foreach (BFLOW_SERVICES as $service) {
    $tenant->query("INSERT INTO service (id, category_id, service, display_name, duration, duration_type, amount, tax, tax_type, tax_amount, total, batch, sessions, product, inactive, park, bid, module_id, created_at) VALUES ({$service['id']}, {$service['category_id']}, '{$service['name']}', '{$service['name']}', 3, 'month', 0, 0, 0, 0, 0, 1, {$service['sessions']}, 0, 0, 0, 1, 0, '{$now}')");
}

echo "seeded: client " . BFLOW_CLIENT_ID . ", " . count(BFLOW_STAFF) . " staff, " . count(BFLOW_CATEGORIES) . " categories, " . count(BFLOW_SERVICES) . " services\n";
