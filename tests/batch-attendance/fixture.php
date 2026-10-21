<?php
// Shared, fictitious fixture identities used by seed.php and flow.php.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

const BFLOW_CLIENT_ID = 9269;
const BFLOW_MODULES = ['Batch', 'Batch Session', 'Batch Finder', 'Attendance', 'Member', 'Invoice', 'Contact', 'Employee', 'Settings'];
const BFLOW_BRANCHES = [1 => 'BFLOW Main', 2 => 'BFLOW Other'];
// permissions: bid => level (1 read, 2 write, 3 delete)
const BFLOW_STAFF = [
    'operator' => ['user_id' => 9001, 'contact_id' => 1, 'employee_id' => 1, 'position_id' => 3, 'name' => 'Operator', 'permissions' => [1 => 3]],
    'viewer'   => ['user_id' => 9002, 'contact_id' => 2, 'employee_id' => 2, 'position_id' => 3, 'name' => 'Viewer', 'permissions' => [1 => 1]],
    'nobody'   => ['user_id' => 9003, 'contact_id' => 3, 'employee_id' => 3, 'position_id' => 3, 'name' => 'NoAccess', 'permissions' => []],
    'trainer1' => ['user_id' => 9004, 'contact_id' => 4, 'employee_id' => 4, 'position_id' => 5, 'name' => 'Trainer One', 'permissions' => []],
    'trainer2' => ['user_id' => 9005, 'contact_id' => 5, 'employee_id' => 5, 'position_id' => 5, 'name' => 'Trainer Two', 'permissions' => []],
];
// Category 1 is configured like production categories (no syllabus module, no
// master sessions). Category 2 has a syllabus module with 12 master sessions.
const BFLOW_CATEGORIES = [
    ['id' => 1, 'name' => 'BFLOW Dance', 'master_sessions' => 0],
    ['id' => 2, 'name' => 'BFLOW Syllabus', 'master_sessions' => 12],
];
const BFLOW_SERVICES = [
    ['id' => 1, 'category_id' => 1, 'name' => 'BFLOW 12 classes', 'sessions' => 12],
    ['id' => 2, 'category_id' => 1, 'name' => 'BFLOW Unlimited', 'sessions' => 0],
    ['id' => 3, 'category_id' => 1, 'name' => 'BFLOW 2 classes', 'sessions' => 2],
    ['id' => 4, 'category_id' => 2, 'name' => 'BFLOW Syllabus 12 classes', 'sessions' => 12],
];
