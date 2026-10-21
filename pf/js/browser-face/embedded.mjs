import {AutomaticCapture} from './capture.mjs?v=testing-20261013-1';
import {FaceApi} from './api.mjs?v=stability-20261010-final-2';
import {responseMessage,errorMessage} from './messages.mjs?v=frictionless-20261016-1';
// One reusable camera/API lifecycle for Angular attendance and private collection.
export class EmbeddedFaceFlow {
    constructor(video,canvas,{csrf,url='../routes/browserFaceRoute.php',change=()=>{},result=()=>{}}) {
        Object.assign(this,{video,canvas,change,result,generation:0,pending:null,scan:null,lastMarked:null,state:{open:false,busy:false,progress:0,result_ready:false,guide:null,previous_reference_preserved:false,draft:[],review:false,status:'Looking for face',error:'',retry:false}});
        this.api=new FaceApi(url,csrf,status=>this.update({status,error:''}));
        this.visibility=()=>{if(this.state.open)this.capture?.setVisibility(document.hidden);};
        this.pagehide=event=>{if(event?.persisted)this.capture?.setVisibility(true);else this.dispose();};
        this.beforeunload=event=>{if(this.hasUnsaved()){event.preventDefault();event.returnValue='';}};window.addEventListener('beforeunload',this.beforeunload);
        this.pageshow=event=>{if(event?.persisted && this.state.open)this.capture?.setVisibility(document.hidden);};
        document.addEventListener('visibilitychange',this.visibility);window.addEventListener('pagehide',this.pagehide);window.addEventListener('pageshow',this.pageshow);
    }
    update(patch){
        if(patch.error){const message=errorMessage(patch.error);patch={...patch,status:message,error:message,recognized_name:'',progress:0};}
        Object.assign(this.state,patch);this.change({...this.state});
    }
    diagnose(patch){
        if(!this.config?.isolated_pilot)return;
        const values={...this.state.diagnostics};
        const numbers=['camera_start_ms','frame_count','face_count','detected_face_count','detection_ms','inference_ms','brightness','blur','clipped_fraction','quality_rejections','observation_count','retained_count','camera_width','camera_height','candidate_count','lowest_top_similarity','highest_top_similarity','matching_ms','backend_ms','attendance_id','person_id','session_id','batch_id','best_similarity','duplicate_threshold','best_matching_person_id','calibration_id','preserved_reference_calibration_id'];
        for(const key of numbers)if(Number.isFinite(patch[key]) && Math.abs(patch[key])<=1e8)values[key]=patch[key];
        for(const key of ['stage','quality_reason','decision','request_key','attendance_date','backend_state','comparison_source','capture_session','camera_facing','facing_source'])if(typeof patch[key]==='string' && patch[key].length<=120)values[key]=patch[key];
        if(typeof patch.engine_ready==='boolean')values.engine_ready=patch.engine_ready;
        if(typeof patch.duplicate_check_ran==='boolean')values.duplicate_check_ran=patch.duplicate_check_ran;
        if(typeof patch.preview_mirrored==='boolean')values.preview_mirrored=patch.preview_mirrored;
        this.update({diagnostics:values});
    }
    reportRecovery(){
        if(!this.config?.test_evaluation_ready && !this.config?.isolated_pilot)return;
        if((this.recoveryReports || 0)>=8)return;
        const context=this.context;if(!context)return;this.recoveryReports=(this.recoveryReports || 0)+1;
        const target=this.scan?{scan_id:this.scan.scan_id,scan_token:this.scan.scan_token}:{person_id:context.person_id,batch_id:context.batch_id};
        this.api.post('camera_event',{...target,event:'camera_recovery',idempotency_key:crypto.randomUUID()}).catch(()=>{});
    }
    async initialize(){const generation=this.generation,context=this.context;const config=await this.api.get('config',context?.batch_id?{batch_id:context.batch_id}:{});if(generation===this.generation)this.config=config;return config;}
    createCapture(generation){
        this.capture?.stop();
        this.capture=new AutomaticCapture(this.video,this.canvas,this.config,{
            status:status=>{if(generation===this.generation)this.update({status});},
            captureState:({state,retry})=>{if(generation===this.generation && this.context?.mode!=='attendance')this.update({capture_state:state,retry});},
            progress:progress=>{if(generation===this.generation)this.update({progress});},
            guide:guide=>{if(generation===this.generation)this.update({guide});},
            camera:camera=>{if(generation===this.generation){this.cameraSelection={deviceId:camera.deviceId,facingMode:camera.facingMode || (camera.facingSource && camera.facingSource!=='default'?camera.facing:'')};this.update({can_switch_camera:camera.canSwitch});this.diagnose({camera_facing:camera.facing || camera.facingMode || 'unavailable',facing_source:camera.facingSource || 'unavailable',preview_mirrored:camera.mirrored});}},
            running:()=>{if(generation===this.generation)this.update({busy:true});},
            health:health=>{if(generation===this.generation){if(typeof health.event==='string' && /^(camera|worker|video) recovery$/.test(health.event))this.reportRecovery();const snapshot={...health,backend_pending:!!this.pending,last_backend_response_age_ms:this.lastBackendResponse?performance.now()-this.lastBackendResponse:null};if(health.event){this.healthEvents ||= [];this.healthEvents.push(snapshot);if(this.healthEvents.length>16)this.healthEvents.shift();}this.update({health:{...snapshot,events:this.healthEvents || []}});}},
            error:error=>{if(generation===this.generation){this.diagnose({stage:'Camera or face processing error'});this.update({busy:false,error,retry:true});}},
            metrics:data=>{if(generation===this.generation){
                const counters=Number.isFinite(data.face_count)?{frame_count:(this.state.diagnostics?.frame_count || 0)+1,quality_rejections:(this.state.diagnostics?.quality_rejections || 0)+(data.quality_reason && !['SUITABLE','TRACK_HELD'].includes(data.quality_reason)?1:0)}:{};
                this.diagnose({...data,...counters,camera_width:this.video.videoWidth,camera_height:this.video.videoHeight});
            }},
            batch:batch=>this.batch(batch,generation),
            trackReset:()=>{if(generation===this.generation){clearTimeout(this.feedbackTimer);this.lastMarked=null;this.update({recognized_name:''});}},
            stopped:()=>{if(generation===this.generation)this.update({busy:false,can_switch_camera:false});}
        });
    }
    async startCapture(mode,generation){
        this.createCapture(generation);await this.capture.start(mode,this.cameraSelection || {facingMode:'environment'});
        if(generation===this.generation && this.capture.active)this.update({busy:true});
        this.armFinalization();
    }
    armFinalization(){
        clearInterval(this.cutoffTimer);
        if(this.context?.mode!=='attendance' || this.state.operating_mode!=='LIVE' || !this.scan?.finalization_enabled || !Number.isFinite(Date.parse(this.scan.cutoff_at)))return;
        this.cutoffTimer=setInterval(()=>{if(this.state.open && !document.hidden && !this.pending && Date.now()>=Date.parse(this.scan.cutoff_at))this.finalize();},30000);
    }
    async finalize(){
        if(this.pending || !this.scan || !this.scan.finalization_enabled || this.state.operating_mode!=='LIVE')return;
        clearInterval(this.cutoffTimer);this.capture?.stop();const generation=this.generation;
        try{const response=await this.submit('finalize',{scan_id:this.scan.scan_id,scan_token:this.scan.scan_token,idempotency_key:crypto.randomUUID()},generation);if(generation===this.generation)await this.applyFinalization(response);}
        catch(error){if(generation===this.generation)this.update({error,retry:true,busy:false});}
    }
    async applyFinalization(response){
        if(!['FINALIZED','PARTIAL_FINALIZATION'].includes(response.state))throw Error('Unable to finalize attendance');
        for(const row of response.results)if(['RECORDED','ALREADY_MARKED','PRESERVED'].includes(row.state))await this.result({...row,state:row.state==='PRESERVED'?'ALREADY_MARKED':row.state,finalization:true},this.context);
        this.finalizationRetry=response.state==='PARTIAL_FINALIZATION';
        this.update({status:responseMessage(response),busy:false,error:'',retry:this.finalizationRetry,finalized:response.state==='FINALIZED'});
    }
    async switchCamera(){
        if(this.pending || this.state.camera_switching || !this.capture?.active || this.capture.processing)return;
        const generation=this.generation;clearTimeout(this.feedbackTimer);this.lastMarked=null;this.update({camera_switching:true,error:'',recognized_name:''});
        try{await this.capture.switchCamera();if(generation===this.generation && this.capture.active)this.update({busy:true});}
        catch(error){if(generation===this.generation){this.capture.stop();this.update({busy:false,error,retry:true});}}
        finally{if(generation===this.generation)this.update({camera_switching:false});}
    }
    async open(context) {
        if(this.pending){this.update({open:true,error:'Resolve the previous submitted request with Retry before changing member or session.',retry:true});return;}
        if(this.state.open)return;
        const generation=++this.generation;this.context=Object.freeze({...context,capture_session:crypto.randomUUID()});this.lastMarked=null;this.healthEvents=[];this.recoveryReports=0;this.finalizationRetry=false;
        this.update({capture_mode:context.mode,operating_mode:'',open:true,busy:true,status:'Starting camera',error:'',progress:0,result_ready:false,previous_reference_preserved:false,guide:null,retry:false,diagnostics:{},health:{},recognized_name:'',pilot_notice:'',can_switch_camera:false,camera_switching:false,draft:[],review:false,saved_results:[],confirmed:false,finalized:false});
        try{
            await this.initialize();if(generation!==this.generation)return;
            this.update({pilot_notice:this.config.pilot_notice || '',operating_mode:this.config.operating_mode || ''});this.diagnose({stage:'Camera startup'});
            if(context.mode==='attendance' && this.config.scanning_available===false){this.update({busy:false,error:this.config.availability_message || 'Face capture is available. Scanning is not enabled for this class yet.',retry:false});return;}
            if(!['calibration','validation'].includes(context.mode) && this.config.acceptance_ready===false && !(context.mode==='attendance' && ['SHADOW','TEST'].includes(this.config.operating_mode)) && !(context.mode==='enroll' && context.consent===true && this.config.enrollment_collection_available===true)){
                this.update({busy:false,status:'Scanner unavailable',error:this.config.availability_message || 'Face scanning is not ready yet. Please contact the administrator.',retry:false});return;
            }
            if(context.mode==='attendance') {
                const opened=await this.submit('open',{batch_id:context.batch_id,attendance_date:context.date,attendance_session_id:context.session_id,selected_time:context.time,idempotency_key:crypto.randomUUID()},generation);
                if(generation!==this.generation){if(opened?.state==='OPEN')this.endScan(opened);return;}
                if(opened.state!=='OPEN')throw Error(responseMessage(opened));this.scan=opened;this.diagnose({stage:'Camera startup',session_id:context.session_id,batch_id:context.batch_id,attendance_date:context.date});
                if(opened.enrollments)await this.result({state:'ENROLLMENT_STATUS',persons:opened.enrollments},this.context);
            }
            if(generation===this.generation)await this.startCapture(context.mode==='attendance'?'attendance':'enroll',generation);
        }catch(error){if(generation===this.generation)this.update({busy:false,error:error.name==='AbortError'?'Connection interrupted. Retry safely.':error,retry:true});}
    }
    async submit(action,payload,generation) {
        const request={action,payload,context:this.context,generation},controller=new AbortController();this.pending=request;this.abort=controller;this.update({request_pending:true});
        try{const response=await this.api.post(action,payload,controller.signal);this.lastBackendResponse=performance.now();if(this.pending===request){this.pending=null;this.abort=null;this.update({request_pending:false});}return response;}
        catch(error){if(error.confirmed && this.pending===request){this.pending=null;this.abort=null;this.update({request_pending:false});}if(generation===this.generation)this.diagnose({stage:error.confirmed?'Backend rejected request':'Network interrupted; retry the same request'});throw error;}
    }
    async batch(batch,generation) {
        if(generation!==this.generation)return false;
        const context=this.context;
        // Only unchanged observations can reuse a confirmed result. Similarity
        // to an earlier arrival must never suppress central identification.
        if(context.mode==='attendance' && this.lastMarked && batch.observations.length===this.lastMarked.length
            && batch.observations.every((vector,i)=>vector.length===this.lastMarked[i].length && vector.every((value,j)=>value===this.lastMarked[i][j]))){this.update({status:this.state.operating_mode==='LIVE'?'Attendance already marked.':this.state.operating_mode==='TEST'?'Already recognized.':'Already added for review'});return {waitForDeparture:true};}
        let action=context.mode==='attendance'?'attendance':context.mode==='validation'?'validate_capture':context.mode==='calibration'?'calibration_collect':context.mode==='migration'?'migrate':'enroll';
        const payload={...(context.update_face?{update_face:true}:{}),model_version:this.config.model_version,preprocessing_version:this.config.preprocessing_version,idempotency_key:crypto.randomUUID()};
        if(action==='attendance')Object.assign(payload,{scan_id:this.scan.scan_id,scan_token:this.scan.scan_token,observations:batch.observations});
        else Object.assign(payload,{person_id:context.person_id,template:batch.template});
        if(action==='migrate')payload.legacy_template_ids=context.legacy_template_ids;
        if(action==='validate_capture' || (action==='enroll' && (this.config.lifecycle_available || this.config.acceptance_ready===false))){
            if(!Number.isFinite(batch.brightness))throw Error('Camera lighting measurement unavailable; retry');
            const settings=this.capture.stream?.getVideoTracks()[0]?.getSettings() || {};
            const device=JSON.stringify([navigator.userAgent,settings.deviceId || 'unavailable',settings.facingMode || 'unavailable']);
            const digest=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(device));
            Object.assign(payload,{consent:context.consent,device:'camera:'+Array.from(new Uint8Array(digest),value=>value.toString(16).padStart(2,'0')).join(''),lighting:batch.brightness<90?'measured-dark':batch.brightness>170?'measured-bright':'measured-normal'});
            if(this.config.lifecycle_available)Object.assign(payload,{capture_session:context.capture_session,observations:batch.observations,evaluation_context:{batch_id:context.batch_id,attendance_date:context.date,session_id:context.session_id,selected_time:context.time}});
            if(generation!==this.generation)return false;
        }
        if(action==='calibration_collect')Object.assign(payload,{consent:context.consent,visit:context.visit,device:context.device,lighting:context.lighting,split:context.split});
        this.diagnose({stage:'Backend request',request_key:payload.idempotency_key,observation_count:batch.observations?.length,retained_count:batch.retained});
        if(action==='attendance' && this.config.isolated_pilot){const d=this.state.diagnostics || {};payload.capture_diagnostics=Object.fromEntries(['camera_start_ms','camera_width','camera_height','frame_count','quality_rejections','detection_ms','inference_ms','brightness','blur','clipped_fraction'].filter(key=>Number.isFinite(d[key])).map(key=>[key,d[key]]));}
        this.lastBatch=batch;this.update({status:action==='attendance'?'Recognizing...':'Checking face...'});
        const started=performance.now();
        let response;
        try{response=await this.submit(action,payload,generation);}
        catch(error){
            if(generation!==this.generation)return false;
            if(this.pending && !error.confirmed){this.update({error,retry:true,progress:0});return {pending:true};}
            throw error;
        }
        if(generation!==this.generation)return false;
        this.lastBackendResponse=performance.now();this.diagnose({backend_ms:performance.now()-started});
        return this.apply(response,context,batch);
    }
    showCaptureResult(response){
        this.capture?.stop();this.lastBatch=null;this.lastMarked=null;
        this.update({capture_state:['REJECTED_DUPLICATE','DUPLICATE','RECAPTURE_REQUIRED'].includes(response.state)?'FAILED':['ACTIVE','ENROLLED','ALREADY_ENROLLED'].includes(response.state)?'COMPLETED':'CAPTURE_COMPLETE',result_ready:true,guide:null,progress:0,busy:false,can_switch_camera:false,camera_switching:false,previous_reference_preserved:response.previous_reference_preserved===true});
    }
    async apply(response,context,batch) {
        if(response.state==='SESSION_CUTOFF' && this.scan?.finalization_enabled){await this.finalize();return false;}
        if(context.mode==='attendance' && ['RECOGNIZED','TEST_RECOGNIZED','TEST_UNKNOWN'].includes(response.state)){
            this.update({status:responseMessage(response),recognized_name:response.person_name || '',error:'',retry:false,progress:0});
            if(response.state==='RECOGNIZED' || response.state==='TEST_RECOGNIZED'){this.lastMarked=batch?.observations?.map(vector=>vector.slice()) || null;await this.result(response,context);}
            const generation=this.generation;clearTimeout(this.feedbackTimer);
            this.feedbackTimer=setTimeout(()=>{if(generation===this.generation && this.capture?.waitForDeparture)this.update({status:'Ready to scan',recognized_name:''});},2500);
            return {waitForDeparture:true};
        }
        if(context.mode==='attendance' && (response.state==='REVIEW_CANDIDATE' || response.state==='SHADOW_EVALUATED')){
            const suggestion=response.suggestion;
            if(suggestion && Number.isInteger(suggestion.person_id) && typeof suggestion.receipt_key==='string'){
                if(!this.state.draft.some(row=>row.person_id===suggestion.person_id))this.update({draft:[...this.state.draft,{
                    person_id:suggestion.person_id,contact_id:suggestion.contact_id,person_name:suggestion.person_name,
                    receipt_key:suggestion.receipt_key,recognition_result:suggestion.recognition_result,
                    verified:false,requires_independent_verification:!!suggestion.requires_independent_verification,
                    branch_id:suggestion.branch_id,batch_id:context.batch_id,session_id:context.session_id,date:context.date,review_status:'AWAITING_CONFIRMATION'}]});
                this.lastMarked=batch?.observations?.map(vector=>vector.slice()) || null;
            }
            this.diagnose({...response.diagnostics,backend_state:response.state,stage:'Recognition complete; awaiting operator confirmation'});
            this.update({status:responseMessage(response),recognized_name:'',error:'',retry:false});
            const generation=this.generation;clearTimeout(this.feedbackTimer);
            this.feedbackTimer=setTimeout(()=>{if(generation===this.generation && this.capture?.waitForDeparture)this.update({status:'Ready to scan'});},2000);
            return {waitForDeparture:true};
        }
        this.diagnose({...response.diagnostics,backend_state:response.state,attendance_id:response.attendance_id,person_id:response.person_id,stage:response.state==='UNKNOWN'?'Identity not accepted':response.state==='RECORDED' || response.state==='ALREADY_MARKED'?'Attendance saved; scanning continues':'Backend result'});
        const accepted=['ENROLLED','ALREADY_ENROLLED','RECORDED','ALREADY_MARKED','CALIBRATION_SAVED','ENROLLMENT_PENDING','PENDING','ACTIVE','VALIDATION_SAVED','SHADOW_EVALUATED'];
        if(accepted.includes(response.state)) {
            const active=['ENROLLED','ALREADY_ENROLLED','ACTIVE'].includes(response.state) && (!response.enrollment_state || response.enrollment_state==='ACTIVE');
            this.update({status:responseMessage(response),recognized_name:response.person_name || '',error:'',retry:false});
            if(context.mode==='attendance' && response.state!=='SHADOW_EVALUATED' && batch?.observations)this.lastMarked=batch.observations.map(vector=>vector.slice());
            if(context.mode!=='attendance')this.showCaptureResult(response);
            await this.result(response,context);
            if(active && context.mode==='enroll' && this.state.open){const generation=this.generation;clearTimeout(this.successTimer);this.successTimer=setTimeout(()=>{if(generation===this.generation)this.close();},2000);}
            if(context.mode==='attendance' && ['RECORDED','ALREADY_MARKED','SHADOW_EVALUATED'].includes(response.state)){
                const generation=this.generation;clearTimeout(this.feedbackTimer);
                if(this.state.open && this.capture)this.feedbackTimer=setTimeout(()=>{if(generation===this.generation && this.capture?.waitForDeparture && (this.lastMarked || response.state==='SHADOW_EVALUATED'))this.update({status:'Ready to scan',recognized_name:''});},2000);
                return {waitForDeparture:true};
            }
        }else if(response.state==='UNKNOWN'){this.update({status:responseMessage(response),recognized_name:'',error:'',progress:0});}
        else if(['DUPLICATE','REJECTED_DUPLICATE','RECAPTURE_REQUIRED'].includes(response.state)){this.showCaptureResult(response);this.update({status:responseMessage(response),recognized_name:'',error:'',progress:0,busy:false,retry:true});await this.result(response,context);}
        else{const message=responseMessage(response);this.update({error:message,retry:true});throw Error(message);}
    }
    async retry({resume=true}={}){
        if(this.finalizationRetry && !this.pending && this.state.open){await this.finalize();return;}
        this.update({open:true,error:'',status:'Starting camera',recognized_name:'',progress:0,result_ready:false,guide:null,previous_reference_preserved:false,retry:false});
        if(this.pending){const saved=this.pending;const generation=this.generation;this.context=saved.context;
            try{const response=await this.submit(saved.action,saved.payload,generation);if(generation!==this.generation)return;
                if(saved.action==='open'){
                    if(response.state!=='OPEN')throw Error(response.message);
                    if(!resume){await this.endScan(response);if(generation===this.generation)this.update({open:false,busy:false,status:'Previous request resolved. Open the scanner again.'});return;}
                    this.scan=response;await this.startCapture('attendance',generation);
                }
                else{
                    if(saved.action==='finalize'){await this.applyFinalization(response);return;}
                    if(saved.action==='confirm'){await this.applyConfirmation(response,saved.context);return;}
                    if(saved.action==='attendance' && !resume){this.capture?.stop();this.update({open:false,busy:false,draft:[],status:'Previous request resolved. Open the scanner again.'});return;}
                    await this.apply(response,saved.context,this.lastBatch);if(generation!==this.generation)return;
                    if(!resume){this.capture?.stop();this.update({open:false,busy:false,status:'Previous request resolved. Open the scanner again.'});return;}
                    if(saved.context.mode!=='attendance')this.capture?.stop();
                    if(saved.context.mode==='attendance'){
                        if(this.scan){if(!this.capture?.active || !this.capture.resumeAfterRequest())await this.startCapture('attendance',generation);}
                        else{
                            // Closing the camera also closes its server lease.
                            // Recover the old result, then authorize a fresh scan
                            // instead of leaving an open panel without a camera.
                            this.update({open:false,busy:false});await this.open(saved.context);
                        }
                    }
                }
            }catch(error){if(generation===this.generation)this.update({busy:false,error,retry:true});}
        }else if(this.state.review){await this.confirm();}else{const context=this.context;await this.close();await this.open(context);}
    }
    hasUnsaved(){return !!this.state.draft?.length || !!this.pending;}
    async finish(){
        if(['LIVE','TEST'].includes(this.state.operating_mode)){await this.close();return;}
        if(this.pending){this.update({status:'Tap Retry to finish the previous request.',retry:true});return;}
        ++this.generation;clearTimeout(this.feedbackTimer);this.capture?.stop();
        this.update({review:true,busy:false,progress:0,can_switch_camera:false,status:this.state.draft.length?'Review the members before confirming.':'No members added for review.',error:'',retry:false});
    }
    async backToScanning(){
        if(this.pending || !this.scan)return;
        const generation=++this.generation;this.lastMarked=null;clearTimeout(this.feedbackTimer);this.update({review:false,busy:true,status:'Starting camera',error:'',retry:false});
        try{await this.startCapture('attendance',generation);}catch(error){if(generation===this.generation)this.update({error,retry:true,busy:false});}
    }
    removeCandidate(id){if(!this.pending)this.update({draft:this.state.draft.filter(row=>row.person_id!==id)});}
    async confirm(){
        if(this.pending || !this.state.review || !this.scan)return;
        const candidates=this.state.draft.filter(row=>row.verified).map(row=>({person_id:row.person_id,receipt_key:row.receipt_key}));
        if(!candidates.length){this.update({status:'Select the members you have verified.'});return;}
        const generation=this.generation;this.update({status:'Saving attendance...',error:'',retry:false});
        try{const response=await this.submit('confirm',{scan_id:this.scan.scan_id,scan_token:this.scan.scan_token,candidates,verified:true,idempotency_key:crypto.randomUUID()},generation);
            if(generation===this.generation)await this.applyConfirmation(response,this.context);
        }catch(error){if(generation===this.generation)this.update({error,retry:true,busy:false});}
    }
    async applyConfirmation(response,context){
        if(!['CONFIRMED','PARTIAL_CONFIRMATION'].includes(response.state))throw Error('Unable to confirm attendance');
        const saved=response.results.filter(row=>['RECORDED','ALREADY_MARKED'].includes(row.state));
        for(const row of saved)await this.result(row,context);
        const ids=new Set(saved.map(row=>row.person_id));
        const outcomes=new Map((this.state.saved_results || []).map(row=>[row.person_id,row]));for(const row of response.results)outcomes.set(row.person_id,row);
        this.update({draft:this.state.draft.filter(row=>!ids.has(row.person_id)),saved_results:[...outcomes.values()],
            status:responseMessage(response),error:'',retry:false,busy:false,review:true,confirmed:response.state==='CONFIRMED'});
        // Keep unchecked suggestions available for independent review; no implicit
        // save, absence marking or enrollment activation occurs here.
    }
    async endScan(scan){try{await this.api.post('close',{scan_id:scan.scan_id,scan_token:scan.scan_token,idempotency_key:crypto.randomUUID()});}catch{/* 2-hour server lease safely expires */}}
    async close(){++this.generation;this.finalizationRetry=false;clearInterval(this.cutoffTimer);clearTimeout(this.successTimer);clearTimeout(this.feedbackTimer);this.capture?.stop();this.abort?.abort();const scan=this.scan;this.scan=null;this.lastMarked=null;
        this.update({open:false,busy:false,progress:0,result_ready:false,guide:null,previous_reference_preserved:false,draft:[],review:false,saved_results:[],can_switch_camera:false,camera_switching:false,status:this.pending?'Tap Retry to check the previous request.':'Camera closed',error:'',recognized_name:'',retry:!!this.pending});
        if(scan)await this.endScan(scan);
    }
    dispose(){this.close();window.removeEventListener('beforeunload',this.beforeunload);document.removeEventListener('visibilitychange',this.visibility);window.removeEventListener('pagehide',this.pagehide);window.removeEventListener('pageshow',this.pageshow);}
}
