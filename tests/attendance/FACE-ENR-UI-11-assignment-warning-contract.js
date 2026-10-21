#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
assert(page.includes('ng-if="faceEnrollmentIssue.visible"') && page.includes('role="alert"'), 'duplicate assignment must remain a visible alert');
assert(page.includes('This face belongs to') && page.includes('Bring {{faceEnrollmentMemberName}} into view'), 'warning must identify the conflict and next step');
const start=controller.indexOf("body.reason === 'FACE_ALREADY_ASSIGNED'"); const end=controller.indexOf('} else if',start); const branch=controller.slice(start,end);
assert(branch.includes('$scope.faceEnrollmentIssue.visible = true'), 'duplicate must persist in structured issue state');
assert(branch.includes("$scope.faceEnrollmentState = 'READY'"), 'teacher must be able to retry');
assert(branch.includes('$scope.faceEnrollmentFrameCount = 0'), 'rejected capture must reset');
assert(!branch.includes('face_mapping_active = true'), 'duplicate must never map the selected child');
console.log('PASS FACE-ENR-UI-11 concise assignment warning contract');
