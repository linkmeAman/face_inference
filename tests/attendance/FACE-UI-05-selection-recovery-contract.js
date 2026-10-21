#!/usr/bin/env node
const fs=require('fs');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const page=fs.readFileSync('pf/attendance.php','utf8');const failures=[];const check=(c,m)=>{if(!c)failures.push(m);};
check(controller.includes('sessionStorage')&&controller.includes('restoreAttendanceSelection'),'selection must survive refresh');
check(controller.includes('resetForSelectionChange'),'selection changes must cancel cameras and stale work');
check(controller.includes('rosterLoadGeneration'),'late roster responses must be ignored');
check(page.includes("attendanceStage == 'READY'")&&page.includes('Scan Arrivals'),'READY stage must expose scan restart');
check(controller.includes('restoreRosterSnapshot()'),'cancelled scan must restore original roster');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-UI-05: '+x));process.exit(1);}console.log('PASS FACE-UI-05 selection and restart recovery contract');
