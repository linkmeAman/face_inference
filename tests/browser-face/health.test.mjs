import {test} from 'node:test';
import assert from 'node:assert/strict';
import {AutomaticCapture} from '../../pf/js/browser-face/capture.mjs';
import {readFileSync} from 'node:fs';
const config=JSON.parse(readFileSync(new URL('../../pf/js/browser-face/contract.json',import.meta.url)));
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function harness(run){
 const saved=new Map(['navigator','Worker','isSecureContext','document'].map(k=>[k,Object.getOwnPropertyDescriptor(globalThis,k)]));
 const workers=[],tracks=[],errors=[],health=[];
 class Track extends EventTarget{readyState='live';muted=false;stop(){this.readyState='ended';}getSettings(){return {facingMode:'environment'};}}
 const video={videoWidth:640,videoHeight:480,readyState:4,currentTime:1,style:{},play:async()=>{}};
 const globals={document:{hidden:false},isSecureContext:true,navigator:{mediaDevices:{getUserMedia:async()=>{const t=new Track();tracks.push(t);return {getTracks:()=>[t],getVideoTracks:()=>[t]};}}},Worker:class{constructor(){workers.push(this);}postMessage(data){if(data.type==='init')queueMicrotask(()=>this.onmessage?.({data:{type:'ready'}}));}terminate(){this.terminated=true;}}};
 for(const [key,value] of Object.entries(globals))Object.defineProperty(globalThis,key,{configurable:true,writable:true,value});
 const ctx={clearRect(){},fillRect(){},beginPath(){},arc(){},fill(){},stroke(){}};
 const capture=new AutomaticCapture(video,{clientWidth:640,clientHeight:480,getContext:()=>ctx},config,{status(){},progress(){},error:e=>errors.push(e),health:h=>health.push(h)});
 capture.tick=()=>{};
 try{await capture.start('attendance');await run({capture,workers,tracks,errors,health,video});}
 finally{capture.stop();for(const [key,value] of saved)if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}
}
test('hung worker is detected and only worker is recovered; camera stays live and stale replies are ignored',async()=>harness(async h=>{
 const old=h.capture.worker,stream=h.capture.stream;h.capture.busy=true;h.capture.jobStarted=performance.now()-20000;
 h.capture.checkHealth();assert.equal(h.capture.recovering,true);assert.equal(old.terminated,true);
 await wait(1100);assert.equal(h.workers.length,2);assert.equal(h.capture.stream,stream);assert.equal(h.tracks[0].readyState,'live');assert.equal(h.capture.engineReady,true);assert.equal(h.capture.busy,false);
 old.onmessage({data:{type:'error'}});assert.equal(h.capture.recovering,false);assert.equal(h.errors.length,0);
}));
test('ended track reacquires exactly one stream and cleans old worker/listeners',async()=>harness(async h=>{
 const old=h.capture.worker;h.tracks[0].readyState='ended';h.tracks[0].dispatchEvent(new Event('ended'));
 await wait(1100);assert.equal(h.tracks.length,2);assert.equal(old.terminated,true);assert.equal(h.tracks.filter(t=>t.readyState==='live').length,1);assert.equal(h.capture.active,true);
 h.tracks[0].dispatchEvent(new Event('ended'));assert.equal(h.capture.recovering,false);
}));
test('no face and legitimate waiting do not trigger restart; mute/unmute gets a grace period',async()=>harness(async h=>{
 h.capture.waitForDeparture=true;h.capture.health.last_video_frame=performance.now();h.capture.checkHealth();assert.equal(h.workers.length,1);assert.equal(h.capture.recovering,false);
 h.tracks[0].muted=true;h.tracks[0].dispatchEvent(new Event('mute'));assert.equal(h.capture.recovering,false);h.tracks[0].muted=false;h.tracks[0].dispatchEvent(new Event('unmute'));assert.equal(h.capture.recovering,false);
}));
test('background pause cancels scheduling, retains stream and prevents stale observations',async()=>harness(async h=>{
 const stream=h.capture.stream;h.capture.setVisibility(true);assert.equal(h.capture.paused,true);h.capture.checkHealth(performance.now()+60000);assert.equal(h.capture.recovering,false);
 await h.capture.observe({face_count:1,box:{x:200,y:150,width:100,height:120},embedding:[1,0]});assert.equal(h.capture.samples.length,0);
 h.capture.setVisibility(false);await wait(0);assert.equal(h.capture.stream,stream);assert.equal(h.capture.paused,false);assert.equal(h.capture.active,true);
}));
test('camera watchdog never interrupts an unknown backend outcome',async()=>harness(async h=>{
 h.capture.processing=true;h.capture.health.last_video_frame=0;h.capture.checkHealth(performance.now()+60000);assert.equal(h.capture.recovering,false);
 h.capture.recover('camera');assert.equal(h.capture.deferredRecovery,'camera');assert.equal(h.tracks[0].readyState,'live');
}));
test('recovery budget is bounded and closing cancels scheduled recreation',async()=>harness(async h=>{
 h.capture.recoveryAttempts=2;await h.capture.recover('worker');assert.equal(h.capture.active,false);assert.equal(h.errors[0],'Camera stopped. Tap Retry to continue.');
 await h.capture.start('attendance');const n=h.workers.length;await h.capture.recover('worker');h.capture.stop();await wait(1100);assert.equal(h.workers.length,n);assert.equal(h.tracks.every(t=>t.readyState==='ended'),true);
}));
test('fresh-frame scheduler uses one video callback and skips unchanged fallback frames',async()=>harness(async h=>{
 h.capture.tick=AutomaticCapture.prototype.tick.bind(h.capture);let callbacks=0;h.video.requestVideoFrameCallback=()=>{callbacks++;return 1;};h.video.cancelVideoFrameCallback=()=>{};
 await h.capture.tick();await h.capture.tick();assert.equal(callbacks,1);assert.equal(h.capture.busy,false);
 h.capture.cancelFrame();delete h.video.requestVideoFrameCallback;h.capture.lastVideoTime=h.video.currentTime;await h.capture.tick();assert.equal(h.capture.busy,false);
}));

test('a cancelled old video callback cannot clear or duplicate a replacement frame loop',async()=>harness(async h=>{
 const callbacks=[];h.video.requestVideoFrameCallback=callback=>{callbacks.push(callback);return callbacks.length;};h.video.cancelVideoFrameCallback=()=>{};
 h.capture.monitorFrames();const old=callbacks[0];h.capture.cancelFrame();h.capture.monitorFrames();const id=h.capture.frameCallback;
 old();assert.equal(h.capture.frameCallback,id);assert.equal(callbacks.length,2);callbacks[1]();assert.equal(callbacks.length,3);
}));
test('visible frozen video is distinguished from a hung worker and triggers camera-only recovery',async()=>harness(async h=>{
 h.video.requestVideoFrameCallback=()=>1;h.capture.health.last_video_frame=performance.now()-20000;
 h.capture.checkHealth();assert.equal(h.capture.recovering,true);assert.equal(h.tracks[0].readyState,'ended');assert.equal(h.health.at(-1).event,'camera recovery');
}));

test('failed bitmap transfer closes the temporary bitmap and does not queue a second job',async()=>harness(async h=>{
 const saved=globalThis.createImageBitmap;let closed=0;
 globalThis.createImageBitmap=async()=>({width:640,height:480,close(){closed++;}});
 h.capture.tick=AutomaticCapture.prototype.tick.bind(h.capture);h.capture.worker.postMessage=()=>{throw Error('transfer failure');};h.capture.recover=component=>{assert.equal(component,'camera');};
 try{await h.capture.tick();assert.equal(closed,1);assert.equal(h.capture.busy,false);}finally{if(saved)globalThis.createImageBitmap=saved;else delete globalThis.createImageBitmap;}
}));

test('unreconciled request holds inference while preview stays live; recovery resumes fresh observations',async()=>harness(async h=>{
 h.capture.mode='enroll';h.capture.config={...h.capture.config,dimension:2};h.capture.draw=()=>{};h.capture.callbacks.batch=()=>({pending:true});h.capture.samples=Array(4).fill([1,0]);h.capture.lastSample=performance.now()-400;
 await h.capture.observe({face_count:1,box:{x:200,y:150,width:100,height:120},embedding:[1,0],quality_reason:'SUITABLE'});
 assert.equal(h.capture.backendPending,true);assert.equal(h.capture.processing,true);assert.equal(h.capture.active,true);assert.equal(h.tracks[0].readyState,'live');
 h.capture.mode='attendance';assert.equal(h.capture.resumeAfterRequest(),true);assert.equal(h.capture.processing,false);assert.equal(h.capture.samples.length,0);assert.equal(h.capture.backendPending,false);
}));

test('paused playback resumes the video without replacing a healthy camera or worker',async()=>harness(async h=>{
 const stream=h.capture.stream,worker=h.capture.worker;let plays=0;h.video.requestVideoFrameCallback=()=>1;h.video.paused=true;h.video.play=async()=>{plays++;h.video.paused=false;};h.capture.health.last_video_frame=performance.now()-20000;
 h.capture.checkHealth();assert.equal(h.health.at(-1).event,'video recovery');await wait(1100);assert.equal(plays,1);assert.equal(h.capture.stream,stream);assert.equal(h.capture.worker,worker);assert.equal(h.tracks[0].readyState,'live');
}));

test('partial enrollment survives worker failure; old worker cannot verify or advance it',async()=>harness(async h=>{
 h.capture.mode='enroll';h.capture.config={...config,capture_continuity:{same_min:.746782791039185,different_max:.6513243536866755,observations:3}};
 const vector=Array(128).fill(0);vector[0]=1;h.capture.samples=Array.from({length:3},()=>vector.slice());h.capture.brightness=[120,120,120];
 const old=h.capture.worker,stream=h.capture.stream;old.onerror();assert.equal(h.capture.samples.length,3);assert.equal(h.capture.needsVerification,true);
 await wait(1100);assert.equal(h.capture.stream,stream);assert.equal(h.capture.samples.length,3);assert.equal(h.capture.brightness.length,3);
 old.onmessage({data:{type:'observation',epoch:h.capture.epoch,face_count:1,box:{x:200,y:150,width:100,height:120},embedding:vector,quality_reason:'SUITABLE'}});assert.equal(h.capture.samples.length,3);assert.equal(h.capture.returning.length,0);
 for(let i=0;i<2;i++){h.capture.lastSample=0;await h.capture.observe({face_count:1,box:{x:200,y:150,width:100,height:120},embedding:vector,quality_reason:'SUITABLE'});assert.equal(h.capture.samples.length,3);}
 h.capture.lastSample=0;await h.capture.observe({face_count:1,box:{x:200,y:150,width:100,height:120},embedding:vector,quality_reason:'SUITABLE'});assert.equal(h.capture.samples.length,4);
}));
test('camera reacquisition preserves partial enrollment but requires fresh identity verification',async()=>harness(async h=>{
 h.capture.mode='enroll';const vector=Array(128).fill(0);vector[0]=1;h.capture.samples=Array.from({length:3},()=>vector.slice());h.capture.brightness=[100,110,120];
 const oldEpoch=h.capture.epoch;h.tracks[0].readyState='ended';h.tracks[0].dispatchEvent(new Event('ended'));await wait(1100);
 assert.equal(h.capture.samples.length,3);assert.deepEqual(h.capture.brightness,[100,110,120]);assert.equal(h.capture.needsVerification,true);assert.equal(h.capture.epoch>oldEpoch,true);assert.equal(h.tracks.filter(t=>t.readyState==='live').length,1);
 h.capture.setVisibility(true);assert.equal(h.capture.samples.length,3);assert.equal(h.capture.returning.length,0);h.capture.setVisibility(false);assert.equal(h.capture.needsVerification,true);
}));
test('a worker reply from before mute/background cannot become a returning observation',async()=>harness(async h=>{
 h.capture.mode='enroll';const vector=Array(128).fill(0);vector[0]=1;h.capture.samples=[vector.slice()];h.capture.jobRevision=h.capture.selectionRevision || 0;
 h.tracks[0].muted=true;h.tracks[0].dispatchEvent(new Event('mute'));
 h.capture.worker.onmessage({data:{type:'observation',epoch:h.capture.epoch,face_count:1,box:{x:200,y:150,width:100,height:120},embedding:vector,quality_reason:'SUITABLE'}});
 assert.equal(h.capture.samples.length,1);assert.equal(h.capture.returning.length,0);assert.equal(h.capture.busy,false);
}));
