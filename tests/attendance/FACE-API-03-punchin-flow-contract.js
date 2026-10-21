#!/usr/bin/env node
/* FACE-API-03 punch-in flow contract, created before the flow correction. */

const fs = require('fs');

const api = fs.readFileSync('classes/services/FaceAttendanceApiService.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const inference = fs.readFileSync('services/face-inference/app/service.py', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(api.includes('recordAcceptedFaceAttendance') && api.includes('recordMemberAttendance'),
    'accepted live recognition must use the attendance domain transaction');
assert(api.includes('NO_MAPPED_CANDIDATES'),
    'scan start must report when the selected roster has no mapped children');
assert(api.includes('automaticAttendanceEnabled') && api.includes("'ATTENDANCE_RECORDED'"),
    'attendance state must distinguish configured automatic writes');
assert(controller.includes("body.effect === 'SELECT_PRESENT'"),
    'browser must honor the canonical reversible row-selection effect');
assert(controller.includes("'ATTENDANCE_RECORDED', 'ALREADY_PRESENT'"),
    'browser must reflect automatic attendance writes without a second commit');
assert(controller.includes('body.member_id'),
    'browser must use the server-selected member identity');
assert(controller.includes('body.state !== \'STARTED\''),
    'browser must handle scan-start rejection without entering a false scanning state');
assert(controller.includes('negative = 0') || controller.includes('negative = 0;'),
    'accepted face punch-in must update the selected child to Present');
assert(inference.includes('candidate_ref=result.candidate_ref'),
    'calibration handling must retain the candidate for an automatic no-match result');

console.log('PASS FACE-API-03 punch-in flow contract');
