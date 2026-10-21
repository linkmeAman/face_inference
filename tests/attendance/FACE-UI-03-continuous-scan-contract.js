#!/usr/bin/env node
const fs=require('fs'); const page=fs.readFileSync('pf/attendance.php','utf8'); const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8'); const Machine=require('../../pf/js/controllers/faceAttendanceController.js').FaceAttendanceStateMachine;
const failures=[];const check=(c,m)=>{if(!c)failures.push(m);}; const machine=new Machine({onError:()=>{},onStopCamera:()=>{}});machine.start();machine.cameraReady();machine.cancel();machine.start();check(machine.state==='STARTING_CAMERA','cancelled scan must restart');
machine.cameraReady(); machine.error('TEST'); machine.restart(); check(machine.state === 'STARTING_CAMERA', 'error state must restart through a clean camera lifecycle');
check(page.includes('Finish Scanning') && page.includes('Continue Scanning'),'scan must have explicit finish and review restart actions');
check(!page.includes('faceRecognitionPopup'),'continuous scanning must not use blocking result popup');
check(controller.includes('stopFailedFaceScan()'), 'a startup outage must close scanning and offer the attendance list');
check(controller.includes('scheduleFaceAttempt') && controller.includes('waitForNextFace'),'continuous scanning and next-child gate must remain');
check(controller.includes('faceMachine.restart()'),'continue scanning must restart from every prior scanner state');
check(controller.includes('faceCameraGeneration'),'late camera callbacks must not attach to a newer scan');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-UI-03: '+x));process.exit(1);}console.log('PASS FACE-UI-03 non-blocking continuous scan contract');
