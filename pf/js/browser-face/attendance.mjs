import {EmbeddedFaceFlow} from './embedded.mjs?v=frictionless-20261016-1';
import {errorMessage} from './messages.mjs?v=frictionless-20261016-1';
export {errorMessage};
// AngularJS bridge: shares the existing row objects; never reloads the roster.
export function installAttendanceFace(scope,{csrf,getContext,restoreContext,notify=()=>{},url,video,canvas}) {
    scope.faceTestPending=[];scope.faceTestPunchBusy=false;scope.faceTestPunchRequest=null;
    const current=()=>JSON.stringify(getContext());
    const flow=new EmbeddedFaceFlow(video,canvas,{csrf,url,
        change:state=>scope.$evalAsync(()=>{scope.automaticFace=state;}),
        result:(result,context)=>scope.$evalAsync(()=>{
            if(JSON.stringify(getContext())!==context.attendance_context)return;
            if(result.state==='ENROLLMENT_STATUS'){
                for(const person of result.persons){const row=(scope.list || []).find(row=>row.type!=='E' && String(row.contact_id)===String(person.contact_id));if(row)Object.assign(row,{browser_face_state:person.state,browser_face_enrolled:person.state==='ACTIVE',face_mapping_active:person.state==='ACTIVE',browser_face_pending:person.state==='PENDING',browser_face_test_ready:person.test_ready===true,browser_face_conflict:person.test_gallery_conflict===true,browser_face_excluded:person.test_excluded===true,browser_face_latest_attempt:person.latest_attempt_state || null});}return;
            }
            const row=(scope.list || []).find(row=>row.type!=='E' && String(row.contact_id)===String(result.contact_id || context.contact_id));
            if(!row)return;
            if(result.state==='RECOGNIZED' || result.state==='TEST_RECOGNIZED'){
                row.selected=true;
                row.negative=0;
                row.skip=false;
                if(result.state==='TEST_RECOGNIZED' && result.test_receipt_key && !scope.faceTestPunchBusy && !scope.faceTestPunchRequest){
                    if(row.browser_face_test_attendance_state==='PRESENT_PUNCHED_TEST')return;
                    if(!scope.faceTestPending.some(p=>p.person_id===result.person_id))scope.faceTestPending.push({person_id:result.person_id,contact_id:result.contact_id,receipt_key:result.test_receipt_key,context:context.attendance_context});
                    row.browser_face_test_attendance_state='PRESENT_PENDING_PUNCH';
                }
                return;
            }
            if(['ENROLLED','ALREADY_ENROLLED','ACTIVE'].includes(result.state))Object.assign(row,{face_mapping_active:true,browser_face_enrolled:true,browser_face_legacy:false,browser_face_pending:false,browser_face_state:'ACTIVE'});
            if(['ENROLLMENT_PENDING','PENDING','RECAPTURE_REQUIRED','REJECTED_DUPLICATE'].includes(result.state)){
                const state=result.enrollment_state || result.state;
                Object.assign(row,{browser_face_pending:['ENROLLMENT_PENDING','PENDING'].includes(state),browser_face_state:state,browser_face_test_ready:result.test_ready===true,browser_face_conflict:result.test_gallery_conflict===true,browser_face_excluded:result.test_excluded===true,browser_face_latest_attempt:result.state,browser_face_enrolled:state==='ACTIVE',face_mapping_active:state==='ACTIVE'});
            }
            if(['RECORDED','ALREADY_MARKED'].includes(result.state)){
                const previously=String(row.attendance_id || '0');Object.assign(row,{attendance_id:String(result.attendance_id),negative:result.finalization?Number(result.negative):0,selected:false,skip:true});
                if(previously==='0' && result.deducted_sessions)row.sessions_used=Number(row.sessions_used || 0)+result.deducted_sessions;
            }
        })});
    const cameraStatus=flow.api.onStatus;flow.api.onStatus=status=>{if(scope.faceTestPunchBusy)scope.$evalAsync(()=>{scope.faceTestPunchMessage=status;});else cameraStatus(status);};
    function personReady(row){return row.browser_face_test_ready===true && !row.browser_face_excluded && !row.browser_face_conflict;}
    scope.faceCaptureButtonLabel=row=>personReady(row)?'Update Face':'Enroll Face';
    scope.refreshFaceTestAttendance=async()=>{
        const context=getContext(),signature=current();
        if(!context.batch_id || !context.time)return;
        const response=await flow.api.get('test_attendance',{batch_id:context.batch_id,attendance_date:context.date,attendance_session_id:context.session_id,selected_time:context.time});
        scope.$evalAsync(()=>{if(current()!==signature)return;
            for(const row of scope.list || [])if(row.type==='M' || row.type===undefined)row.browser_face_test_attendance_state=scope.faceTestPending.some(p=>String(p.contact_id)===String(row.contact_id) && p.context===signature)?'PRESENT_PENDING_PUNCH':'NOT_DETECTED';
            for(const saved of response.persons || []){const row=(scope.list || []).find(r=>r.type!=='E' && String(r.contact_id)===String(saved.contact_id));if(row)Object.assign(row,{browser_face_test_attendance_state:saved.state,browser_face_test_attendance_id:saved.test_attendance_id});}
        });
    };
    scope.canChangeFaceTestContext=()=>{
        const original=scope.faceTestPunchRequest?.context || scope.faceTestPending[0]?.context;
        if(scope.faceTestPunchBusy || scope.faceTestPunchRequest){if(original && restoreContext)restoreContext(JSON.parse(original));notify('Check the previous punch before changing session.');return false;}
        if(!scope.faceTestPending.length)return true;
        if(!window.confirm('Discard unsaved test attendance before changing session?')){if(restoreContext)restoreContext(JSON.parse(original));return false;}
        scope.faceTestPending=[];scope.faceTestPunchMessage='';++version;flow.close();
        for(const row of scope.list || [])if(row.browser_face_test_attendance_state==='PRESENT_PENDING_PUNCH')row.browser_face_test_attendance_state='NOT_DETECTED';
        return true;
    };
    scope.resetFaceTestScan=async()=>{
        if(scope.faceTestPunchRequest || scope.faceTestPunchBusy){notify('Tap Punch Attendance again to check the previous save.');return;}
        if(scope.faceTestPending.length && !window.confirm('Discard unsaved test attendance and scan again?'))return;
        ++version;await flow.close();scope.$evalAsync(()=>{scope.faceTestPending=[];scope.faceTestPunchMessage='';});await scope.refreshFaceTestAttendance();
    };
    scope.punchFaceTestAttendance=async()=>{
        if(scope.faceTestPunchBusy)return;
        if(flow.pending && flow.pending.action!=='attendance'){notify('Use Retry to check the previous face save first.');return;}
        const context=getContext(),signature=current();
        if(scope.faceTestPunchRequest && scope.faceTestPunchRequest.context!==signature){notify('Return to the original session to check the previous punch.');return;}
        const staged=scope.faceTestPending.filter(p=>p.context===signature);
        if(!scope.faceTestPunchRequest && !staged.length){notify('Scan a member before punching test attendance.');return;}
        const cancelRecognitionOnly=flow.state.operating_mode==='TEST' && flow.pending?.action==='attendance';
        scope.faceTestPunchBusy=true;scope.faceTestPunchMessage='Saving…';++version;
        try{
            // Invalidate and stop inference before freezing/submitting the list.
            await flow.close();
            // TEST recognition cannot write attendance. Its cancelled result
            // is not a write outcome to reconcile or add after this punch.
            if(cancelRecognitionOnly && flow.pending?.action==='attendance'){flow.pending=null;flow.abort=null;flow.update({retry:false,request_pending:false});}
            if(!scope.faceTestPunchRequest)scope.faceTestPunchRequest={context:signature,payload:{batch_id:context.batch_id,attendance_date:context.date,attendance_session_id:context.session_id,selected_time:context.time,candidates:staged.map(p=>({person_id:p.person_id,receipt_key:p.receipt_key})),idempotency_key:crypto.randomUUID()}};
            const response=await flow.api.post('test_punch',scope.faceTestPunchRequest.payload);
            if(!['TEST_PUNCHED','PARTIAL_TEST_PUNCH'].includes(response.state))throw Object.assign(Error('Unable to save test attendance'),{confirmed:true});
            scope.$evalAsync(()=>{
                if(current()!==signature)return;
                const saved=new Set();for(const r of response.results){if(!['RECORDED','ALREADY_MARKED'].includes(r.state))continue;saved.add(r.person_id);
                    const row=(scope.list || []).find(p=>p.type!=='E' && String(p.contact_id)===String(r.contact_id));if(row)Object.assign(row,{browser_face_test_attendance_state:'PRESENT_PUNCHED_TEST',browser_face_test_attendance_id:r.test_attendance_id});}
                scope.faceTestPending=scope.faceTestPending.filter(p=>!saved.has(p.person_id));
                // A returned partial result is reconciled: retry only unsaved rows.
                // Unknown network outcomes retain the original frozen request.
                scope.faceTestPunchRequest=null;
                scope.faceTestPunchMessage=response.message;notify(response.message);
            });
        }catch(error){scope.$evalAsync(()=>{if(error.confirmed)scope.faceTestPunchRequest=null;scope.faceTestPunchMessage=error.confirmed?errorMessage(error):'Connection interrupted. Tap Punch Attendance again to check the save.';notify(scope.faceTestPunchMessage);});}
        finally{scope.$evalAsync(()=>{scope.faceTestPunchBusy=false;});}
    };
    const warnUnsaved=event=>{if(scope.faceTestPending.length || scope.faceTestPunchRequest){event.preventDefault();event.returnValue='';}};
    window.addEventListener('beforeunload',warnUnsaved);
    const closeFlow=flow.close.bind(flow);
    flow.close=async()=>{
        const context=flow.context;await closeFlow();
        if(!context?.attendance_context || JSON.stringify(getContext())!==context.attendance_context)return;
        try{const options=await flow.api.get('options',{batch_id:context.batch_id});if(JSON.stringify(getContext())!==context.attendance_context)return;
            flow.result({state:'ENROLLMENT_STATUS',persons:options.persons.map(p=>({...p,member_id:p.id,state:p.enrollment_state}))},context);
        }catch{/* Existing stored row remains visible; never guess a saved status. */}
    };
    let version=0;
    scope.openAutomaticFace=async(mode,row)=>{
        if(scope.faceTestPunchBusy || scope.faceTestPunchRequest){notify('Tap Punch Attendance again to check the previous save.');return;}
        if(mode!=='attendance' && !row)return;
        if(flow.state.open && !flow.pending)return;
        const token=++version,context=getContext();
        try{
            if(flow.pending){await flow.open(context);return;}
            if(mode==='migration' && !row.browser_face_migration_authorized){notify('Explicit authorization is required to replace this legacy enrollment.');return;}
            if(mode==='attendance' && (!context.batch_id || !context.time)){notify('Select a batch and time first.');return;}
            if(mode!=='attendance'){
                const options=await flow.api.get('options',{batch_id:context.batch_id});if(token!==version)return;
                const person=options.persons.find(p=>p.type!=='E' && String(p.contact_id)===String(row.contact_id));
                if(!person)throw Error('Member is not authorized in this branch');if(person.capture_authorized===false)throw Error('FIELD_RELEASE_DISABLED');
                if(mode==='enroll')context.update_face=person.test_ready===true && !person.test_excluded && !person.test_gallery_conflict;
                scope.$evalAsync(()=>{if(token===version)Object.assign(row,{browser_face_test_ready:person.test_ready===true,browser_face_excluded:person.test_excluded===true,browser_face_conflict:person.test_gallery_conflict===true});});Object.assign(context,{person_id:Number(person.id),contact_id:row.contact_id,legacy_template_ids:row.browser_face_legacy_ids});
            }
            context.mode=mode;if(['enroll','validation'].includes(mode))context.consent=true;context.attendance_context=JSON.stringify(getContext());
            scope.$evalAsync(()=>{
                scope.automaticFaceTitle=mode==='attendance'?'Scan Arrivals':mode==='validation'?'Check Face':context.update_face?'Update Face':'Enroll Face';
                scope.automaticFaceSubject=mode==='attendance'?'':row.fullname;
                const batch=scope.batch?.batches?.find(batch=>String(batch.id)===String(context.batch_id));
                const slot=scope.batch?.times?.[scope.timeIndex],today=new Date().toLocaleDateString('en-GB').replace(/\//g,'-');
                const time=slot?.start_time_string?[slot.start_time_string,slot.end_time_string].filter(Boolean).join(' – '):context.time;
                scope.automaticFaceContext=mode==='attendance'?[batch?.batch || 'Selected class',scope.batch?.date && scope.batch.date!==today?scope.batch.date:'',time].filter(Boolean).join(' · '):'';
                scope.automaticFaceInstruction=mode==='attendance'?'Position one face inside the circle':'Position face inside the circle';
                scope.automaticFaceDiagnosticsVisible=false;
            });await flow.open(context);
        }catch(error){notify(errorMessage(error));}
    };
    scope.closeAutomaticFace=()=>{if(flow.hasUnsaved() && !window.confirm(flow.pending?'A request is still being checked. Close? Use Retry to check its result.':'Discard the unsaved review list? Attendance has not been confirmed.'))return; ++version;return flow.close();};
    scope.finishAutomaticFace=()=>flow.finish();
    scope.backToAutomaticScanning=()=>flow.backToScanning();
    scope.removeAutomaticCandidate=id=>flow.removeCandidate(id);
    scope.confirmAutomaticAttendance=()=>{if(JSON.stringify(getContext())!==flow.context?.attendance_context){notify('Session changed. Return to the original session to confirm this list.');return;}return flow.confirm();};
    scope.switchAutomaticFaceCamera=()=>flow.switchCamera();
    scope.retryAutomaticFace=()=>flow.retry({resume:JSON.stringify(getContext())===(flow.pending?.context || flow.context)?.attendance_context});
    scope.$watch(()=>JSON.stringify(getContext()),(next,old)=>{if(next===old)return;
        if(!scope.faceTestPunchRequest && !scope.faceTestPunchBusy)scope.faceTestPunchMessage='';
        if(scope.faceTestPunchRequest || scope.faceTestPunchBusy){if(restoreContext)restoreContext(JSON.parse(old));notify('Check the previous punch before changing session.');return;}
        if(scope.faceTestPending.length){if(!window.confirm('Discard unsaved test attendance before changing session?')){if(restoreContext)restoreContext(JSON.parse(old));return;}scope.faceTestPending=[];scope.faceTestPunchMessage='';for(const row of scope.list || [])delete row.browser_face_test_attendance_state;}
        if(flow.hasUnsaved() && next===flow.context?.attendance_context)return;
        if(flow.hasUnsaved() && !window.confirm('Changing the session will discard unsaved face suggestions. Continue?')){flow.finish();notify('Return to the original session to review these unsaved suggestions.');return;}
        ++version;flow.close();});
    scope.$on('$destroy',()=>{++version;window.removeEventListener('beforeunload',warnUnsaved);flow.dispose();});
    return flow;
}
