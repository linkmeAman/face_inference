const fs = require('fs');
const path = require('path');

function check(condition, message) {
    if (!condition) throw new Error(message);
}

const controller = fs.readFileSync(
    path.join(__dirname, '../../pf/js/controllers/batchAttendanceController.js'),
    'utf8'
);
const page = fs.readFileSync(path.join(__dirname, '../../pf/attendance.php'), 'utf8');
const matcher = fs.readFileSync(
    path.join(__dirname, '../../services/face-inference/app/matcher.py'),
    'utf8'
);

check(controller.includes('grabAttendanceSnapshotDataUrl'), 'attendance must use the high-quality snapshot path');
check(controller.includes('fitFaceCaptureDimensions'), 'portrait and landscape captures must retain their aspect ratio');
check(controller.includes('captureDisplayedVideoFrame(video, canvas, 0.90'), 'attendance snapshots must avoid low-quality JPEG compression');
check(controller.includes('captureDisplayedVideoFrame'), 'preview and submitted frames must use one canvas/video capture path');
check(!controller.includes('new window.ImageCapture(track)'), 'biometric capture must not change field of view through ImageCapture');
check(controller.includes('capture_contexts: captureContexts'), 'attendance must submit per-frame FaceGuide geometry');
check(controller.includes('formatFaceAttendanceGuidance(body)'), 'server rejection details must become actionable teacher guidance');
check(controller.includes("reason === 'NO_FACE_IN_GUIDE'") && controller.includes("reason === 'MULTIPLE_FACES_IN_GUIDE'"), 'final guidance must distinguish missing and competing guide faces');
check(controller.includes("result.stage === 'QUALITY'") && controller.includes("result.stage === 'MATCHING'"), 'final guidance must distinguish quality and identity failures');
check(controller.includes('acquisitionNeedsRetry && !faceRetryUsed'), 'only guide and quality reviews may offer one explicit retry');
check(controller.includes('$scope.faceRetryAvailable = true'), 'a failed check must expose an explicit retry action');
check(page.includes('ng-click="retryFaceAttempt()"') && page.includes('Check Again'), 'the scan card must render the Check Again control');
check(matcher.includes('self.pad.real_probability(image, face)'), 'attendance PAD must use the versioned source-compatible crop');
check(matcher.includes('evidence["pad_score"]'), 'attendance PAD scores must be retained as non-biometric audit evidence');

console.log('PASS FACE-PAD-02 live-user liveness resilience contract');
