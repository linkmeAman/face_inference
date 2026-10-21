#!/usr/bin/env node
const assert=require('assert');const fs=require('fs');
const page=fs.readFileSync('pf/attendance.php','utf8');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const member=fs.readFileSync('controllers/member.php','utf8');
assert(member.includes("$row['negative'] = isset($attendanceByContact[$contactId]) ? $attendanceByContact[$contactId]['negative'] : '1';"),'server roster must default missing attendance to Absent');
assert(controller.includes("member.selected = String(member.negative) === '0';"),'client must derive Present only from authoritative negative=0');
assert(controller.includes('matchedRow.negative = 0;')&&controller.includes('matchedRow.selected = true;'),'accepted scan must select Present');
assert(controller.includes('if ($scope.faceUiEnabled) return;'),'bulk manual selection must remain blocked in face mode');
assert(page.includes('ng-if="!faceUiEnabled" class="attendance-manual-controls"'),'manual controls must render only outside face mode');
assert(controller.includes('Not scanned — Absent'),'roster must plainly describe the default');
assert(page.includes('{{rosterAbsentCount()}}')&&page.includes('Face not ready'),'compact safety summary must show absent and setup counts');
assert(page.includes('Set up face')&&page.includes('!row.face_mapping_active'),'unmapped students must remain fixable in setup mode');
console.log('PASS FACE-UI-12 no-scan-is-absent staged contract');