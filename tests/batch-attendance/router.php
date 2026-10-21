<?php
// Router for the disposable PHP built-in server started by run.sh. It adds a
// single test-only login endpoint; every other request is served from the
// temporary copy of the code exactly as nginx/FPM would serve it.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
if (!preg_match('/^ccc_database_test_bflow_[a-f0-9]{8}$/D', (string)getenv('BFLOW_TENANT_DB'))) {
    http_response_code(404);
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/__bflow/login') {
    if (preg_match('#^/routes/#', $path)) {
        // Route files use relative requires ("../controllers/..."), resolved from their own folder under FPM.
        chdir(__DIR__ . '/routes');
    }
    return false;
}

if (!hash_equals((string)getenv('BFLOW_KEY'), $_SERVER['HTTP_X_BFLOW_KEY'] ?? '')) {
    http_response_code(403);
    exit;
}

// Same session shape as userAuthentication::login (controllers/userAuthentication.php:118).
require_once __DIR__ . '/classes/dbconnection.class.php';
$userId = (int)($_GET['user_id'] ?? 0);
$bid = (int)($_GET['bid'] ?? 1);
$central = (new dbconnection())->connect('ccc_central');
$user = $central->query("SELECT u.id, u.admin, u.fname, u.lname, u.contact_id, u.position_id, u.client_id, u.type, u.auth_epoch, cd.db_name, cd.logo FROM user u JOIN client_db cd ON cd.id = u.client_id WHERE u.id = {$userId} AND u.park = 0 AND u.inactive = 0")->fetch_assoc();
if (!$user) {
    http_response_code(404);
    echo json_encode(['error' => 1, 'message' => 'no such test user']);
    exit;
}
$tenant = (new dbconnection())->connect($user['db_name']);
$employee = $tenant->query("SELECT id FROM employee WHERE contact_id = '{$user['contact_id']}' LIMIT 1")->fetch_assoc();
$branches = [];
$result = $tenant->query('SELECT id, branch, type FROM branch ORDER BY id');
while ($row = $result->fetch_assoc()) {
    $branches[] = ['id' => (string)$row['id'], 'branch' => $row['branch'], 'type' => $row['type']];
}

session_start();
session_regenerate_id(true);
$_SESSION = [
    'auth_epoch'=>(int)$user['auth_epoch'], 'config' => [], 'permissions' => [], 'firstLogin' => 0, 'db_name' => $user['db_name'], 'bid' => (string)$bid,
    'client_id' => $user['client_id'], 'user_id' => $user['id'], 'admin' => $user['admin'],
    'user_name' => $user['fname'] . ' ' . $user['lname'], 'inst_logo' => $user['logo'], 'session_id' => session_id(),
    'branch' => $branches, 'position_id' => $user['position_id'], 'employee_id' => $employee['id'] ?? null,
    'token' => 'bflow-' . bin2hex(random_bytes(8)), 'contact_id' => $user['contact_id'], 'user_type' => $user['type'],
    'home_page' => '/pf/dashboard.php',
];
header('Content-Type: application/json');
echo json_encode(['session_id' => session_id(), 'token' => $_SESSION['token'], 'user_id' => $user['id'], 'bid' => $bid]);
