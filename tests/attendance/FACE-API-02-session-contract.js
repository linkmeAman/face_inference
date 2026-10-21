#!/usr/bin/env node

const assert = require('assert');
const fs = require('fs');

const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

function functionBlock(name) {
    const start = controller.indexOf('function ' + name + '(');
    assert(start >= 0, 'face attendance function is missing: ' + name);
    const end = controller.indexOf('\n    function ', start + 10);
    return controller.slice(start, end >= 0 ? end : controller.length);
}

['requestFaceScanStart', 'captureFaceAttempt', 'completeFaceScan', 'cancelFaceScan'].forEach(function(action) {
    const block = functionBlock(action);
    assert(block.includes('session_id'), action + ' must send the application session_id');
});

const startRequestBlock = functionBlock('requestFaceScanStart');
assert(startRequestBlock.includes('session_id: session_id'), 'requestFaceScanStart must send the application session_id');
assert(startRequestBlock.includes('attendance_session_id: attendanceSessionId'), 'requestFaceScanStart must keep the selected attendance session separate');
assert.strictEqual((startRequestBlock.match(/\bsession_id\s*:/g) || []).length, 1, 'requestFaceScanStart must not overwrite application session_id');

const service = fs.readFileSync('classes/services/FaceAttendanceApiService.php', 'utf8');
assert(service.includes("$request['attendance_session_id']"), 'server must accept the separate attendance session field');
assert(service.includes("$request['s_id']"), 'server must preserve the existing s_id compatibility field');

assert(controller.includes("reason: reason"), 'face error logging must retain the server reason');
assert(controller.includes("reason === 'SESSION_REQUIRED'"), 'session failures must be visible to the teacher');
assert(controller.includes('Refresh the page and sign in again'), 'session failure guidance must be actionable');
console.log('PASS face-api-session-contract');
