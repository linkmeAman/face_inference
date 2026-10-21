#!/usr/bin/env node
const fs=require('fs');const controller=fs.readFileSync('pf/js/controllers/batchAttendanceController.js','utf8');const failures=[];const check=(c,m)=>{if(!c)failures.push(m);};
check(controller.includes('faceScannedMemberIds'),'scan must track recognized IDs');
check(controller.includes('already scanned'),'repeated child must receive feedback');
check(controller.includes("body.effect === 'SELECT_PRESENT'"),'only supervised selection may update roster');
check(controller.includes('matchedRow.negative = 0')&&controller.includes('matchedRow.selected = true'),'accepted match must select one Present row');
check(controller.includes('waitForNextFace()'),'results must advance without a modal');
check(!controller.includes('showFaceRecognitionPopup('),'recognized results must not block continuous scanning');
const start=controller.indexOf('function selectPresentRosterRow(contactId)');const end=controller.indexOf('\n    function ',start+1);const block=controller.slice(start,end);check(block.includes('row.contact_id')&&!block.includes('row.fullname'),'selection must use contact_id only');
if(failures.length){failures.forEach(x=>console.error('FAIL FACE-UI-06: '+x));process.exit(1);}console.log('PASS FACE-UI-06 non-blocking recognized-child flow contract');
