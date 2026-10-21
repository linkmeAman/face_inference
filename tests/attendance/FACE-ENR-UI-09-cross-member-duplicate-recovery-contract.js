#!/usr/bin/env node
/* FACE-ENR-UI-09 cross-member duplicate recovery contract. */

const assert = require('assert');
const fs = require('fs');

const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const routeController = fs.readFileSync('controllers/faceEnrollment.php', 'utf8');

function assertContains(source, fragment, message) {
    assert(source.includes(fragment), message + ' (missing: ' + fragment + ')');
}

const duplicateStart = controller.indexOf("body.reason === 'FACE_ALREADY_ASSIGNED'");
assert(duplicateStart >= 0,
    'cross-member duplicate enrollment must have an explicit recovery branch');

const duplicateEnd = controller.indexOf("} else {", duplicateStart);
assert(duplicateEnd > duplicateStart,
    'cross-member duplicate recovery branch must remain bounded');
const duplicateBranch = controller.slice(duplicateStart, duplicateEnd);

assertContains(duplicateBranch, "$scope.faceEnrollmentState = 'READY'",
    'duplicate enrollment must return the selected child to a retryable mapping state');
assertContains(duplicateBranch, '$scope.faceEnrollmentFrameCount = 0',
    'duplicate enrollment must reset the temporary frame count');
assertContains(duplicateBranch, '$scope.faceEnrollmentCaptured = 0',
    'duplicate enrollment must reset the temporary capture count');
assertContains(duplicateBranch, 'already assigned to another child',
    'duplicate enrollment must clearly explain the identity collision');
assert(!duplicateBranch.includes('face_mapping_active = true'),
    'duplicate enrollment must not mark the selected child as mapped');

assertContains(routeController, "'possible_duplicate' => $possibleDuplicate",
    'single-frame similarity must be returned as advisory evidence');
assertContains(routeController, "$collision = null",
    'single-frame similarity must not become an authoritative collision');
const advisoryStart = controller.indexOf('if (body.possible_duplicate');
const advisoryEnd = controller.indexOf("if (body.reference_match_state", advisoryStart);
assert(advisoryStart >= 0 && advisoryEnd > advisoryStart,
    'advisory duplicate guidance must be handled before reference validation');
const advisoryBranch = controller.slice(advisoryStart, advisoryEnd);
assert(!advisoryBranch.includes('return;') && !advisoryBranch.includes('faceEnrollmentDetected = false'),
    'single-frame similarity guidance must not disable enrollment capture');

console.log('PASS FACE-ENR-UI-09 cross-member duplicate recovery contract');
