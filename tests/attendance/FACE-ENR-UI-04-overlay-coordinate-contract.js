#!/usr/bin/env node

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

function assertContains(source, fragment, message) {
    assert(source.includes(fragment), message + ' (missing: ' + fragment + ')');
}

// The model preview returns coordinates in the camera's intrinsic frame.
// This fixture is the exact shape reported by the preview endpoint during testing.
const detectedFace = {
    x: 210.36460876464844,
    y: 89.52656555175781,
    width: 179.04086303710938,
    height: 235.54348754882812
};

// A 640x480 camera frame rendered at 320x240 must produce the equivalent
// 105.18x44.76x89.52x117.77 pixel overlay, not percentages against another box.
const rendered = {width: 320, height: 240};
const intrinsic = {width: 640, height: 480};
const expected = {
    left: detectedFace.x * rendered.width / intrinsic.width,
    top: detectedFace.y * rendered.height / intrinsic.height,
    width: detectedFace.width * rendered.width / intrinsic.width,
    height: detectedFace.height * rendered.height / intrinsic.height
};

const mapperStart = controller.indexOf('function mapEnrollmentFaceBox');
const mapperEnd = controller.indexOf('\n    function applyEnrollmentModelDetection', mapperStart);
assert(mapperStart >= 0 && mapperEnd > mapperStart, 'overlay mapper must be extractable for a deterministic fixture test');
const environmentSandbox = {$scope: {cameraFacingMode: 'environment'}};
const mapEnrollmentFaceBox = vm.runInNewContext('(' + controller.slice(mapperStart, mapperEnd).trim() + ')', environmentSandbox);
const actual = mapEnrollmentFaceBox(detectedFace, {
    videoWidth: intrinsic.width,
    videoHeight: intrinsic.height,
    clientWidth: rendered.width,
    clientHeight: rendered.height
});

assert(Math.abs(expected.left - 105.18230438232422) < 0.001, 'fixture left coordinate must be stable');
assert(Math.abs(expected.top - 44.763282775878906) < 0.001, 'fixture top coordinate must be stable');
assert(Math.abs(expected.width - 89.52043151855469) < 0.001, 'fixture width must be stable');
assert(Math.abs(expected.height - 117.77174377441406) < 0.001, 'fixture height must be stable');
assert.strictEqual(actual.left, expected.left + 'px', 'implementation must map the returned left coordinate');
assert.strictEqual(actual.top, expected.top + 'px', 'implementation must map the returned top coordinate');
assert.strictEqual(actual.width, expected.width + 'px', 'implementation must map the returned width');
assert.strictEqual(actual.height, expected.height + 'px', 'implementation must map the returned height');

const mirroredSandbox = {$scope: {cameraFacingMode: 'user'}};
const mapMirroredEnrollmentFaceBox = vm.runInNewContext('(' + controller.slice(mapperStart, mapperEnd).trim() + ')', mirroredSandbox);
const mirrored = mapMirroredEnrollmentFaceBox(detectedFace, {
    videoWidth: intrinsic.width,
    videoHeight: intrinsic.height,
    clientWidth: rendered.width,
    clientHeight: rendered.height
});
const expectedMirroredLeft = (intrinsic.width - detectedFace.x - detectedFace.width) * rendered.width / intrinsic.width;
assert.strictEqual(mirrored.left, expectedMirroredLeft + 'px', 'front-camera overlay must mirror raw detector X');
assert.strictEqual(mirrored.top, expected.top + 'px', 'mirroring must not change detector Y');

assertContains(page, 'id="faceEnrollmentFrame"', 'enrollment overlay must have a frame container');
assertContains(page, 'object-fit:contain', 'video and guide must use the uncropped rendered camera content');
assertContains(controller, 'function mapEnrollmentFaceBox', 'controller must map model coordinates through one tested helper');
assertContains(controller, 'video.clientWidth', 'overlay mapping must use the rendered video width');
assertContains(controller, 'video.clientHeight', 'overlay mapping must use the rendered video height');
assertContains(controller, 'face.normalized_box', 'overlay mapping must consume detector-resolution-independent normalized boxes');
assertContains(controller, 'syncEnrollmentPreviewFrame', 'camera frame aspect ratio must be synchronized before mapping');
assertContains(controller, "object_fit: 'contain'", 'capture context must describe the object-fit mapping');

console.log('PASS overlay-coordinate-contract');
