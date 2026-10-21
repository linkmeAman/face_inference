import {test} from 'node:test';
import assert from 'node:assert/strict';
import {EmbeddedFaceFlow} from '../../pf/js/browser-face/embedded.mjs';
import {installAttendanceFace} from '../../pf/js/browser-face/attendance.mjs';
globalThis.document={hidden:false,addEventListener(){},removeEventListener(){}};
globalThis.window={addEventListener(){},removeEventListener(){}};
const make=()=>new EmbeddedFaceFlow({}, {},{csrf:'test-only'});
test('duplicate attempt preserves the selected row original pending or active state',async()=>{
 for(const state of ['PENDING','ACTIVE']){
  const current={session_id:2},row={contact_id:792,attendance_id:0,browser_face_enrolled:state==='ACTIVE',face_mapping_active:state==='ACTIVE'};
  const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
  await flow.apply({state:'REJECTED_DUPLICATE',enrollment_state:state,contact_id:792,message:'This face belongs to another member.'},{mode:'enroll',attendance_context:JSON.stringify(current)});
  assert.equal(row.browser_face_pending,state==='PENDING');assert.equal(row.browser_face_enrolled,state==='ACTIVE');assert.equal(row.face_mapping_active,state==='ACTIVE');assert.equal(row.browser_face_state,state);assert.equal(row.attendance_id,0);assert.equal(flow.state.status,'Face already registered');flow.dispose();
 }
});
test('pilot diagnostics retain only bounded scalars and discard biometrics and tokens',()=>{
 const flow=make();flow.config={isolated_pilot:true};
 flow.diagnose({face_count:1,inference_ms:92,quality_reason:'BLUR',embedding:[1,0],landmarks:[[1,2]],scan_token:'secret',template:[1,0],brightness:Infinity});
 assert.deepEqual(flow.state.diagnostics,{face_count:1,inference_ms:92,quality_reason:'BLUR'});
 flow.config={isolated_pilot:false};flow.diagnose({face_count:2});assert.equal(flow.state.diagnostics.face_count,1);flow.dispose();
});
test('pilot backend result exposes persistence context while scanner continues',async()=>{
 const flow=make();flow.config={isolated_pilot:true};flow.context={mode:'attendance'};
 await flow.apply({state:'RECORDED',attendance_id:8,person_id:623,diagnostics:{decision:'MATCHED',observation_count:5,session_id:3,request_key:'pilot-safe-attempt',encrypted_embedding:'secret'}},flow.context,{observations:[[1,0]]});
 assert.equal(flow.state.diagnostics.attendance_id,8);assert.equal(flow.state.diagnostics.session_id,3);assert.equal(flow.state.diagnostics.decision,'MATCHED');assert.equal(flow.state.diagnostics.encrypted_embedding,undefined);assert.equal(flow.state.status,'Attendance marked.');flow.dispose();
});
test('similar-looking changed observations are still identified by the backend',async()=>{
 const flow=make();flow.context={mode:'attendance'};flow.config={attendance_threshold:.9};flow.scan={scan_id:1,scan_token:'test'};
 const original={template:[1,0],observations:Array.from({length:5},()=>[1,0])};
 let calls=0;flow.api.post=async()=>{calls++;return {state:'UNKNOWN'};};
 await flow.apply({state:'RECORDED',attendance_id:1},flow.context,original);
 await flow.batch({template:[.94,Math.sqrt(1-.94**2)],observations:Array.from({length:5},()=>[.94,Math.sqrt(1-.94**2)])},0);
 assert.equal(calls,1);assert.equal(flow.state.status,'Face not recognized');flow.dispose();
});
test('unchanged confirmed observation batches avoid redundant browser submissions',async()=>{
 const flow=make();flow.context={mode:'attendance'};flow.config={attendance_threshold:.9};flow.scan={scan_id:1,scan_token:'test'};
 const batch={template:[1,0],observations:Array.from({length:5},()=>[1,0])};let calls=0;flow.api.post=async()=>{calls++;return {state:'RECORDED'};};
 await flow.apply({state:'RECORDED'},flow.context,batch);await flow.batch(batch,0);
 assert.equal(calls,0);assert.equal(flow.state.status,'Already added for review');flow.dispose();
});
test('retry after a session change resolves the old request without resuming its scanner',async()=>{
 const flow=make();flow.context={mode:'attendance',attendance_context:'old-session'};flow.pending={action:'open',context:flow.context,payload:{idempotency_key:'old-open'}};
 let started=false,closed=false;flow.api.post=async()=>({state:'OPEN',scan_id:1,scan_token:'old-token'});flow.startCapture=async()=>{started=true;};flow.endScan=async()=>{closed=true;};
 await flow.retry({resume:false});assert.equal(started,false);assert.equal(closed,true);assert.equal(flow.pending,null);assert.equal(flow.state.open,false);flow.dispose();
});
test('unfinished matching setup is reported before camera capture or submission',async()=>{
 for(const mode of ['enroll','attendance']){
  const flow=make();flow.api.get=async()=>({acceptance_ready:false,availability_message:'Face scanning is not ready yet. Please use manual attendance.'});
  let submissions=0;flow.api.post=async()=>{submissions++;};await flow.open({mode,person_id:1});
  assert.equal(flow.capture,undefined);assert.equal(submissions,0);assert.equal(flow.state.error,'Face scanning is not ready yet. Please use manual attendance.');assert.equal(flow.state.busy,false);assert.equal(flow.state.retry,false);flow.dispose();
 }
});
test('matching configuration is refreshed instead of keeping stale disabled readiness',async()=>{
 const flow=make();let reads=0;flow.api.get=async()=>({acceptance_ready:++reads>1});
 assert.equal((await flow.initialize()).acceptance_ready,false);assert.equal((await flow.initialize()).acceptance_ready,true);assert.equal(reads,2);flow.dispose();
});
test('late camera result cannot submit against a different member/session',async()=>{
 const flow=make();flow.generation=4;let submissions=0;flow.submit=async()=>{submissions++;};
 await flow.batch({template:[1,0]},3);assert.equal(submissions,0);flow.dispose();
});
test('uncertain retry keeps exact payload and original immutable context',async()=>{
 const flow=make(),payload={idempotency_key:'same-key'},context={mode:'enroll',person_id:1};let calls=[];
 flow.context=context;flow.api.post=async(action,body)=>{calls.push(body);if(calls.length===1)throw new TypeError('Network disconnected');return {state:'ENROLLED',person_id:1};};
 await assert.rejects(()=>flow.submit('enroll',payload,0));assert.equal(flow.pending.payload,payload);await flow.retry();
 assert.equal(calls[0],calls[1]);assert.equal(flow.pending,null);flow.dispose();
});
test('older submitted completion cannot erase a newer pending request',async()=>{
 const flow=make();let finish;flow.api.post=()=>new Promise(resolve=>{finish=resolve;});const operation=flow.submit('enroll',{idempotency_key:'old'},0);
 const later={action:'attendance'};flow.pending=later;finish({state:'ENROLLED'});await operation;assert.equal(flow.pending,later);flow.pending=null;flow.dispose();
});
test('Angular row patch respects session and deducts only on first saved attendance',async()=>{
 let current={session_id:7};const row={contact_id:101,attendance_id:0,sessions_used:0};const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}}),context={attendance_context:JSON.stringify(current)};
 const result={state:'RECORDED',contact_id:101,attendance_id:9,deducted_sessions:1};await flow.apply(result,context,{template:[1,0]});
 assert.equal(row.attendance_id,'9');assert.equal(row.sessions_used,1);await flow.apply(result,context,{template:[1,0]});assert.equal(row.sessions_used,1);
 current={session_id:8};await flow.apply({...result,attendance_id:10},context,{template:[1,0]});assert.equal(row.attendance_id,'9');flow.dispose();
});
test('pending enrollment updates row without claiming an active enrollment or attendance',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0,face_mapping_active:false};const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
 await flow.apply({state:'ENROLLMENT_PENDING',contact_id:101,message:'Face sample saved'},{mode:'enroll',attendance_context:JSON.stringify(current)});
 assert.equal(row.browser_face_pending,true);assert.equal(row.face_mapping_active,false);assert.equal(row.attendance_id,0);assert.notEqual(row.browser_face_enrolled,true);flow.dispose();
});
test('recovered attendance after camera closure opens a fresh scan for the same context',async()=>{
 const flow=make(),context={mode:'attendance',batch_id:11,date:'2026-10-09',session_id:7,time:'10:00:00',attendance_context:'same-session'};
 flow.context=context;flow.pending={action:'attendance',payload:{idempotency_key:'committed-request'},context};flow.scan=null;flow.state.open=false;
 let opened;flow.api.post=async()=>({state:'RECORDED',attendance_id:9});flow.open=async next=>{opened=next;};
 await flow.retry();assert.equal(opened,context,'recovery must resume with a fresh authorized scanner after its old lease closed');flow.dispose();
});
test('resolving old-session attendance leaves the panel closed instead of a stuck camera',async()=>{
 const flow=make(),context={mode:'attendance',attendance_context:'old-session'};flow.context=context;
 flow.pending={action:'attendance',payload:{idempotency_key:'old-arrival'},context};flow.scan=null;
 flow.api.post=async()=>({state:'ALREADY_MARKED',attendance_id:9});let started=false;flow.startCapture=async()=>{started=true;};
 await flow.retry({resume:false});assert.equal(started,false);assert.equal(flow.state.open,false);assert.equal(flow.pending,null);flow.dispose();
});
test('late retry error cannot update a closed or newer camera generation',async()=>{
 const flow=make(),context={mode:'enroll',person_id:1};flow.context=context;flow.pending={action:'enroll',payload:{idempotency_key:'old-enroll'},context};
 let reject;flow.api.post=()=>new Promise((_,no)=>{reject=no;});const operation=flow.retry();await flow.close();
 flow.update({status:'New context',error:'',retry:false});reject(Object.assign(Error('Old request error'),{confirmed:true}));await operation;
 assert.equal(flow.state.error,'');assert.equal(flow.state.status,'New context');flow.dispose();
});
test('camera switching is blocked for pending requests and processing, preserving session context',async()=>{
 const flow=make();flow.context=Object.freeze({mode:'attendance',person_id:623,session_id:2});const context=flow.context;
 let calls=0;flow.capture={active:true,processing:false,switchCamera:async()=>{calls++;return true;},stop(){}};
 flow.pending={action:'attendance'};await flow.switchCamera();assert.equal(calls,0);flow.pending=null;flow.capture.processing=true;await flow.switchCamera();assert.equal(calls,0);
 flow.capture.processing=false;flow.scan={scan_id:5};flow.lastMarked=[[1,0]];await flow.switchCamera();assert.equal(calls,1);assert.equal(flow.context,context);assert.equal(flow.scan.scan_id,5);assert.equal(flow.lastMarked,null);assert.equal(flow.state.camera_switching,false);flow.scan=null;flow.dispose();
});
test('a camera switch completing after closure cannot reopen or update a newer session',async()=>{
 const flow=make();let complete;flow.capture={active:true,processing:false,switchCamera:()=>new Promise(resolve=>{complete=resolve;}),stop(){this.active=false;}};
 const operation=flow.switchCamera();await flow.close();flow.update({status:'New session',busy:false,camera_switching:false});complete(false);await operation;
 assert.equal(flow.state.busy,false);assert.equal(flow.state.status,'New session');assert.equal(flow.state.open,false);flow.dispose();
});

test('operator messages distinguish pending capture, active enrollment and duplicates',async()=>{
 const flow=make(),context={mode:'enroll'};
 await flow.apply({state:'PENDING',message:'Internal threshold setup pending',enrollment_state:'PENDING'},context);
 assert.equal(flow.state.status,'Face saved · Setup pending');
 await flow.apply({state:'ACTIVE',enrollment_state:'ACTIVE',message:'Internal model version'},context);assert.equal(flow.state.status,'Face enrolled');
 await flow.apply({state:'REJECTED_DUPLICATE',message:'score=0.99; request=private'},context);assert.equal(flow.state.status,'Face already registered');assert.equal(flow.state.error,'');flow.dispose();
});
test('operator errors hide matching internals and keep camera/network recovery clear',()=>{
 const flow=make();flow.update({error:'Live calibration threshold/model configuration not ready'});assert.equal(flow.state.error,'Face scanning is not ready yet. Please use manual attendance.');
 flow.update({error:'Connection interrupted. request=private'});assert.equal(flow.state.error,'Connection lost');
 flow.update({error:'PRIVATE_BACKEND_STACK'});assert.equal(flow.state.error,'Please try again');flow.dispose();
});
test('member/session titles remain bound to the open camera context',async()=>{
 const current={batch_id:33,date:'2026-10-10',session_id:2,time:'09:00:00'},row={contact_id:791,fullname:'Test One'};
 const scope={batch:{date:'10-10-2026',batches:[{id:33,batch:'Testing'}]},$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>({...current}),video:{},canvas:{}});flow.api.get=async()=>({persons:[{id:623,contact_id:791}]});flow.open=async context=>{flow.context=context;flow.state.open=true;};
 await scope.openAutomaticFace('enroll',row);assert.equal(scope.automaticFaceTitle,'Enroll Face');assert.equal(scope.automaticFaceSubject,'Test One');assert.equal(scope.automaticFaceContext,'');assert.equal(scope.automaticFaceInstruction,'Position face inside the circle');
 await scope.openAutomaticFace('enroll',{contact_id:792,fullname:'Other child'});assert.equal(scope.automaticFaceSubject,'Test One');assert.equal(flow.context.person_id,623);
 flow.state.open=false;await scope.openAutomaticFace('attendance');assert.equal(scope.automaticFaceTitle,'Scan Arrivals');assert.equal(scope.automaticFaceContext,'Testing · 10-10-2026 · 09:00:00');assert.equal(scope.automaticFaceSubject,'');assert.equal(scope.automaticFaceInstruction,'Position one face inside the circle');
 // Today's session shows the readable class time without repeating the date.
 flow.state.open=false;scope.batch.date=new Date().toLocaleDateString('en-GB').replace(/\//g,'-');scope.batch.times=[{start_time_string:'09:00 AM',end_time_string:'10:00 AM'}];scope.timeIndex='0';
 await scope.openAutomaticFace('attendance');assert.equal(scope.automaticFaceContext,'Testing · 09:00 AM – 10:00 AM');flow.dispose();
});

test('brief attendance feedback expires without changing a newer scanner context',async()=>{
 const timeout=globalThis.setTimeout,clear=globalThis.clearTimeout,callbacks=[];
 globalThis.setTimeout=callback=>{callbacks.push(callback);return callbacks.length;};globalThis.clearTimeout=()=>{};
 const flow=make();flow.state.open=true;flow.capture={waitForDeparture:true,stop(){}};flow.context={mode:'attendance'};
 try{
  await flow.apply({state:'RECORDED',person_name:'Known child'},flow.context,{observations:[[1,0]]});assert.equal(flow.state.status,'Attendance marked for Known child.');assert.equal(flow.state.recognized_name,'Known child');
  callbacks[0]();assert.equal(flow.state.status,'Ready to scan');assert.equal(flow.state.recognized_name,'');
  await flow.apply({state:'ALREADY_MARKED'},flow.context,{observations:[[1,0]]});flow.generation++;flow.update({status:'New scanner context'});callbacks[1]();assert.equal(flow.state.status,'New scanner context');
 }finally{flow.dispose();globalThis.setTimeout=timeout;globalThis.clearTimeout=clear;}
});

test('rejected enrollment clears progress; closing and another member clears stale rejection',async()=>{
 const flow=make();flow.state.open=true;flow.state.progress=1;flow.state.busy=true;
 await flow.apply({state:'REJECTED_DUPLICATE',enrollment_state:'PENDING',matching_person:{name:'Private match'}},{mode:'enroll'});
 assert.equal(flow.state.status,'Face already registered');assert.equal(flow.state.progress,0);assert.equal(flow.state.busy,false);assert.equal(flow.state.retry,true);assert.equal(flow.state.recognized_name,'');
 await flow.close();assert.equal(flow.state.error,'');assert.equal(flow.state.progress,0);
 flow.initialize=async()=>{flow.config={acceptance_ready:true};};flow.startCapture=async()=>{};
 await flow.open({mode:'enroll',person_id:2});assert.equal(flow.state.status,'Starting camera');assert.equal(flow.state.error,'');assert.equal(flow.state.retry,false);flow.dispose();
});
test('background and bfcache preserve scanner context and submitted idempotent request',()=>{
 const flow=make();flow.state.open=true;flow.context=Object.freeze({mode:'attendance',session_id:2});flow.pending={payload:{idempotency_key:'unchanged'}};const visibility=[];flow.capture={setVisibility:hidden=>visibility.push(hidden),stop(){}};
 document.hidden=true;flow.visibility();assert.equal(flow.state.open,true);assert.equal(flow.context.session_id,2);assert.equal(flow.pending.payload.idempotency_key,'unchanged');
 flow.pagehide({persisted:true});document.hidden=false;flow.pageshow({persisted:true});assert.deepEqual(visibility,[true,true,false]);flow.pending=null;flow.dispose();
});
test('actual backend session errors map to reopen; raw exceptions never appear in status',()=>{
 const flow=make();for(const message of ['Attendance camera session has ended','Attendance session is not authorized','AUTH_REQUIRED','CSRF_REQUIRED']){flow.update({error:message});assert.equal(flow.state.status,'Session changed or expired. Please reopen the scanner.');}
 flow.update({error:{code:'ATTENDANCE_ACCESS',message:'private SQL'}});assert.equal(flow.state.status,'Session changed or expired. Please reopen the scanner.');flow.dispose();
});

test('unknown network outcome parks the capture and retry reconciles exact request without replacing healthy camera',async()=>{
 const flow=make();flow.context={mode:'attendance'};flow.config={};flow.scan={scan_id:1,scan_token:'test'};flow.state.open=true;
 let payload,resumed=0,starts=0;flow.capture={active:true,resumeAfterRequest(){resumed++;return true;},stop(){}};flow.startCapture=async()=>{starts++;};
 const batch={template:[1,0],observations:Array(5).fill([1,0])};
 flow.api.post=async(action,input)=>{payload=input;throw new TypeError('Network failed');};
 const result=await flow.batch(batch,0);assert.deepEqual(result,{pending:true});assert.equal(flow.capture.active,true);assert.equal(flow.state.retry,true);assert.equal(flow.state.status,'Connection lost');
 const key=flow.pending.payload.idempotency_key;flow.api.post=async(action,input)=>{assert.equal(input,payload);assert.equal(input.idempotency_key,key);return {state:'ALREADY_MARKED'};};
 await flow.retry();assert.equal(flow.pending,null);assert.equal(resumed,1);assert.equal(starts,0);flow.dispose();
});

test('shadow config starts a scoped scanner despite unavailable production thresholds',async()=>{
 const flow=make();let configParams,started=false;
 flow.api.get=async(action,params)=>{configParams=params;return {operating_mode:'SHADOW',scanning_available:true,acceptance_ready:false};};
 flow.submit=async()=>({state:'OPEN',operating_mode:'SHADOW',scan_id:1,scan_token:'private'});flow.startCapture=async()=>{started=true;};
 await flow.open({mode:'attendance',batch_id:11,session_id:7,time:'10:00:00'});assert.deepEqual(configParams,{batch_id:11});assert.equal(started,true);assert.equal(flow.state.operating_mode,'SHADOW');flow.dispose();
});
test('shadow checks park the track without updating attendance or inventing a marked identity',async()=>{
 const current={batch_id:11},row={contact_id:101,attendance_id:0,sessions_used:0},scope={list:[row],$evalAsync:f=>f(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
 const context={mode:'attendance',attendance_context:JSON.stringify(current)};
 const next=await flow.apply({state:'SHADOW_EVALUATED',diagnostics:{matched_member_id:1}},context,{observations:[[1,0]]});assert.equal(next.waitForDeparture,true);assert.equal(row.attendance_id,0);assert.equal(row.sessions_used,0);assert.equal(flow.lastMarked,null);assert.equal(flow.state.recognized_name,'');assert.equal(flow.state.status,'Face check complete. No attendance was marked.');flow.dispose();
});

test('recognition stages one draft with no row update; finish/back preserves scope and releases camera',async()=>{
 const current={batch_id:11,session_id:7},row={contact_id:101,attendance_id:0,sessions_used:0},scope={list:[row],$evalAsync:f=>f(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>current,video:{},canvas:{}});flow.context={mode:'attendance',...current,attendance_context:JSON.stringify(current)};flow.state.open=true;let stopped=0;flow.capture={stop(){stopped++;}};flow.scan={scan_id:2,scan_token:'test'};
 const response={state:'REVIEW_CANDIDATE',suggestion:{person_id:1,contact_id:101,person_name:'Child A',receipt_key:'recognition_receipt',recognition_result:'RECOGNIZED',requires_independent_verification:false}};
 await flow.apply(response,flow.context,{observations:[[1,0]]});await flow.apply(response,flow.context,{observations:[[1,0]]});
 assert.equal(flow.state.draft.length,1);assert.equal(row.attendance_id,0);assert.equal(row.sessions_used,0);assert.equal(flow.state.status,'Child A added for review.');assert.equal(flow.state.draft[0].verified,false);assert.equal(flow.state.draft[0].observations,undefined);
 await scope.finishAutomaticFace();assert.equal(flow.state.review,true);assert.equal(stopped,1);assert.equal(flow.state.draft.length,1);assert.equal(row.attendance_id,0);
 let started=0;flow.startCapture=async()=>{started++;};await flow.backToScanning();assert.equal(started,1);assert.equal(flow.state.review,false);assert.equal(flow.state.draft.length,1);assert.equal(flow.lastMarked,null);flow.dispose();
});
test('unvalidated shadow suggestion starts unchecked and confirmation alone updates actual saved rows',async()=>{
 const current={batch_id:11,session_id:7},row={contact_id:101,attendance_id:0,sessions_used:0},scope={list:[row],$evalAsync:f=>f(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>current,video:{},canvas:{}});flow.context={mode:'attendance',...current,attendance_context:JSON.stringify(current)};flow.scan={scan_id:1,scan_token:'secret'};
 await flow.apply({state:'SHADOW_EVALUATED',suggestion:{person_id:1,contact_id:101,person_name:'Child A',receipt_key:'receipt',recognition_result:'UNVERIFIED_SUGGESTION',requires_independent_verification:true}},flow.context,{observations:[[1,0]]});
 assert.equal(flow.state.draft[0].verified,false);await flow.finish();let calls=0;flow.api.post=async(action,payload)=>{calls++;assert.equal(action,'confirm');assert.equal(payload.verified,true);assert.deepEqual(payload.candidates,[{person_id:1,receipt_key:'receipt'}]);return {state:'CONFIRMED',results:[{state:'RECORDED',person_id:1,contact_id:101,attendance_id:10,deducted_sessions:1}]};};
 await flow.confirm();assert.equal(calls,0);assert.equal(row.attendance_id,0);flow.state.draft[0].verified=true;await flow.confirm();assert.equal(calls,1);assert.equal(row.attendance_id,'10');assert.equal(row.sessions_used,1);assert.equal(flow.state.status,'Attendance confirmed.');assert.equal(flow.state.draft.length,0);flow.dispose();
});
test('lost confirmation response retries the identical reviewed list without starting camera',async()=>{
 const flow=make();flow.context={mode:'attendance'};flow.scan={scan_id:1,scan_token:'secret'};flow.state.review=true;flow.state.draft=[{person_id:1,receipt_key:'receipt',verified:true}];let first,starts=0;
 flow.api.post=async(action,payload)=>{assert.equal(action,'confirm');first=payload;throw new TypeError('Network lost');};flow.startCapture=async()=>{starts++;};await flow.confirm();assert.equal(flow.pending.action,'confirm');assert.equal(flow.state.draft.length,1);
 flow.api.post=async(action,payload)=>{assert.equal(payload,first);return {state:'CONFIRMED',results:[{state:'RECORDED',person_id:1,attendance_id:4}]};};await flow.retry();assert.equal(starts,0);assert.equal(flow.pending,null);assert.equal(flow.state.status,'Attendance confirmed.');assert.equal(flow.state.draft.length,0);flow.dispose();
});
test('partial confirmation keeps failed and unchecked members and updates only persisted outcomes',async()=>{
 const flow=make();flow.state.draft=[{person_id:1,verified:true},{person_id:2,verified:true},{person_id:3,verified:false}];const saved=[];flow.result=r=>saved.push(r);
 await flow.applyConfirmation({state:'PARTIAL_CONFIRMATION',results:[{state:'RECORDED',person_id:1},{state:'REJECTED',person_id:2}]},{mode:'attendance'});assert.deepEqual(flow.state.draft.map(r=>r.person_id),[2,3]);assert.equal(saved.length,1);assert.equal(flow.state.confirmed,false);await flow.applyConfirmation({state:'CONFIRMED',results:[{state:'RECORDED',person_id:2}]},{mode:'attendance'});assert.deepEqual(flow.state.saved_results.map(r=>r.person_id),[1,2]);flow.dispose();
});
test('unsaved drafts warn before leaving; changed context cannot confirm or carry candidates',()=>{
 let watch;const scope={$evalAsync:f=>f(),$watch:(expression,fn)=>watch=fn,$on(){}};let context={batch_id:11};const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>context,video:{},canvas:{}});
 flow.context={mode:'attendance',attendance_context:JSON.stringify(context)};flow.state.draft=[{person_id:1}];let prevented=false;flow.beforeunload({preventDefault(){prevented=true;}});assert.equal(prevented,true);
 let warning='';window.confirm=message=>{warning=message;return true;};context={batch_id:12};watch(JSON.stringify(context),'old');assert.match(warning,/discard unsaved/i);assert.equal(flow.state.draft.length,0);flow.dispose();delete window.confirm;
});
test('LIVE persisted recognition updates row and stays scanning without a review draft',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0,sessions_used:0};
 const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
 flow.context={mode:'attendance',attendance_context:JSON.stringify(current)};flow.state.operating_mode='LIVE';flow.state.open=true;let stopped=0;flow.capture={waitForDeparture:true,stop(){stopped++;}};
 const outcome=await flow.apply({state:'RECORDED',person_id:1,contact_id:101,person_name:'Child A',attendance_id:41,deducted_sessions:1},flow.context,{observations:[[1,0]]});
 assert.equal(row.attendance_id,'41');assert.equal(row.sessions_used,1);assert.equal(flow.state.draft.length,0);assert.equal(flow.state.review,false);assert.equal(outcome.waitForDeparture,true);assert.equal(stopped,0);
 await flow.finish();assert.equal(flow.state.open,false);assert.equal(flow.state.review,false);assert.equal(stopped,1);flow.dispose();
});
test('closing LIVE never finalizes absences and finalized negative state is preserved in row',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0,sessions_used:0};
 const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
 flow.context={mode:'attendance',attendance_context:JSON.stringify(current)};flow.state.operating_mode='LIVE';flow.state.open=true;flow.scan={scan_id:1,scan_token:'private',finalization_enabled:true};
 flow.api.post=async action=>{assert.equal(action,'close');return {state:'CLOSED'};};
 await flow.applyFinalization({state:'FINALIZED',results:[{state:'RECORDED',negative:1,contact_id:101,attendance_id:42,deducted_sessions:1}]});
 assert.equal(row.negative,1);assert.equal(row.sessions_used,1);assert.equal(flow.state.status,'Session attendance finalized.');await flow.close();assert.equal(flow.cutoffTimer,undefined);flow.dispose();
});
test('TEST recognition is continuous and never stages review or updates attendance rows',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0,sessions_used:0};const scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});flow.state.operating_mode='TEST';flow.state.open=true;
 flow.capture={waitForDeparture:true,stop(){}};
 const out=await flow.apply({state:'TEST_RECOGNIZED',person_id:1,contact_id:101,person_name:'Child A',attendance_saved:false},{mode:'attendance',attendance_context:JSON.stringify(current)},{observations:[[1],[1],[1]]});
 assert.deepEqual(out,{waitForDeparture:true});assert.equal(flow.state.status,'Child A recognized');assert.equal(flow.state.draft.length,0);assert.equal(row.attendance_id,0);assert.equal(row.sessions_used,0);assert.equal(flow.state.review,false);flow.dispose();
});
test('TEST scanner opens with production readiness false, without a confirmation prerequisite',async()=>{
 const flow=make();let started=false;flow.api.get=async()=>({operating_mode:'TEST',scanning_available:true,acceptance_ready:false,test_evaluation_ready:true});
 flow.submit=async()=>({state:'OPEN',scan_id:1,scan_token:'test'});flow.startCapture=async()=>{started=true;};
 await flow.open({mode:'attendance',batch_id:11,session_id:7,time:'10:00:00'});assert.equal(started,true);assert.equal(flow.state.operating_mode,'TEST');assert.equal(flow.state.review,false);flow.dispose();
});

test('rejected capture stops camera and clears guide/ring; retry creates a distinct member-bound session',async()=>{
 const flow=make();flow.state.open=true;flow.state.guide={x:1,y:1,radius:1};flow.state.progress=1;flow.context=Object.freeze({mode:'enroll',person_id:616,capture_session:'old'});let stops=0;
 flow.capture={stop(){stops++;}};flow.lastBatch={observations:[[1]]};
 await flow.apply({state:'REJECTED_DUPLICATE',capture_decision:'AMBIGUOUS_DUPLICATE',previous_reference_preserved:true},flow.context);
 assert.equal(stops,1);assert.equal(flow.state.result_ready,true);assert.equal(flow.state.guide,null);assert.equal(flow.state.progress,0);assert.equal(flow.lastBatch,null);assert.equal(flow.state.retry,true);assert.equal(flow.state.previous_reference_preserved,true);
 flow.api.get=async()=>({acceptance_ready:true});flow.startCapture=async()=>{};await flow.retry();assert.equal(flow.context.person_id,616);assert.notEqual(flow.context.capture_session,'old');assert.equal(flow.state.result_ready,false);assert.equal(flow.state.progress,0);assert.equal(flow.state.error,'');assert.equal(flow.state.previous_reference_preserved,false);flow.dispose();
});
test('rapid close/open across five members ignores all old batch and config completions',async()=>{
 const flow=make();flow.api.get=async()=>({acceptance_ready:true});const attempts=[];flow.startCapture=async()=>{attempts.push({generation:flow.generation,context:flow.context});};
 let posts=0;flow.submit=async()=>{posts++;};
 for(let member=616;member<=620;member++){await flow.open({mode:'enroll',person_id:member});assert.equal(Object.isFrozen(flow.context),true);assert.equal(flow.state.progress,0);assert.equal(flow.state.error,'');await flow.close();}
 assert.equal(new Set(attempts.map(a=>a.context.capture_session)).size,5);await flow.open({mode:'enroll',person_id:620});
 for(const attempt of attempts.slice(0,5))await flow.batch({template:[1]},attempt.generation);assert.equal(posts,0);assert.equal(flow.context.person_id,620);
 let complete;flow.api.get=()=>new Promise(resolve=>complete=resolve);const old=flow.initialize();await flow.close();flow.config={marker:'new'};complete({marker:'stale'});await old;assert.equal(flow.config.marker,'new');flow.dispose();
});
test('conflicted stored reference stays PENDING and latest rejected attempt remains distinct',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0},scope={list:[row],$evalAsync:f=>f(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>current,video:{},canvas:{}});
 await flow.apply({state:'REJECTED_DUPLICATE',enrollment_state:'PENDING',test_gallery_conflict:true,test_ready:false,contact_id:101},{mode:'enroll',attendance_context:JSON.stringify(current)});
 assert.equal(row.browser_face_state,'PENDING');assert.equal(row.browser_face_conflict,true);assert.equal(row.browser_face_latest_attempt,'REJECTED_DUPLICATE');assert.equal(row.browser_face_test_ready,false);assert.equal(row.attendance_id,0);flow.dispose();
});

test('authorized reset displays uncaptured state and a new saved capture clears the exclusion flag',async()=>{
 const current={session_id:7},row={contact_id:101,attendance_id:0},scope={list:[row],$evalAsync:f=>f(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test',getContext:()=>current,video:{},canvas:{}});const context={mode:'enroll',attendance_context:JSON.stringify(current)};
 flow.result({state:'ENROLLMENT_STATUS',persons:[{contact_id:101,state:'RECAPTURE_REQUIRED',test_excluded:true,test_ready:false}]},context);
 assert.equal(row.browser_face_excluded,true);assert.equal(row.browser_face_test_ready,false);assert.equal(row.face_mapping_active,false);
 await flow.apply({state:'PENDING',enrollment_state:'PENDING',contact_id:101,test_ready:true,test_excluded:false},context);
 assert.equal(row.browser_face_excluded,false);assert.equal(row.browser_face_state,'PENDING');assert.equal(row.browser_face_test_ready,true);assert.equal(row.browser_face_latest_attempt,'PENDING');assert.equal(row.attendance_id,0);flow.dispose();
});

test('TEST recognition stages one correct row and punch freezes collection without operational writes',async()=>{
 const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},row={contact_id:101,type:'M',attendance_id:0,sessions_used:4};
 const scope={list:[row,{contact_id:102,type:'M'}],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});flow.context={...context,mode:'attendance',attendance_context:JSON.stringify(context)};flow.state.operating_mode='TEST';
 let stops=0;flow.capture={stop(){stops++;}};
 const recognized={state:'TEST_RECOGNIZED',person_id:1,contact_id:101,person_name:'A',test_receipt_key:'receipt_00000000001'};
 await flow.apply(recognized,flow.context,{observations:[[1,0]]});await flow.apply(recognized,flow.context,{observations:[[1,0]]});
 assert.equal(scope.faceTestPending.length,1);assert.equal(row.browser_face_test_attendance_state,'PRESENT_PENDING_PUNCH');assert.equal(row.attendance_id,0);assert.equal(row.sessions_used,4);assert.equal(scope.list[1].browser_face_test_attendance_state,undefined);
 await flow.apply({state:'TEST_UNKNOWN',reason:'AMBIGUOUS'},flow.context,{observations:[[0,1]]});assert.equal(scope.faceTestPending.length,1);
 let posts=0;flow.api.post=async(action,payload)=>{assert.equal(action,'test_punch');assert.ok(stops>0);assert.equal(payload.candidates.length,1);posts++;return {state:'TEST_PUNCHED',message:'Test attendance punched.',results:[{state:'RECORDED',person_id:1,contact_id:101,test_attendance_id:9}]};};
 await scope.punchFaceTestAttendance();assert.equal(posts,1);assert.equal(scope.faceTestPending.length,0);assert.equal(scope.faceTestPunchRequest,null);assert.equal(row.browser_face_test_attendance_state,'PRESENT_PUNCHED_TEST');assert.equal(row.attendance_id,0);assert.equal(row.sessions_used,4);
 await scope.punchFaceTestAttendance();assert.equal(posts,1);flow.dispose();
});
test('TEST unknown punch outcome retries the original key and frozen members',async()=>{
 const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'};
 const scope={list:[{contact_id:101}],$evalAsync:fn=>fn(),$watch(){},$on(){}};const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});
 scope.faceTestPending=[{person_id:1,contact_id:101,receipt_key:'receipt_00000000001',context:JSON.stringify(context)}];let first,calls=0;
 flow.api.post=async(action,payload)=>{calls++;if(!first){first=structuredClone(payload);throw Error('network failed');}assert.deepEqual(payload,first);return {state:'TEST_PUNCHED',message:'Test attendance punched.',results:[{state:'ALREADY_MARKED',person_id:1,contact_id:101,test_attendance_id:7}]};};
 await scope.punchFaceTestAttendance();assert.ok(scope.faceTestPunchRequest);assert.equal(scope.faceTestPending.length,1);await scope.punchFaceTestAttendance();assert.equal(calls,2);assert.equal(scope.faceTestPending.length,0);assert.equal(scope.faceTestPunchRequest,null);flow.dispose();
});
test('TEST labels use saved compatibility, not previous rejected attempt',()=>{
 const scope={$evalAsync:fn=>fn(),$watch(){},$on(){}};const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>({}),video:{},canvas:{}});
 assert.equal(scope.faceCaptureButtonLabel({browser_face_test_ready:true,browser_face_latest_attempt:'REJECTED_DUPLICATE'}),'Update Face');
 for(const r of [{},{browser_face_test_ready:true,browser_face_excluded:true},{browser_face_test_ready:true,browser_face_conflict:true}])assert.equal(scope.faceCaptureButtonLabel(r),'Enroll Face');flow.dispose();
});
test('TEST refresh reads persisted sandbox rows without overwriting operational attendance',async()=>{
 const scope={list:[{contact_id:101,type:'M',attendance_id:81,negative:0},{contact_id:102,type:'M'}],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>({batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'}),video:{},canvas:{}});
 flow.api.get=async(action)=>{assert.equal(action,'test_attendance');return {persons:[{contact_id:101,state:'PRESENT_PUNCHED_TEST',test_attendance_id:9}]};};
 await scope.refreshFaceTestAttendance();assert.equal(scope.list[0].browser_face_test_attendance_state,'PRESENT_PUNCHED_TEST');assert.equal(scope.list[0].attendance_id,81);assert.equal(scope.list[1].browser_face_test_attendance_state,'NOT_DETECTED');flow.dispose();
});
test('TEST stale response cannot stage a new session row',async()=>{
 const current={batch_id:11,date:'2026-10-12',session_id:8,time:'12:00:00'},scope={list:[{contact_id:101}],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>current,video:{},canvas:{}});
 flow.result({state:'TEST_RECOGNIZED',person_id:1,contact_id:101,test_receipt_key:'receipt_00000000001'},{attendance_context:JSON.stringify({...current,session_id:7})});assert.equal(scope.faceTestPending.length,0);flow.dispose();
});
test('TEST known partial punch retains only unsaved rows and permits a clean reset',async()=>{
 const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},scope={list:[{contact_id:101},{contact_id:102}],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});scope.faceTestPending=[1,2].map(id=>({person_id:id,contact_id:100+id,receipt_key:'receipt_0000000000'+id,context:JSON.stringify(context)}));
 flow.api.post=async()=>({state:'PARTIAL_TEST_PUNCH',message:'Some test attendance could not be saved. Please retry.',results:[{state:'RECORDED',person_id:1,contact_id:101,test_attendance_id:9},{state:'REJECTED',person_id:2,contact_id:102}]});
 await scope.punchFaceTestAttendance();assert.deepEqual(scope.faceTestPending.map(p=>p.person_id),[2]);assert.equal(scope.faceTestPunchRequest,null);assert.equal(scope.list[0].browser_face_test_attendance_state,'PRESENT_PUNCHED_TEST');flow.dispose();
});
test('TEST selection guard warns before destructive roster resets and restores declined context',()=>{
 const original={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},scope={list:[{browser_face_test_attendance_state:'PRESENT_PENDING_PUNCH'}],$evalAsync:fn=>fn(),$watch(){},$on(){}};let restored;
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>original,restoreContext:c=>{restored=c;},video:{},canvas:{}});
 scope.faceTestPending=[{person_id:1,context:JSON.stringify(original)}];const prior=window.confirm;window.confirm=()=>false;
 assert.equal(scope.canChangeFaceTestContext(),false);assert.deepEqual(restored,original);assert.equal(scope.faceTestPending.length,1);
 window.confirm=()=>true;assert.equal(scope.canChangeFaceTestContext(),true);assert.equal(scope.faceTestPending.length,0);assert.equal(scope.list[0].browser_face_test_attendance_state,'NOT_DETECTED');window.confirm=prior;flow.dispose();
});
test('TEST result targets the member row and cannot stage a same-contact employee row',async()=>{
 const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},employee={contact_id:101,type:'E'},member={contact_id:101,type:'M'};
 const scope={list:[employee,member],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});
 await flow.result({state:'TEST_RECOGNIZED',person_id:1,contact_id:101,test_receipt_key:'receipt_00000000001'},{attendance_context:JSON.stringify(context)});
 assert.equal(employee.browser_face_test_attendance_state,undefined);assert.equal(member.browser_face_test_attendance_state,'PRESENT_PENDING_PUNCH');flow.dispose();
});
test('TEST punch cancels a non-writing in-flight recognition without staging a late candidate',async()=>{
 const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},scope={list:[{contact_id:101,type:'M'}],$evalAsync:fn=>fn(),$watch(){},$on(){}};
 const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});flow.state.operating_mode='TEST';flow.pending={action:'attendance'};
 scope.faceTestPending=[{person_id:1,contact_id:101,receipt_key:'receipt_00000000001',context:JSON.stringify(context)}];
 flow.api.post=async()=>({state:'TEST_PUNCHED',message:'Test attendance punched.',results:[{state:'RECORDED',person_id:1,contact_id:101,test_attendance_id:9}]});
 await scope.punchFaceTestAttendance();assert.equal(flow.pending,null);assert.equal(flow.state.retry,false);assert.equal(scope.faceTestPending.length,0);flow.dispose();
});
test('TEST camera Update title follows freshly fetched backend state over a stale row',async()=>{
 for(const ready of [true,false]){
  const context={batch_id:11,date:'2026-10-12',session_id:7,time:'10:00:00'},row={contact_id:101,browser_face_test_ready:!ready,fullname:'Member A'},scope={list:[row],$evalAsync:fn=>fn(),$watch(){},$on(){}};
  const flow=installAttendanceFace(scope,{csrf:'test-only',getContext:()=>context,video:{},canvas:{}});let opened;
  flow.api.get=async()=>({persons:[{id:1,contact_id:101,test_ready:ready}]});flow.open=async c=>{opened=c;};
  await scope.openAutomaticFace('enroll',row);assert.equal(opened.update_face,ready);assert.equal(scope.automaticFaceTitle,ready?'Update Face':'Enroll Face');assert.equal(row.browser_face_test_ready,ready);flow.dispose();
 }
});
