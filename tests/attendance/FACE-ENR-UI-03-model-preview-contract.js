#!/usr/bin/env node
/* Actual model preview contract, created before the server-preview change. */

const fs = require('fs');

const route = fs.readFileSync('routes/faceEnrollmentRoute.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const client = fs.readFileSync('classes/clients/FaceInferenceClient.php', 'utf8');
const phpController = fs.readFileSync('controllers/faceEnrollment.php', 'utf8');
const main = fs.readFileSync('services/face-inference/app/main.py', 'utf8');
const matcher = fs.readFileSync('services/face-inference/app/matcher.py', 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(route.includes('previewEnrollment'), 'PHP route must accept enrollment preview frames');
assert(controller.includes('previewEnrollment'), 'browser must request model enrollment preview');
assert(controller.includes('body.faces'), 'browser must use model face coordinates');
assert(client.includes('previewEnrollment'), 'PHP client must expose model preview');
assert(phpController.includes('previewEnrollment'), 'PHP controller must handle model preview');
assert(main.includes('[\"internal\", \"enrollments\", \"preview\"]'), 'inference service must expose private preview');
assert(matcher.includes('def preview'), 'matcher must return model face coordinates');

console.log('PASS FACE-ENR-UI-03 model preview contract');
