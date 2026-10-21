#!/usr/bin/env node
const assert = require('assert');
const fs = require('fs');
const page=fs.readFileSync('pf/attendance.php','utf8'); const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');
assert(page.includes('Scan Departures'), 'READY stage must expose plain-language departure scanning');
assert(page.includes('ng-if="facePunchOutUiEnabled'), 'departure action must be feature flagged');
assert(page.includes('startFacePunchOut()') && controller.includes("startFaceScan('PUNCH_OUT'"), 'departure scan must retain PUNCH_OUT semantics');
console.log('PASS FACE-OUT-01 departure scan contract');
