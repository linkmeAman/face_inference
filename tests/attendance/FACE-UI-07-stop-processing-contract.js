#!/usr/bin/env node
/* FACE-UI-07 stop-during-processing contract, created before implementation. */

const fs = require('fs');
const { FaceAttendanceStateMachine } = require('../../pf/js/controllers/faceAttendanceController.js');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

let stopCameraCalls = 0;
const machine = new FaceAttendanceStateMachine({
    onStopCamera: () => { stopCameraCalls += 1; },
    onError: () => {},
});

machine.start();
machine.cameraReady();
machine.captureStarted();
machine.framesCaptured();

try {
    machine.cancel();
} catch (error) {
    console.error('FAIL FACE-UI-07: Stop Face Scan cannot cancel an in-flight processing attempt: ' + error.message);
    process.exit(1);
}

if (machine.state !== 'CANCELLED') {
    console.error('FAIL FACE-UI-07: stopping during processing must enter CANCELLED');
    process.exit(1);
}
if (stopCameraCalls !== 1) {
    console.error('FAIL FACE-UI-07: stopping during processing must invoke camera cleanup once');
    process.exit(1);
}
if (!controller.includes('faceStream.getTracks().forEach(function(track){ track.stop(); })')
    || !controller.includes('video.srcObject = null')) {
    console.error('FAIL FACE-UI-07: camera cleanup must stop tracks and detach the video source');
    process.exit(1);
}

console.log('PASS FACE-UI-07 stop-during-processing contract');
