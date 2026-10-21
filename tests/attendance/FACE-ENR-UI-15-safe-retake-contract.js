/* Safe retake browser contract. */
const fs = require('fs');
const assert = require('assert');
const root = require('path').resolve(__dirname, '../..');
const html = fs.readFileSync(root + '/pf/attendance.php', 'utf8');
const js = fs.readFileSync(root + '/pf/js/controllers/batchAttendanceController.js', 'utf8');

assert.match(html, /Reference Portrait \(Awaiting Verification\)/);
assert.match(html, /face-reference-badge[\s\S]*?ng-click="clearFaceReference\(\)"/);
assert.match(html, /faceEnrollmentIssue\.visible[\s\S]*?ng-click="clearFaceReference\(\)"/);
assert.match(js, /function clearFaceReference\(\)/);
assert.match(js, /faceEnrollmentState = 'REFERENCE_REQUIRED'/);
assert.match(js, /enrollmentFrames = \[\]/);
assert.match(js, /clearTimeout\(referenceCaptureTimer\)/);
assert.match(js, /captureRequestSequence\+\+/);
assert.match(js, /requestSequence !== captureRequestSequence/);
assert.match(js, /capture_session_token/);
assert.match(html, /Face re-enrollment reviews/);
assert.match(html, /I verified this child in person/);
assert.match(js, /action: 'reviewReenrollmentAttempt'/);
console.log('PASS FACE-ENR-UI-15 safe retake contract');
