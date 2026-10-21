const fs = require('fs');
const path = require('path');
const { FaceAttendanceStateMachine } = require('../../pf/js/controllers/faceAttendanceController.js');

function check(condition, message) {
    if (!condition) throw new Error(message);
}

const machine = new FaceAttendanceStateMachine();
machine.start();
machine.cameraReady();
machine.captureStarted();
machine.framesCaptured();
machine.result({
    decision: 'REVIEW',
    liveness: 'CHALLENGE',
    challenge: {type: 'LOOK_LEFT', token: 'a'.repeat(43), expires_in: 15}
});
check(machine.state === 'CHALLENGE', 'borderline PAD must enter the active challenge state');
check(machine.canCapture(), 'active challenge state must permit the bound response capture');

const controller = fs.readFileSync(path.join(__dirname, '../../pf/js/controllers/batchAttendanceController.js'), 'utf8');
check(controller.includes('attemptPayload.challenge_token = faceChallenge.token'), 'challenge response must send the server token');
check(controller.includes("faceChallenge.type === 'LOOK_LEFT'"), 'challenge prompt must distinguish randomized direction');
check(controller.includes('useManualFaceFallback'), 'challenge UI must expose an accessible manual fallback');

console.log('PASS FACE-PAD-01 active challenge UI contract');
