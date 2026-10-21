#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
assert((page.match(/Set up face/g) || []).length >= 1, 'focused roster must retain setup action');
assert(page.includes('!row.face_mapping_active'), 'setup action must hide after mapping');
assert(page.includes('Face ready'), 'mapped state must use plain language');
assert(controller.includes('enrollmentMember.face_mapping_active = true;'), 'success must update the current roster row');
console.log('PASS FACE-ENR-UI-06 focused-roster mapping state contract');
