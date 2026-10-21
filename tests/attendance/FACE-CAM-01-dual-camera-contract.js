#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const contains = (source, value, message) => assert(source.includes(value), message);
contains(page, `face-camera-flip-btn" ng-click="switchFaceCamera('attendance')"`, 'attendance camera needs one in-frame switch');
contains(page, `face-camera-flip-btn" ng-click="switchFaceCamera('enrollment')"`, 'setup camera needs one in-frame switch');
assert(!page.includes('face-camera-toggle-btn" ng-click'), 'duplicate heading camera switches must be removed');
contains(controller, 'face_camera_facing_mode', 'camera preference must persist');
contains(controller, 'function getCameraConstraints(mode)', 'dynamic constraints helper is required');
contains(controller, 'facingMode: { ideal: facing }', 'constraints must tolerate single-camera devices');
contains(controller, 'function switchFaceCamera(context)', 'camera switching implementation is required');
contains(controller, 'Only one camera detected on this device.', 'single-camera fallback must be friendly');
console.log('PASS FACE-CAM-01 single-control dual-camera contract');
