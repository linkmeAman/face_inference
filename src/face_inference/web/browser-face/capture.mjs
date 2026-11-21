import {aggregate,captureGuide,normalize,cosine} from './core.mjs?v=stability-20261010-final';
import {continuityPolicy,continuityDecision} from './continuity.mjs?v=testing-20261012-2';
import {resolveCameraFacing,isMobileBrowser} from './facing.mjs?v=testing-20261013-1';
export class AutomaticCapture {
    constructor(video,canvas,config,callbacks) {
        Object.assign(this,{video,canvas,config,callbacks,epoch:0,active:false,samples:[],busy:false,processing:false});
    }
    async start(mode,selection=this.cameraSelection || {facingMode:'environment'},recovering=false) {
        // Only internal camera recovery carries this session's samples forward.
        // Explicit start/Retry/switch/member change always starts fresh.
        const retained=recovering && this.active && this.mode==='enroll' && !this.processing
            ? {samples:this.samples,brightness:this.brightness,stats:this.continuityStats}:null;
        this.stop();
        if(retained){this.samples=retained.samples;this.brightness=retained.brightness;this.continuityStats=retained.stats;this.needsVerification=this.samples.length>0;}
        const epoch = this.epoch;
        this.active = true; this.mode = mode; this.started = performance.now();
        if(!recovering)this.recoveryAttempts=0;
        this.health={last_video_frame:this.started,last_detection:0,last_inference:0,worker_started:this.started,worker_state:'starting',consecutive_failures:0,recovery_attempts:this.recoveryAttempts || 0};
        this.paused=!!globalThis.document?.hidden;this.engineReady=false;
        this.continuityStats ||= {pauses:0,resumes:0,different_faces:0,uncertain_returns:0};
        this.enrollmentState(this.samples.length?'PAUSED_NO_FACE':'WAITING_FOR_FACE');
        this.callbacks.progress?.(this.samples.length/this.config.batch_size);
        this.callbacks.status('Starting camera');
        this.deadline = setTimeout(()=>{if(this.active && epoch===this.epoch)this.fail(this.engineReady?'Face capture timed out. Please try again.':'Camera or face collection timed out; retry');},this.config.capture_timeout_ms);
        try {
            if (!globalThis.isSecureContext) throw Error('Camera requires HTTPS');
            if (!navigator.mediaDevices?.getUserMedia) throw Error('This browser cannot open a camera');
            const size={width:{ideal:640},height:{ideal:480}};
            const preferred={...size,facingMode:{ideal:selection.facingMode || 'environment'}};
            if(selection.deviceId)preferred.deviceId={ideal:selection.deviceId};
            let stream;
            try{stream=await navigator.mediaDevices.getUserMedia({video:preferred,audio:false});}
            catch(error){
                if(!this.active || epoch!==this.epoch)return;
                if(!['OverconstrainedError','NotFoundError','NotReadableError'].includes(error.name))throw error;
                stream=await navigator.mediaDevices.getUserMedia({video:size,audio:false});
            }
            if (!this.active || epoch !== this.epoch) { stream.getTracks().forEach(track=>track.stop()); return; }
            this.stream = stream; this.video.srcObject = stream;
            const track=stream.getVideoTracks()[0],settings=track?.getSettings?.() || {};
            this.cameraSelection={deviceId:settings.deviceId || '',facingMode:settings.facingMode || ''};
            this.requestedFacing=selection.facingMode || 'environment';
            this.applyFacing(track,settings);
            this.video.muted=true;this.video.playsInline=true;
            this.trackListeners=[];
            for(const track of stream.getVideoTracks())for(const event of ['ended','mute','unmute']){
                const listener=()=>{if(!this.active || epoch!==this.epoch)return;
                    this.reportHealth(event);
                    if(event==='ended')this.recover('camera');
                    else if(event==='mute'){this.interrupt('PAUSED_NO_FACE');this.callbacks.status('Camera paused. Reconnecting...');}
                    else{this.health.last_video_frame=performance.now();this.schedule(0);}
                };track.addEventListener(event,listener);this.trackListeners.push([track,event,listener]);
            }
            await this.video.play();
            if (!this.active || epoch !== this.epoch) return;
            this.draw(null);
            if(globalThis.ResizeObserver){this.resizeObserver=new ResizeObserver(()=>{if(this.active && epoch===this.epoch)this.draw(null);});this.resizeObserver.observe(this.canvas);}
            this.callbacks.metrics?.({camera_start_ms:performance.now()-this.started});
            this.monitorFrames(epoch);
            this.createWorker(epoch);
            this.watchHealth(epoch);
            // Enumeration is advisory and never prevents a working camera.
            try{this.cameras=(await navigator.mediaDevices.enumerateDevices?.() || []).filter(device=>device.kind==='videoinput');}catch{this.cameras=[];}
            if(!this.active || epoch!==this.epoch)return;
            let facingModes=[];try{facingModes=track?.getCapabilities?.().facingMode || [];}catch{}
            this.canSwitch=this.cameras.length>1 || facingModes.includes('user') && facingModes.includes('environment');
            // Device count can settle an ambiguous phone camera; known facing is never changed.
            if(this.facingSource!=='settings')this.applyFacing(track,settings);
            this.callbacks.camera?.({...this.cameraSelection,canSwitch:this.canSwitch,mirrored:this.mirrored,facing:this.facing,facingSource:this.facingSource});
        } catch(error) {
            if (epoch===this.epoch && this.active) this.fail(error.name==='NotAllowedError' ? 'Allow camera access, then retry' : error.message);
        }
    }
    // Mirror only the displayed video layer (selfie view for the front camera). The
    // frames sent to YuNet/SFace come from createImageBitmap(video), which ignores CSS
    // transforms, so model input, alignment and stored templates are unaffected.
    applyFacing(track,settings) {
        let capabilities={};try{capabilities=track?.getCapabilities?.() || {};}catch{}
        const result=resolveCameraFacing({settings,capabilities,label:track?.label || '',requested:this.requestedFacing,deviceCount:this.cameras?.length || 0,mobile:isMobileBrowser()});
        this.facing=result.facing;this.facingSource=result.source;this.mirrored=result.facing==='user';
        this.paintMirror();
    }
    paintMirror() {
        if(this.video.style)this.video.style.transform=this.mirrored?'scaleX(-1)':'none';
        this.video.setAttribute?.('data-camera-facing',this.facing || '');
        this.video.setAttribute?.('data-preview-mirrored',String(!!this.mirrored));
    }
    createWorker(epoch=this.epoch) {
        this.worker?.terminate();this.busy=false;this.engineReady=false;
        const worker=new Worker(new URL('./worker.mjs?v=stability-20261010-final',import.meta.url),{type:'module'});
        this.worker=worker;this.health.worker_started=performance.now();this.health.worker_state='starting';
        const current=()=>this.active && !this.recovering && epoch===this.epoch && this.worker===worker;
        worker.onerror=worker.onmessageerror=()=>{if(current())this.recover('worker');};
        worker.onmessage=({data})=>{
            if(!current())return;
            if(data.type==='ready'){
                this.engineReady=true;this.health.worker_state='ready';this.callbacks.running?.();
                this.callbacks.metrics?.({engine_ready:true});
                if(this.mode==='attendance' || this.needsVerification)clearTimeout(this.deadline);
                this.schedule(0);
            }else if(data.type==='error')this.recover('worker');
            else if(data.type==='observation' && data.epoch===this.epoch){
                if(this.jobRevision!==(this.selectionRevision || 0)){this.busy=false;this.schedule(0);return;}
                const now=performance.now();this.health.last_detection=now;
                if(data.embedding)this.health.last_inference=now;
                this.latency=this.latency?this.latency*.8+(now-this.jobStarted)*.2:now-this.jobStarted;
                this.health.consecutive_failures=0;this.health.worker_state='ready';
                Promise.resolve(this.observe(data)).catch(()=>{if(current())this.fail('Unable to complete the request. Please try again.');});
            }
        };
        worker.postMessage({type:'init',config:this.config});
    }
    schedule(delay=this.config.sample_interval_ms) {
        clearTimeout(this.timer);
        if(!this.active || this.paused || this.recovering || this.processing)return;
        const epoch=this.epoch;
        this.timer=setTimeout(()=>{if(this.active && epoch===this.epoch)this.tick();},delay);
    }
    cancelFrame() {
        if(this.frameCallback!=null)this.video.cancelVideoFrameCallback?.(this.frameCallback);
        this.frameCallback=null;this.frameWaiting=false;this.frameSerial=(this.frameSerial || 0)+1;
    }
    monitorFrames(epoch=this.epoch) {
        if(!this.active || this.paused || !this.video.requestVideoFrameCallback || this.frameCallback!=null)return;
        const serial=this.frameSerial || 0;
        this.frameCallback=this.video.requestVideoFrameCallback(()=>{
            if(!this.active || epoch!==this.epoch || serial!==(this.frameSerial || 0) || this.paused)return;
            this.frameCallback=null;
            this.health.last_video_frame=performance.now();
            if(this.frameWaiting){this.frameWaiting=false;this.frameFresh=true;this.tick();}
            this.monitorFrames(epoch);
        });
    }
    reportHealth(event='') {
        if(!this.health)return;
        const now=performance.now(),track=this.stream?.getVideoTracks()[0];
        this.callbacks.health?.({event,elapsed_ms:now-this.started,last_video_frame_age_ms:now-this.health.last_video_frame,
            last_detection_age_ms:this.health.last_detection?now-this.health.last_detection:null,
            last_inference_age_ms:this.health.last_inference?now-this.health.last_inference:null,
            in_flight:this.busy?1:0,worker_state:this.health.worker_state,
            scanner_state:this.paused?'paused':this.recovering?'recovering':this.backendPending?'awaiting_request_recovery':this.processing?'backend':this.waitForDeparture?'waiting_for_departure':'scanning',
            track_state:track?.readyState || 'unavailable',track_muted:!!track?.muted,video_ready_state:this.video.readyState || 0,video_paused:!!this.video.paused,video_error:this.video.error?.code || 0,
            consecutive_failures:this.health.consecutive_failures,recovery_attempts:this.recoveryAttempts || 0});
    }
    watchHealth(epoch=this.epoch) {
        clearTimeout(this.healthTimer);
        if(!this.active)return;
        this.healthTimer=setTimeout(()=>{
            if(!this.active || epoch!==this.epoch)return;
            this.checkHealth();this.watchHealth(epoch);
        },2000);
    }
    checkHealth(now=performance.now()) {
        if(!this.video.requestVideoFrameCallback && Number.isFinite(this.video.currentTime) && this.video.currentTime!==this.healthVideoTime){this.healthVideoTime=this.video.currentTime;this.health.last_video_frame=now;}
        this.reportHealth();
        if(!this.active || this.paused || this.recovering || this.processing)return;
        const track=this.stream?.getVideoTracks()[0];
        // Conservative floor, increased by measured device latency; this is a
        // liveness timeout, never a face/identity acceptance threshold.
        const timeout=Math.max(15000,(this.latency || 500)*8);
        if(track?.readyState==='ended' || this.video.srcObject!==this.stream){this.recover('camera');return;}
        if(!this.engineReady){if(now-this.health.worker_started>this.config.capture_timeout_ms)this.recover('worker');return;}
        if(this.busy && now-this.jobStarted>timeout){this.recover('worker');return;}
        if(now-this.health.last_video_frame>timeout){this.recover(this.video.paused && !track?.muted && !this.video.error?'video':'camera');return;}
        if(!this.busy && !this.frameWaiting)this.schedule(0);
    }
    async recover(component) {
        if(!this.active || this.paused || this.recovering)return;
        // Never replay or abandon a submitted batch from a camera watchdog.
        if(this.processing){this.deferredRecovery=component;return;}
        if((this.recoveryAttempts || 0)>=2){this.fail('Camera stopped. Tap Retry to continue.');return;}
        this.recoveryAttempts=(this.recoveryAttempts || 0)+1;this.recovering=true;
        this.health.consecutive_failures++;this.health.recovery_attempts=this.recoveryAttempts;
        this.reportHealth(component+' recovery');this.callbacks.status('Camera paused. Reconnecting...');
        clearTimeout(this.timer);this.cancelFrame();this.interrupt('PAUSED_NO_FACE');
        const epoch=this.epoch;
        if(component==='worker'){this.worker?.terminate();this.worker=null;this.busy=false;}
        else if(component==='camera'){this.detachTracks();this.stream?.getTracks().forEach(track=>track.stop());this.video.srcObject=null;}
        this.recoveryTimer=setTimeout(async()=>{
            if(!this.active || epoch!==this.epoch)return;
            this.recovering=false;
            try{
                if(component==='worker'){this.monitorFrames(epoch);this.createWorker(epoch);}
                else if(component==='video'){await this.video.play();if(this.active && epoch===this.epoch){this.health.last_video_frame=performance.now();this.monitorFrames(epoch);this.schedule(0);}}
                else await this.start(this.mode,this.cameraSelection,true);
            }catch{if(this.active && epoch===this.epoch)this.fail('Camera stopped. Tap Retry to continue.');}
        },1000*this.recoveryAttempts);
    }
    setVisibility(hidden) {
        if(!this.active)return;
        this.paused=hidden;clearTimeout(this.timer);this.cancelFrame();this.interrupt('PAUSED_NO_FACE');
        if(hidden){clearTimeout(this.deadline);this.callbacks.status('Camera paused. Reconnecting...');this.reportHealth('background');return;}
        this.health.last_video_frame=performance.now();
        // A suspended in-flight worker may never deliver its old reply.
        if(this.busy && !this.processing)this.recover('worker');
        else if(this.stream?.getVideoTracks()[0]?.readyState==='ended' || this.video.srcObject!==this.stream)this.recover('camera');
        else{const epoch=this.epoch;this.monitorFrames(epoch);Promise.resolve(this.video.play()).then(()=>{if(this.active && epoch===this.epoch && !this.paused){this.callbacks.status(this.mode==='attendance'?'Ready to scan':'Position a face inside the circle.');this.schedule(0);}}).catch(()=>{if(this.active && epoch===this.epoch)this.fail('Camera stopped. Tap Retry to continue.');});}
        const deadlineEpoch=this.epoch;
        if(this.mode!=='attendance')this.deadline=setTimeout(()=>{if(this.active && deadlineEpoch===this.epoch)this.fail(this.engineReady?'Face capture timed out. Please try again.':'Camera or face collection timed out; retry');},this.config.capture_timeout_ms);
        this.reportHealth('foreground');
    }
    detachTracks() {for(const [track,event,listener] of this.trackListeners || [])track.removeEventListener?.(event,listener);this.trackListeners=[];}
    async switchCamera() {
        if(!this.active || this.processing || !this.canSwitch)return false;
        const current=this.cameraSelection,mode=this.mode;
        const facingMode=(this.facing || current.facingMode)==='environment'?'user':'environment';
        const next=this.cameras.find(camera=>camera.deviceId && camera.deviceId!==current.deviceId);
        // When facing metadata is absent, an enumerated camera is preferable
        // to guessing from localized device labels. All constraints are ideal.
        await this.start(mode,{facingMode,...(!current.facingMode && next?{deviceId:next.deviceId}:{})});
        if(this.active && current.deviceId && current.deviceId===this.cameraSelection.deviceId)this.callbacks.status('Other camera unavailable; using current camera');
        return this.active;
    }
    async tick() {
        if (!this.active || this.busy || this.processing || this.paused || this.recovering || !this.engineReady) return;
        if (document.hidden || !this.video.videoWidth || this.video.readyState < 2) {
            this.interrupt('PAUSED_NO_FACE'); this.schedule(200); return;
        }
        if(!this.frameFresh){
            if(this.frameWaiting)return;
            if(this.video.requestVideoFrameCallback){
                this.frameWaiting=true;this.monitorFrames();return;
            }
            const time=this.video.currentTime;
            if(Number.isFinite(time) && time===this.lastVideoTime){this.schedule(200);return;}
            this.lastVideoTime=time;this.health.last_video_frame=performance.now();
        }
        this.frameFresh=false;this.busy=true;this.jobRevision=this.selectionRevision || 0;this.jobStarted=performance.now();this.health.worker_state='processing';
        const epoch=this.epoch,worker=this.worker;
        let bitmap,transferred=false;
        try {
            bitmap=await createImageBitmap(this.video);
            if (!this.active || epoch!==this.epoch || this.worker!==worker || this.paused || this.recovering) { bitmap.close(); if(epoch===this.epoch && this.worker===worker)this.busy=false;return; }
            const guide=this.draw(null);
            if(!guide || bitmap.width!==this.video.videoWidth || bitmap.height!==this.video.videoHeight){bitmap.close();this.busy=false;this.schedule(200);return;}
            worker.postMessage({type:'frame',bitmap,epoch,guide:guide.source,guide_key:this.guideKey,recognize:!this.waitForDeparture},[bitmap]);transferred=true;
        } catch { if(bitmap && !transferred)bitmap.close();if(epoch===this.epoch && this.active) {this.busy=false;this.recover('camera');} }
    }
    draw(box) {
        const width=this.canvas.clientWidth || this.video.videoWidth, height=this.canvas.clientHeight || this.video.videoHeight;
        if(this.canvas.width!==width)this.canvas.width=width; if(this.canvas.height!==height)this.canvas.height=height;
        const context=this.canvas.getContext('2d'); context.clearRect(0,0,width,height);
        if(!width || !height)return null;
        const fit=globalThis.getComputedStyle?.(this.video).objectFit || 'contain';
        const geometry=captureGuide(this.video.videoWidth,this.video.videoHeight,width,height,fit,this.mirrored);
        if(!geometry)return null;
        const key=JSON.stringify([this.video.videoWidth,this.video.videoHeight,width,height,fit,!!this.mirrored]);
        if(this.guideKey && this.guideKey!==key)this.interrupt('PAUSED_QUALITY');
        this.guideKey=key;
        const guide=geometry.display;
        this.callbacks.guide?.({...guide});
        // Overlay only: the original video/bitmap remains rectangular and clear.
        context.fillStyle='#000';context.fillRect(0,0,width,height);
        context.globalCompositeOperation='destination-out';
        context.beginPath();context.arc(guide.x,guide.y,guide.radius,0,2*Math.PI);context.fill();
        context.globalCompositeOperation='source-over';
        context.beginPath();context.arc(guide.x,guide.y,guide.radius,0,2*Math.PI);
        context.strokeStyle='rgba(255,255,255,.9)';context.lineWidth=3;context.lineJoin='round';context.stroke();
        if(this.config.diagnostics===true && box){
            const mapped={x:geometry.offsetX+(this.mirrored?this.video.videoWidth-box.x-box.width:box.x)*geometry.scale,y:geometry.offsetY+box.y*geometry.scale,width:box.width*geometry.scale,height:box.height*geometry.scale};
            context.strokeStyle='#32d583';context.strokeRect(mapped.x,mapped.y,mapped.width,mapped.height);
        }
        return geometry;
    }
    enrollmentState(state) {
        if(this.mode!=='enroll')return;
        this.captureState=state;this.callbacks.captureState?.({state,retry:['DIFFERENT_FACE_DETECTED','PAUSED_IDENTITY_UNCERTAIN'].includes(state)});
    }
    interrupt(state='PAUSED_NO_FACE') {
        if(this.mode!=='enroll'){this.reset();return;}
        this.selectionRevision=(this.selectionRevision || 0)+1;
        this.track=null;this.returning=[];this.lastSample=0;this.verificationStarted=0;
        if(this.samples.length){
            if(!this.needsVerification)this.continuityStats && this.continuityStats.pauses++;
            this.needsVerification=true;clearTimeout(this.deadline);
        }
        this.enrollmentState(state);
    }
    retainObservation(data,now) {
        if(!data.embedding || data.quality_reason!=='SUITABLE' || data.face_count!==1 || !data.box)return false;
        if(this.lastSample && now-this.lastSample<this.config.sample_interval_ms)return false;
        const vector=normalize(data.embedding,this.config.dimension),policy=continuityPolicy(this.config);
        // Even overlapping boxes cannot prove identity: compare already-produced
        // embeddings cheaply, without running another inference job.
        if(this.samples.length && policy && this.samples.some(previous=>cosine(previous,vector)<policy.same_min))this.needsVerification=true;
        if(this.needsVerification && this.samples.length){
            clearTimeout(this.deadline);
            this.verificationStarted ||= now;
            this.returning ||= [];this.returning.push(vector);this.lastSample=now;
            if(this.returning.length>(policy?.observations || 3))this.returning.shift();
            const decision=continuityDecision(this.samples,this.returning,policy);
            if(decision!=='SAME'){
                const state=decision==='DIFFERENT'?'DIFFERENT_FACE_DETECTED':decision==='VERIFYING'?'VERIFYING_RETURNING_FACE':'PAUSED_IDENTITY_UNCERTAIN';
                if(state!==this.captureState && this.continuityStats){if(decision==='DIFFERENT')this.continuityStats.different_faces++;else if(decision==='UNCERTAIN')this.continuityStats.uncertain_returns++;}
                this.enrollmentState(state);
                this.callbacks.status(decision==='DIFFERENT'?'Different face detected. Return to the original face or tap Retry.':decision==='UNAVAILABLE'?'Unable to verify the returning face. Tap Retry for a fresh capture.':decision==='VERIFYING'?'Checking face...':'Hold steady. Position the original face inside the circle.');
                return false;
            }
            this.needsVerification=false;this.returning=[];
            if(this.continuityStats)this.continuityStats.resumes++;
            this.callbacks.metrics?.({reidentification_ms:now-this.verificationStarted,...this.continuityStats});this.verificationStarted=0;
        }
        this.samples.push(vector);this.lastSample=now;
        if(Number.isFinite(data.brightness))(this.brightness ||= []).push(data.brightness);
        this.callbacks.progress?.(this.samples.length/this.config.batch_size);
        this.enrollmentState('COLLECTING');this.callbacks.status('Capturing...');
        const epoch=this.epoch;clearTimeout(this.deadline);
        this.deadline=setTimeout(()=>{if(this.active && epoch===this.epoch)this.fail('Face capture timed out. Please try again.');},this.config.capture_timeout_ms);
        return true;
    }
    reset(clearTrack=true) {
        this.samples=[];this.brightness=[];this.returning=[];this.needsVerification=false;this.verificationStarted=0;this.lastSample=0;this.callbacks.progress?.(0);
        this.enrollmentState('WAITING_FOR_FACE');
        if(clearTrack){this.track=null;this.waitForDeparture=false;this.callbacks.trackReset?.();}
    }
    async observe(data) {
        this.busy=false; if(!this.active || this.processing)return; if(this.paused){this.interrupt('PAUSED_NO_FACE');return;} this.draw(data.box); this.callbacks.metrics?.(data);
        if(data.guide_key && data.guide_key!==this.guideKey){this.interrupt('PAUSED_QUALITY');this.schedule(0);return;}
        const now=performance.now();
        const previous=this.track?.box, box=data.box;
        const overlaps=previous && box && Math.min(previous.x+previous.width,box.x+box.width)>Math.max(previous.x,box.x)
            && Math.min(previous.y+previous.height,box.y+box.height)>Math.max(previous.y,box.y);
        const loss=data.face_count!==1 || !box || data.quality_reason==='OUTSIDE_GUIDE' || (previous && !overlaps) || now-(this.lastSample || now)>2000;
        if(this.mode==='enroll'){
            if(loss || data.quality_reason!=='SUITABLE')this.interrupt(['NO_FACE','OUTSIDE_GUIDE'].includes(data.quality_reason) || !box?'PAUSED_NO_FACE':'PAUSED_QUALITY');
        }else if(loss)this.reset();
        this.track=data.face_count===1 && box ? {box} : null;
        if(!this.waitForDeparture)this.callbacks.status(data.quality_reason==='SUITABLE'?(this.mode==='attendance'?'Recognizing...':'Capturing...'):data.quality_reason==='TRACK_HELD'?'Ready to scan':data.quality_reason==='MULTIPLE_FACES'?'Only one face at a time':data.quality_reason==='FACE_TOO_SMALL'?'Move closer':['TOO_DARK','TOO_BRIGHT','CLIPPED_EXPOSURE'].includes(data.quality_reason)?'Improve lighting and try again.':data.quality_reason==='BLUR'?'Hold steady':data.quality_reason==='NO_FACE'?(this.mode==='attendance'?'Ready to scan':'Position face inside the circle.'):data.status);
        if(this.mode==='enroll')this.retainObservation(data,now);
        else if(data.face_count===1 && box && data.embedding && (!this.lastSample || now-this.lastSample>=this.config.sample_interval_ms)) {
            this.samples.push(data.embedding);this.lastSample=now;
            if(Number.isFinite(data.brightness))(this.brightness ||= []).push(data.brightness);
            this.callbacks.progress(this.samples.length/this.config.batch_size);
        }
        if(this.samples.length>=this.config.batch_size) {
            this.enrollmentState('CAPTURE_COMPLETE');
            this.processing=true; clearTimeout(this.deadline);
            const epoch=this.epoch, observations=this.samples.slice();
            try {
                const result=aggregate(observations,this.config.minimum_observations);
                const brightness=this.brightness?.length ? this.brightness.reduce((sum,value)=>sum+value,0)/this.brightness.length : null;
                this.enrollmentState('SAVING');
                const next=await this.callbacks.batch({observations,template:result.template,retained:result.retained,brightness});
                if(!this.active || epoch!==this.epoch) return;
                this.callbacks.metrics?.({completion_ms:performance.now()-this.started});
                if(next?.pending){this.backendPending=true;this.reportHealth('network request unresolved');return;}
                if(this.mode==='enroll' || next===false) {this.enrollmentState('COMPLETED');this.stop();return;}
                this.reset(false);this.processing=false;
                this.waitForDeparture=next?.waitForDeparture===true;
                if(this.deferredRecovery){const component=this.deferredRecovery;this.deferredRecovery=null;this.recover(component);}else this.schedule(1000);
            } catch(error) {if(epoch===this.epoch) this.fail(error.message);}
            return;
        }
        this.schedule();
    }
    resumeAfterRequest() {
        if(!this.active)return false;
        this.backendPending=false;this.processing=false;this.reset();
        const component=this.deferredRecovery;this.deferredRecovery=null;
        if(component)this.recover(component);else{this.callbacks.running?.();this.schedule(0);}
        return true;
    }
    fail(message) {this.enrollmentState('FAILED');this.stop();this.callbacks.error(message);}
    stop() {
        this.active=false;this.epoch++;clearTimeout(this.timer);clearTimeout(this.deadline);clearTimeout(this.healthTimer);clearTimeout(this.recoveryTimer);this.cancelFrame();this.detachTracks();
        this.recovering=false;this.deferredRecovery=null;this.backendPending=false;this.engineReady=false;this.frameFresh=false;this.lastVideoTime=null;
        this.worker?.terminate();this.worker=null;
        this.resizeObserver?.disconnect();this.resizeObserver=null;
        this.stream?.getTracks().forEach(track=>track.stop());this.stream=null;
        this.video.srcObject=null;this.busy=false;this.processing=false;this.samples=[];this.brightness=[];
        this.lastSample=0;this.track=null;this.waitForDeparture=false;this.guideKey=null;this.returning=[];this.needsVerification=false;this.verificationStarted=0;this.continuityStats=null;
        this.canSwitch=false;
        this.canvas.getContext('2d')?.clearRect(0,0,this.canvas.width,this.canvas.height);
        this.callbacks.stopped?.();
    }
}
