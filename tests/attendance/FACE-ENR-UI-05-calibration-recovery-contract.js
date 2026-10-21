#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const start = controller.indexOf("body.reason === 'VERIFICATION_FAILED'");
const end = controller.indexOf('} else {', start);
assert(start >= 0 && end > start, 'server readiness failure branch must remain explicit');
const branch = controller.slice(start, end);
assert(branch.includes("$scope.faceEnrollmentState = 'READY'"), 'failure must return to retryable setup state');
assert(branch.includes('No face was saved'), 'failure must confirm that nothing changed');
assert(branch.includes('ask an administrator to enable it'), 'staff must receive a plain-language recovery step');
assert(!page.includes('score-only calibration'), 'internal calibration mechanics must not be shown to staff');
assert(controller.includes('$scope.faceEnrollmentFrameCount = 0'), 'temporary frames must be reset');
console.log('PASS FACE-ENR-UI-05 readiness recovery contract');
