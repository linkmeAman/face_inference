#!/usr/bin/env node
const assert=require('assert');const fs=require('fs');
const page=fs.readFileSync('pf/attendance.php','utf8');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const machine=fs.readFileSync('pf/js/controllers/faceAttendanceController.js','utf8');
['SELECTING','LOADING','READY','SCANNING','REVIEW','COMMITTING','COMPLETE'].forEach(stage=>assert(controller.includes("'"+stage+"'")||page.includes("'"+stage+"'"),'missing stage '+stage));
assert(!page.includes('Show Attendees'),'redundant Show Attendees action must be removed');
assert(controller.includes('if (!$scope.support_mode) selectMembers();'),'valid time selection must auto-load roster');
assert(controller.includes('rosterLoadGeneration'),'roster loading must ignore stale responses');
assert(page.includes('Finish Without Scanning')&&page.includes('Every unscanned student will be recorded Absent'),'no-scan finish must warn before commit');
assert(page.includes('Finish Scanning')&&controller.includes("faceMachine.reconcile();"),'active scan must have a reachable attendance-summary transition');
assert(page.includes('Attendance summary')&&page.includes('Punch Attendance')&&page.includes("attendanceStage == 'REVIEW'")&&!page.includes('Review attendance'),'final commit must appear in the attendance summary');
assert(page.includes('Attendance saved')&&page.includes('ng-click="finishAttendanceFlow()"'),'completion must be durable and dismissible');
assert(machine.includes("COMPLETED: ['STARTING_CAMERA']"),'completed scan must allow continued scanning');
assert(!page.includes('Use manual attendance instead'),'face flow must not point to disabled manual controls');
console.log('PASS FACE-UI-13 staged workflow contract');