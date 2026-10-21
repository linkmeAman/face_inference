#!/usr/bin/env node
/* FACE-UI-01 state contract, created before the camera controller. */

let FaceAttendanceStateMachine;
try {
    ({ FaceAttendanceStateMachine } = require('../../pf/js/controllers/faceAttendanceController.js'));
} catch (error) {
    console.error(`FAIL FACE-UI-01 state contract: ${error.message} (expected pre-change baseline)`);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

const machine = new FaceAttendanceStateMachine({
    onError: () => {},
    onStopCamera: () => {},
});
assert(machine.state === 'IDLE', 'initial state must be IDLE');
machine.start();
assert(machine.state === 'STARTING_CAMERA', 'teacher start must request camera');
machine.cameraReady();
assert(machine.state === 'SCANNING', 'camera ready must enter SCANNING');
machine.captureStarted();
assert(machine.state === 'CAPTURING', 'capture transition');
machine.framesCaptured();
assert(machine.state === 'PROCESSING', 'processing transition');
machine.result({ decision: 'REVIEW' });
assert(machine.state === 'REVIEW', 'uncertain match must remain REVIEW');
machine.manualFallback();
assert(machine.state === 'MANUAL', 'manual fallback must remain available');
machine.resume();
assert(machine.state === 'SCANNING', 'resume must return to SCANNING');
machine.captureStarted();
machine.framesCaptured();
machine.result({ decision: 'ACCEPT' });
assert(machine.state === 'MATCHED', 'accepted result must be MATCHED');
machine.complete();
assert(machine.state === 'COMPLETED', 'completion transition');
console.log('PASS FACE-UI-01 state machine contract');
