#!/usr/bin/env node

'use strict';

const assert = require('assert');
const fs = require('fs');

const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const enrollment = fs.readFileSync('classes/services/FaceEnrollmentService.php', 'utf8');

assert(page.includes('Front Camera · Mirror view'), 'front-camera control must describe the mirrored presentation');
assert(page.includes('.face-capture-freeze.is-mirrored'), 'frozen front-camera presentation must remain mirrored');
assert(controller.includes('CSS mirrors only the presentation'), 'raw capture orientation must be explicitly canonical');
assert(controller.includes("still.className = 'face-capture-freeze' + ($scope.cameraFacingMode === 'user' ? ' is-mirrored' : '')"), 'freeze overlay must follow presentation mirror state');
assert(!controller.includes("video.style.visibility = 'hidden'"), 'capture must not blink by hiding the live video element');
assert(controller.includes('}, idx === 2);'), 'reference burst must freeze only its final frame');
assert(controller.includes('}, index === 7);'), 'enrollment burst must freeze only its final frame');
assert(controller.includes('}, index === 2);'), 'attendance burst must freeze only its final frame');
assert(controller.includes("if ($scope.faceEnrollmentReferenceBusy || $scope.faceEnrollmentState === 'CAPTURING'"), 'preview polling must pause during reference capture');
assert(controller.includes('var renderX = isMirrored ? (intrinsicWidth - right) : x;'), 'raw detector boxes must map into mirrored display coordinates');
assert(enrollment.includes("'PAD_CALIBRATION_REQUIRED'"), 'unapproved PAD calibration must return a stable administrative reason');
assert(enrollment.includes('A reference preview is only one short capture'), 'reference duplicate evidence must remain advisory before the PAD calibration gate');
assert(!enrollment.includes("'reason' => 'REFERENCE_REQUIRED'"), 'first-time preview must not skip duplicate detection when reference is absent');
assert(controller.includes('this is not caused by positioning'), 'calibration failure must not blame operator positioning');

console.log('PASS FACE-CAM-02 mirror and capture stability contract');
