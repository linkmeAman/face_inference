#!/usr/bin/env node
const fs=require('fs');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const failures=[];const check=(c,m)=>{if(!c)failures.push(m);};
check(controller.includes('faceAwaitingNextChild'),'scanner must gate after each result');
check(controller.includes('faceAttendanceSingleFacePresent')&&controller.includes('faceAttendanceFaceCount'),'preview must distinguish one, none, and multiple faces');
check(controller.includes('faceNextChildTimer'),'next-child waiting must be cancellable');
const duplicateStart=controller.indexOf('if (alreadyFaceScanned)');const duplicateEnd=controller.indexOf("if (body.decision === 'REVIEW'",duplicateStart);const duplicate=controller.slice(duplicateStart,duplicateEnd);check(duplicate.includes('waitForNextFace()'),'repeated child must use departure gate');
const waitStart=controller.indexOf('function waitForNextFace()');const waitEnd=controller.indexOf('\n    function ',waitStart+1);const wait=controller.slice(waitStart,waitEnd);check(wait.includes('faceAttendanceFaceCount === 0'),'gate must observe an empty frame');check(wait.includes('scheduleFaceAttempt(350)'),'new face must schedule next attempt');
check(wait.includes('if (!facePreviewAvailable)') && !wait.includes('scheduleFaceAttempt(900)'),'an unavailable preview must hold the hand-off gate instead of re-submitting the prior child');
check(controller.includes("form.append('action', 'previewFace')"),'preview action must be carried in multipart form data');
const skipStart=controller.indexOf('function useManualFaceFallback()');const skipEnd=controller.indexOf('\n    function ',skipStart+1);check(controller.slice(skipStart,skipEnd).includes('waitForNextFace()'),'leave-Absent action must also respect departure gate');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-UI-09: '+x));process.exit(1);}console.log('PASS FACE-UI-09 next-child gate contract');