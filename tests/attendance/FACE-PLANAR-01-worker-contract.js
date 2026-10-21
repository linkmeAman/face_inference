'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const workerPath = path.resolve(__dirname, '../../pf/js/faceEnrollmentLandmarker.worker.js');
const source = fs.readFileSync(workerPath, 'utf8')
    .split('\nself.onmessage')[0]
    .replace(/^import .*?;\n/, '')
    + '\nglobalThis.clientPlanarZVarianceForTest = clientPlanarZVariance;\n';
const context = {globalThis: {}, self: {}, Number, Math};
vm.runInNewContext(source, context, {filename: 'faceEnrollmentLandmarker.worker.js'});

const landmarks = Array.from({length: 455}, () => ({x: 0, y: 0, z: 0}));
landmarks[1] = {x: 0.5, y: 0.5, z: 0.30};
landmarks[234] = {x: 0.2, y: 0.4, z: 0.10};
landmarks[454] = {x: 0.8, y: 0.6, z: 0.10};

const metric = context.globalThis.clientPlanarZVarianceForTest(landmarks, 0.75);
const expectedWidth = Math.sqrt((0.8 - 0.2) ** 2 + ((0.6 - 0.4) * 0.75) ** 2);
assert.ok(Math.abs(metric - (0.20 / expectedWidth)) < 1e-12);
assert.equal(context.globalThis.clientPlanarZVarianceForTest(landmarks, 0), null);
assert.ok(metric >= 0.10);

process.stdout.write(JSON.stringify({case: 'aspect_corrected_depth_to_width_ratio', status: 'PASS'}) + '\n');
