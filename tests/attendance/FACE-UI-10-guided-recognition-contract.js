#!/usr/bin/env node
const fs=require('fs');const page=fs.readFileSync('pf/attendance.php','utf8');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const failures=[];const check=(c,m)=>{if(!c)failures.push(m);};
check(page.includes('face-scan-card')&&page.includes('face-current-instruction'),'scan must use grouped card with one dynamic instruction');
check(page.includes('Show one child at a time'),'guidance must require one child');
check(page.includes('wait for the green guide'),'guidance must explain readiness');
check(page.includes('<details class="face-help">'),'long help must be collapsed');
check(page.includes('aria-live="polite"'),'dynamic guidance must be accessible');
check(page.includes('{{faceScanStatusLabel}}'),'machine state must be translated');
check(page.includes('Finish Scanning'),'scan must have a persistent exit to the attendance summary');
check(controller.includes('function updateFaceScanStatus(state)'),'controller must translate scan state');
check(controller.includes("body.decision === 'ACCEPT' && body.effect === 'SELECT_PRESENT'"),'only accepted selection effects may mark Present');
check(controller.includes("else if (body.decision === 'REVIEW')") && controller.includes('It remains Absent; show the next child.') && controller.includes('waitForNextFace()'),'uncertain results must remain absent and automatically continue');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-UI-10: '+x));process.exit(1);}console.log('PASS FACE-UI-10 concise guided recognition contract');
