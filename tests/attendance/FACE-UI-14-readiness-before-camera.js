'use strict';
const fs = require('fs');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const route = fs.readFileSync('controllers/faceAttendance.php', 'utf8');
const start = controller.slice(controller.indexOf('function startFaceScan('), controller.indexOf('function requestFaceScanStart('));
if (!start.includes("'getFaceReadiness'") || start.indexOf("'getFaceReadiness'") > start.indexOf('navigator.mediaDevices.getUserMedia(getCameraConstraints')) {
    throw new Error('Readiness must be checked before camera permission is requested');
}
if (!start.includes("readiness.state !== 'READY'") || !start.includes('stopFailedFaceScan()')) {
    throw new Error('Failed readiness must return to the manual attendance list');
}
if (!route.includes("case 'getFaceReadiness':") || !route.includes("FaceSelectionGate::enabled($conn)")) {
    throw new Error('Readiness endpoint must enforce the teacher selection release gate');
}
console.log('PASS FACE-UI-14 readiness preflight precedes camera opening');
