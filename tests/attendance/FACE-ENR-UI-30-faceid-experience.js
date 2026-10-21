const assert = require('assert');
const fs = require('fs');

const view = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

assert.match(view, /class="faceid-viewport"/, 'enrollment must have a centered FaceID viewport');
assert.match(view, /class="faceid-background-scrim"/, 'viewport must mask the area outside the face ring');
assert.strictEqual((view.match(/class="faceid-ring-segment"/g) || []).length, 6,
    'the face ring must expose exactly six visual progress segments');
assert.match(view, /object-fit: cover/, 'enrollment video must fill its immersive viewport');
assert.match(view, /backdrop-filter: blur\(10px\)/, 'background outside the ring must blur');
assert.match(view, /class="faceid-guidance-pill"/, 'worker guidance must remain inside the camera viewport');
assert.doesNotMatch(view, /id="faceEnrollmentGuide"/, 'the legacy static enrollment oval must not return');

assert.match(controller, /function resetEnrollmentRing\(\)/, 'ring state must reset per enrollment');
assert.match(controller, /function setEnrollmentRingActive\(index\)/, 'ring must expose an active segment transition');
assert.match(controller, /function completeEnrollmentRingStep\(index\)/, 'ring must complete only after an accepted candidate');
assert.match(controller, /setEnrollmentRingActive\(slot >= 5 \? 5 : slot\)/,
    'the continuous sweep must drive the ring from its capture slot');
assert.match(controller, /if \(slot === 7\) completeEnrollmentRingStep\(5\)/,
    'the final segment must complete after the final server-accepted frame');

console.log('FACE-ENR-UI-30 FaceID experience contract PASS');
