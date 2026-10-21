import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {AutomaticCapture} from '../../pf/js/browser-face/capture.mjs';
import {faceGuide,positioningHint} from '../../pf/js/browser-face/core.mjs';
const config=JSON.parse(readFileSync(new URL('../../pf/js/browser-face/contract.json',import.meta.url)));
async function cameraHarness(run,{getUserMedia,enumerateDevices}={}){
 const saved=new Map(['navigator','isSecureContext','Worker'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 const requests=[],tracks=[],workers=[],arcs=[],rects=[],states=[],errors=[],statuses=[];
 let highestLive=0;
 const stream=(facingMode='environment',deviceId=facingMode)=>{
  const track={stopped:false,stop(){this.stopped=true;},addEventListener(){},getSettings:()=>({facingMode,deviceId}),getCapabilities:()=>({})};tracks.push(track);highestLive=Math.max(highestLive,tracks.filter(t=>!t.stopped).length);
  return {getTracks:()=>[track],getVideoTracks:()=>[track]};
 };
 const media={getUserMedia:async constraints=>{requests.push(constraints);return getUserMedia?getUserMedia(constraints,stream,requests.length):stream(constraints.video.facingMode?.ideal || 'user');},enumerateDevices:enumerateDevices || (async()=>['environment','user'].map(deviceId=>({kind:'videoinput',deviceId})))};
 const globals={navigator:{mediaDevices:media},isSecureContext:true,Worker:class{constructor(){this.terminated=false;workers.push(this);}postMessage(){}terminate(){this.terminated=true;}}};
 for(const [key,value] of Object.entries(globals))Object.defineProperty(globalThis,key,{configurable:true,writable:true,value});
 const attrs={},video={videoWidth:640,videoHeight:480,style:{},attrs,setAttribute(key,value){attrs[key]=value;},play:async()=>{}},context={clearRect(){},fillRect(){},fill(){},beginPath(){},arc(...args){arcs.push(args);},stroke(){},strokeRect(...args){rects.push(args);}};
 const canvas={clientWidth:400,clientHeight:300,getContext:()=>context};
 const capture=new AutomaticCapture(video,canvas,config,{status:message=>statuses.push(message),progress(){},error:message=>errors.push(message),camera:state=>states.push(state)});
 try{await run({capture,video,canvas,requests,tracks,workers,arcs,rects,states,errors,statuses,stream,get highestLive(){return highestLive;}});}
 finally{capture.stop();for(const [key,descriptor] of saved)if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}
}
test('rear preference is ideal, actual settings control mirroring and inline video',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');assert.deepEqual(h.requests[0].video.facingMode,{ideal:'environment'});assert.equal(h.requests[0].audio,false);assert.equal(h.video.style.transform,'none');assert.equal(h.video.muted,true);assert.equal(h.video.playsInline,true);assert.equal(h.states[0].facingMode,'environment');assert.equal(h.capture.canSwitch,true);});
});
test('front-only desktop fallback is mirrored only when settings confirm user camera',async()=>{
 await cameraHarness(async h=>{await h.capture.start('attendance');assert.equal(h.capture.active,true);assert.equal(h.video.style.transform,'scaleX(-1)');assert.equal(h.capture.canSwitch,false);assert.equal(await h.capture.switchCamera(),false);},{getUserMedia:(_,stream)=>stream('user'),enumerateDevices:async()=>[{kind:'videoinput',deviceId:'user'}]});
});
test('unavailable preferred camera falls back without mandatory facing constraints',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');assert.equal(h.requests.length,2);assert.equal(h.requests[1].video.facingMode,undefined);assert.equal(h.capture.active,true);assert.equal(h.errors.length,0);},{getUserMedia:(_,stream,n)=>{if(n===1)throw Object.assign(Error('Unavailable'),{name:'NotFoundError'});return stream('user');}});
});
test('permission denial never loops through cameras and leaves resources released',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');assert.equal(h.requests.length,1);assert.equal(h.capture.active,false);assert.equal(h.capture.worker,null);assert.match(h.errors[0],/Allow camera/);},{getUserMedia:()=>{throw Object.assign(Error('Denied'),{name:'NotAllowedError'});}});
});
test('switching releases old tracks/workers, resets samples and retains selected camera on restart',async()=>{
 await cameraHarness(async h=>{await h.capture.start('attendance');h.capture.samples=[[1,0],[1,0]];const old=h.capture.worker,epoch=h.capture.epoch;
  assert.equal(await h.capture.switchCamera(),true);assert.equal(h.requests[1].video.facingMode.ideal,'user');assert.equal(h.tracks[0].stopped,true);assert.equal(old.terminated,true);assert.equal(h.highestLive,1);assert.equal(h.capture.samples.length,0);assert.equal(h.capture.epoch>epoch,true);assert.equal(h.video.style.transform,'scaleX(-1)');
  old.onmessage({data:{type:'error',message:'stale'}});assert.equal(h.capture.active,true);assert.equal(h.errors.length,0);
  await h.capture.start('attendance');assert.equal(h.requests[2].video.deviceId.ideal,'user');assert.equal(h.requests[2].video.facingMode.ideal,'user');assert.equal(h.highestLive,1);
 });
});
test('a switch cannot interrupt an enrollment/attendance batch already processing',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');h.capture.processing=true;assert.equal(await h.capture.switchCamera(),false);assert.equal(h.requests.length,1);assert.equal(h.tracks[0].stopped,false);});
});
test('closing during camera switch stops its late stream and cannot create a worker',async()=>{
 let release;
 await cameraHarness(async h=>{await h.capture.start('attendance');const switched=h.capture.switchCamera();h.capture.stop();release(h.stream('user'));assert.equal(await switched,false);assert.equal(h.tracks.every(t=>t.stopped),true);assert.equal(h.capture.worker,null);assert.equal(h.workers.length,1);},{getUserMedia:(constraints,stream,n)=>n===1?stream('environment'):new Promise(resolve=>{release=resolve;})});
});
test('missing facing settings fall back to inference (lone webcam is front, mirrored); enumeration failure leaves the camera usable',async()=>{
 await cameraHarness(async h=>{await h.capture.start('attendance');assert.equal(h.video.style.transform,'scaleX(-1)');assert.equal(h.capture.facingSource,'default');assert.equal(h.video.attrs['data-camera-facing'],'user');assert.equal(h.video.attrs['data-preview-mirrored'],'true');assert.equal(h.capture.active,true);assert.equal(h.capture.canSwitch,false);},{getUserMedia:(_,stream)=>stream('','unknown'),enumerateDevices:async()=>{throw Error('Unsupported');}});
});
test('without settings, a rear-looking device label keeps the preview natural; a front label mirrors it',async()=>{
 for(const [label,transform] of [['Back Camera','none'],['camera2 0, facing back','none'],['Front Camera','scaleX(-1)'],['camera2 1, facing front','scaleX(-1)'],['FaceTime HD Camera','scaleX(-1)']])
  await cameraHarness(async h=>{await h.capture.start('attendance');assert.equal(h.video.style.transform,transform,label);assert.equal(h.capture.facingSource,'label',label);},{getUserMedia:(_,stream)=>{const s=stream('','device');s.getVideoTracks()[0].label=label;return s;}});
});
test('settings win over a misleading label and the preview is flipped exactly once',async()=>{
 await cameraHarness(async h=>{await h.capture.start('attendance');assert.equal(h.capture.facingSource,'settings');assert.equal(h.video.style.transform,'scaleX(-1)');assert.equal(h.states.at(-1).mirrored,true);assert.equal(h.states.at(-1).facing,'user');},{getUserMedia:(_,stream)=>{const s=stream('user','front');s.getVideoTracks()[0].label='Back Camera';return s;}});
});
test('switching between cameras updates the mirror from the NEW stream and never stacks transforms',async()=>{
 await cameraHarness(async h=>{
  await h.capture.start('attendance');assert.equal(h.video.style.transform,'none');assert.equal(h.video.attrs['data-camera-facing'],'environment');
  assert.equal(await h.capture.switchCamera(),true);assert.equal(h.requests.at(-1).video.facingMode.ideal,'user');
  assert.equal(h.video.style.transform,'scaleX(-1)');assert.equal(h.video.attrs['data-preview-mirrored'],'true');assert.equal(h.capture.mirrored,true);
  assert.equal(await h.capture.switchCamera(),true);assert.equal(h.requests.at(-1).video.facingMode.ideal,'environment');
  assert.equal(h.video.style.transform,'none');assert.equal(h.video.attrs['data-preview-mirrored'],'false');
  assert.equal(h.tracks.filter(t=>!t.stopped).length,1);
 });
});
test('a new stream always replaces the previous flip before its first frame is shown',async()=>{
 await cameraHarness(async h=>{
  await h.capture.start('attendance',{facingMode:'user'});assert.equal(h.video.style.transform,'scaleX(-1)');
  h.capture.stop(); // the finished camera keeps its last state; only a new start decides the next one
  assert.equal(h.video.style.transform,'scaleX(-1)');
  await h.capture.start('attendance',{facingMode:'environment'});assert.equal(h.video.style.transform,'none');assert.equal(h.video.attrs['data-preview-mirrored'],'false');
 },{getUserMedia:(constraints,stream)=>stream(constraints.video.facingMode?.ideal==='environment'?'environment':'user')});
});
test('without facing metadata switching selects another enumerated device',async()=>{
 await cameraHarness(async h=>{await h.capture.start('attendance');await h.capture.switchCamera();assert.equal(h.requests[1].video.deviceId.ideal,'user');assert.equal(h.highestLive,1);},{getUserMedia:(constraints,stream)=>stream('',constraints.video.deviceId?.ideal || 'environment')});
});
test('normal overlay draws one stable circle without any rectangular detection box',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');h.capture.draw({x:10,y:20,width:100,height:120});assert.equal(h.rects.length,0);assert.deepEqual(h.arcs.at(-1),[200,150,120,0,2*Math.PI]);
  h.video.videoWidth=480;h.video.videoHeight=640;h.capture.draw(null);assert.equal(h.arcs.at(-1)[0],200);assert.equal(h.arcs.at(-1)[1],150);assert.equal(h.arcs.at(-1)[2],90);});
});
test('explicit development outline maps confirmed front mirroring correctly',async()=>{
 await cameraHarness(async h=>{await h.capture.start('enroll');h.capture.config={...config,diagnostics:true};h.capture.mirrored=true;h.capture.draw({x:10,y:20,width:100,height:120});assert.deepEqual(h.rects.at(-1),[331.25,12.5,62.5,75]);});
});
test('guide positioning uses detected bounds and eye/nose/mouth landmarks as advisory cues',()=>{
 assert.deepEqual(faceGuide(640,480),{x:320,y:240,radius:192});
 const box={x:260,y:140,width:120,height:170},points=[[280,190],[340,190],[320,225],[290,270],[340,270]];
 assert.equal(positioningHint(box,points,640,480),'');assert.equal(positioningHint({...box,x:0},points,640,480),'Center face');
 assert.equal(positioningHint({...box,y:-1},points,640,480),'Move slightly back');assert.equal(positioningHint(box,[...points.slice(0,4),[340,481]],640,480),'Move slightly back');
});
test('progress counts suitable observations, preserves accepted progress and verifies identity after track loss',async()=>{
 await cameraHarness(async h=>{
  const progress=[];h.capture.callbacks.progress=p=>progress.push(p);h.capture.callbacks.batch=async()=>false;
  h.capture.config={...config,capture_continuity:{same_min:.746782791039185,different_max:.6513243536866755,observations:3}};
  await h.capture.start('enroll');const vector=Array(128).fill(0);vector[0]=1;const box={x:270,y:180,width:100,height:100};
  await h.capture.observe({face_count:1,box,embedding:vector,quality_reason:'SUITABLE'});assert.equal(progress.at(-1),.2);
  await h.capture.observe({face_count:1,box,quality_reason:'BLUR'});assert.equal(progress.at(-1),.2);assert.equal(h.capture.samples.length,1);
  await h.capture.observe({face_count:0,quality_reason:'NO_FACE'});assert.equal(progress.at(-1),.2);assert.equal(h.capture.samples.length,1);
  for(let i=0;i<6;i++){h.capture.lastSample=0;await h.capture.observe({face_count:1,box,embedding:vector,quality_reason:'SUITABLE'});}
  assert.deepEqual(progress.slice(-5),[.2,.4,.6,.8,1]);assert.equal(h.capture.active,false);
 });
});
