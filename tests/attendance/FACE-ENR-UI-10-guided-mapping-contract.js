#!/usr/bin/env node
const fs = require('fs');
const page = fs.readFileSync('pf/attendance.php', 'utf8');
const controller = fs.readFileSync('pf/js/controllers/batchAttendanceController.js', 'utf8');
const failures=[]; const check=(c,m)=>{if(!c)failures.push(m);};
check(page.includes("faceSetupMode && faceEnrollmentState != 'IDLE' && attendanceStage == 'READY'"), 'setup camera must only appear in READY setup mode');
check(page.includes("faceSetupMode\">Face setup" ) || page.includes('ng-if="faceSetupMode"'), 'setup actions must be progressively disclosed');
check(page.includes('face-enrollment-card'), 'setup needs a dedicated card');
check(page.includes('Keep only {{faceEnrollmentMemberName}} in view'), 'guidance must identify the selected child');
check(page.includes('Capture appears when the face is positioned correctly'), 'guidance must explain automatic readiness');
check(page.includes('{{faceEnrollmentStatusLabel()}}'), 'setup must show human-readable status');
check(page.includes('ng-class="faceEnrollmentDetectionStatusClass"'), 'detector must show dynamic state');
check(controller.includes('activeFaceScanStates.indexOf'), 'setup must be blocked during scanning');
check(controller.includes("$scope.faceEnrollmentDetectionStatusClass = 'is-ready'"), 'ready face must be visible');
check(controller.includes("$scope.faceEnrollmentDetectionStatusClass = 'is-warning'"), 'bad positioning must be visible');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-ENR-UI-10: '+x));process.exit(1);}console.log('PASS FACE-ENR-UI-10 progressive setup contract');
