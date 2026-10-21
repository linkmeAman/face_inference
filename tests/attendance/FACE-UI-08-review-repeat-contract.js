#!/usr/bin/env node
/* FACE-UI-08 uncertain-face automatic continuation contract. */

const fs = require('fs');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');

const failures = [];
function check(condition, message) {
    if (!condition) failures.push(message);
}

const attemptStart = controller.indexOf("v1/face-attendance.php', 'recognizeFaceAttempt'");
const attemptEnd = controller.indexOf('\n            }).catch(function(){', attemptStart);
const responseBlock = attemptStart >= 0 && attemptEnd > attemptStart
    ? controller.slice(attemptStart, attemptEnd)
    : '';
const duplicateStart = responseBlock.indexOf('var alreadyFaceScanned');
const duplicateEnd = responseBlock.indexOf('\n                if (alreadyFaceScanned)', duplicateStart);
const duplicateBlock = duplicateStart >= 0 && duplicateEnd > duplicateStart
    ? responseBlock.slice(duplicateStart, duplicateEnd)
    : '';
check(duplicateBlock.includes('body.member_already_selected === true'),
    'repeat suppression must use the server-authoritative selected-member marker');
check(!duplicateBlock.includes("body.decision === 'REVIEW'")
    && !duplicateBlock.includes('faceScannedMemberIds[recognizedMemberId]'),
    'an uncertain result must not locally suppress a later accepted retry');
check(responseBlock.includes('already scanned')
    && responseBlock.includes('Please use the next child'),
    'a server-confirmed repeated selection must advance to the next child');
check(!controller.includes('Teacher review') && !controller.includes('faceReviewRequired'),    'the scan UI must not expose a manual teacher-review queue');check(responseBlock.includes("body.decision === 'REVIEW'") && responseBlock.includes('waitForNextFace()'),    'an uncertain result must remain absent and automatically advance to the next child');
check(responseBlock.includes("body.decision === 'REVIEW'"),
    'uncertain faces must remain internally non-accepted rather than becoming ACCEPT');

if (failures.length) {
    failures.forEach((failure) => console.error('FAIL FACE-UI-08: ' + failure));
    console.error(`${failures.length} FACE-UI-08 assertions failed.`);
    process.exit(1);
}

console.log('PASS FACE-UI-08 uncertain-face automatic continuation contract');
