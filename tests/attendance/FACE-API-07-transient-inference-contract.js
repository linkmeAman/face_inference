#!/usr/bin/env node
/* FACE-API-07 transient inference recovery contract, created before implementation. */

const fs = require('fs');
const service = fs.readFileSync('classes/services/FaceAttendanceApiService.php', 'utf8');

const failures = [];
function check(condition, message) {
    if (!condition) failures.push(message);
}

const start = service.indexOf('public function startFaceScan(');
const end = service.indexOf('\n\tpublic function recognizeFaceAttempt(', start);
const block = start >= 0 && end > start ? service.slice(start, end) : '';
const initialize = block.indexOf('$this->inference->initializeScan(');
const insert = block.indexOf('INSERT INTO attendance_scan ');
const unavailableCatch = block.indexOf("'reason' => 'INFERENCE_UNAVAILABLE'");
const cancelUpdate = block.indexOf("UPDATE attendance_scan SET status = 'CANCELLED'");

check(initialize >= 0, 'scan start must initialize the private sidecar');
check(insert >= 0, 'scan start must persist an attendance scan after initialization');
check(initialize >= 0 && insert >= 0 && initialize < insert,
    'private sidecar initialization must succeed before a database scan is persisted');
check(unavailableCatch >= 0, 'private inference outage must retain an explicit retryable reason');
check(cancelUpdate < 0 || cancelUpdate > unavailableCatch,
    'private inference outage must not mark the newly requested scan CANCELLED');
check(block.includes('No active face mappings are available in the selected roster'),
    'a roster without candidates must retain a clear manual fallback reason');

if (failures.length) {
    failures.forEach((failure) => console.error('FAIL FACE-API-07: ' + failure));
    console.error(`${failures.length} FACE-API-07 assertions failed.`);
    process.exit(1);
}

console.log('PASS FACE-API-07 transient inference recovery contract');
