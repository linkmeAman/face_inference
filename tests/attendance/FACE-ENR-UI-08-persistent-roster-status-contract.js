#!/usr/bin/env node
/* FACE-ENR-UI-08 persistent mapping status contract, created before the fix. */

const fs = require('fs');

const memberController = fs.readFileSync('controllers/member.php', 'utf8');
const attendanceController = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(memberController.includes('face_mapping_active'),
    'the server roster response must expose persistent face mapping status');
assert(memberController.includes('face_template'),
    'the roster status must be sourced from active face templates');
assert(memberController.includes("`ft`.`status` = 'ACTIVE'"),
    'only active face templates may hide the mapping action');
assert(attendanceController.includes('face_mapping_active'),
    'the browser must consume the persistent mapping status');

console.log('PASS FACE-ENR-UI-08 persistent roster status contract');
