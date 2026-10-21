'use strict';

/* FACE-UI-02 punch-in detection overlay, created before the UI change. */
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const route = fs.readFileSync('routes/faceAttendanceRoute.php', 'utf8');
const phpController = fs.readFileSync('controllers/faceAttendance.php', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(page.includes('faceAttendanceFrame'), 'punch-in preview must have a shared frame container');
assert(page.includes('faceAttendanceGuide'), 'punch-in preview must show a face guide');
assert(page.includes('faceAttendanceDetectionBox'), 'punch-in preview must show a model detection box');
assert(page.includes('faceAttendanceDetectionMessage'), 'punch-in preview must show detection instructions');
assert(controller.includes('function syncFaceGuideToFrame'), 'face guide must be sized against the rendered camera frame');
assert(controller.includes('Math.min(content.width * 0.66, availableHeight * 0.75)'), 'guide width must be constrained by the contained video content');
assert(controller.includes('content.y + content.height - guideHeight - labelSpace'), 'guide and its label must remain inside the actual video content');
assert(controller.includes('new window.ResizeObserver'), 'guide must be refitted after responsive camera resizing');
assert(controller.includes('aspectRatio: { ideal: 4 / 3 }'), 'camera should prefer a high-resolution 4:3 frame instead of a narrow portrait guide');
assert(controller.includes('previewFace'), 'punch-in controller must request the attendance preview action');
assert(controller.includes('pollFaceModelPreview'), 'punch-in controller must continuously refresh the detection box');
assert(controller.includes('mapFaceBox'), 'punch-in controller must map intrinsic detector coordinates to rendered video coordinates');
assert(controller.includes('stopFaceDetection'), 'punch-in camera cleanup must stop preview polling');
assert(route.includes("'previewFace'"), 'attendance route must parse a preview frame action');
assert(route.includes("$request['action']"), 'attendance route must accept multipart preview actions when query parameters are unavailable');
assert(phpController.includes("case 'previewFace':"), 'attendance controller must handle the preview action');

console.log('PASS FACE-UI-02 punch-in detection overlay contract');
