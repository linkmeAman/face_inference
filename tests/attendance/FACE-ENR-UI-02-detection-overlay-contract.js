#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
assert(page.includes('faceEnrollmentGuide') && page.includes('faceEnrollmentDetectionBox'), 'setup preview needs guide and detector overlay');
assert(page.includes('faceEnrollmentProgressStyle'), 'capture progress must remain visible while capturing');
assert(page.includes("faceEnrollmentState == 'READY' && faceEnrollmentDetectionReady"), 'Capture must appear only after detector readiness');
assert(controller.includes('FaceDetector') && controller.includes('detectEnrollmentFace'), 'browser detection must remain available');
assert(controller.includes('faceEnrollmentCaptured') && controller.includes('faceEnrollmentDetected'), 'controller must track capture and detection');
assert(controller.includes('VERIFY_THRESHOLD_NOT_MET'), 'pending server readiness must remain explicit');
assert(controller.includes('ask an administrator to enable it'), 'technical calibration detail must be translated for staff');
console.log('PASS FACE-ENR-UI-02 guided detection contract');
