// Actual elapsed-time real-model endurance; never a physical-device claim.
// Static local PHP server only. No production/database/API writes.
const {chromium}=require('@playwright/test');
const fs=require('fs'),assert=require('node:assert/strict');
const duration=Number(process.env.FACE_SOAK_MS || 30*60*1000);
const wait=ms=>new Promise(r=>setTimeout(r,ms));
(async()=>{
 const browser=await chromium.launch({headless:true,args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-mixed-camera.y4m']});
 const context=await browser.newContext({permissions:['camera']});const page=await context.newPage();
 const errors=[];page.on('pageerror',e=>errors.push(e.message));
 const cdp=await browser.newBrowserCDPSession();
 const rss=async()=>{const data=await cdp.send('SystemInfo.getProcessInfo');return data.processInfo.filter(p=>p.type==='renderer').reduce((total,p)=>{try{return total+Number(fs.readFileSync('/proc/'+p.id+'/status','utf8').match(/^VmRSS:\s+(\d+)/m)[1]);}catch{return total;}},0);};
 try{
 await page.goto(process.env.FACE_SOAK_URL || 'http://127.0.0.1:8138/tests/browser-face/inference.html');
 await page.evaluate(async()=>{
  const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
  const NativeWorker=Worker;window.soakWorkers=[];window.Worker=class extends NativeWorker{constructor(...args){super(...args);soakWorkers.push(this);}terminate(){this.closed=true;super.terminate();}};
  const gum=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);window.soakTracks=[];
  navigator.mediaDevices.getUserMedia=async constraints=>{const stream=await gum(constraints);soakTracks.push(...stream.getTracks());return stream;};
  const video=document.createElement('video'),canvas=document.createElement('canvas');video.muted=true;video.playsInline=true;
  video.style.cssText='width:640px;height:480px';canvas.style.cssText='width:640px;height:480px';document.body.append(video,canvas);
  window.soakNetworkPending=false;window.soak={batches:0,frames:0,no_face:0,held:0,failures:[],latencies:[],recognitions:[],max_in_flight:0,max_workers:0,max_tracks:0,events:[],health:null};
  window.capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},batch(){soak.batches++;return soakNetworkPending?{pending:true}:{waitForDeparture:true};},
   error:e=>soak.failures.push(e),metrics:d=>{if(d.detection_ms){soak.frames++;soak.latencies.push(d.detection_ms);if(soak.latencies.length>512)soak.latencies.shift();}if(d.inference_ms){soak.recognitions.push(d.inference_ms);if(soak.recognitions.length>512)soak.recognitions.shift();}if(d.quality_reason==='NO_FACE')soak.no_face++;if(d.quality_reason==='TRACK_HELD')soak.held++;},
   health:h=>{soak.health=h;soak.max_in_flight=Math.max(soak.max_in_flight,h.in_flight);soak.max_workers=Math.max(soak.max_workers,soakWorkers.filter(w=>!w.closed).length);soak.max_tracks=Math.max(soak.max_tracks,soakTracks.filter(t=>t.readyState!=='ended').length);if(h.event){soak.events.push({event:h.event,elapsed_ms:h.elapsed_ms});if(soak.events.length>32)soak.events.shift();}}});
  const sourceHash=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(AutomaticCapture.toString()));soak.capture_source_sha256=Array.from(new Uint8Array(sourceHash),v=>v.toString(16).padStart(2,'0')).join('');
  await capture.start('attendance');
 });
 const started=Date.now(),samples=[],injected=new Set();let lastFrames=0;
 while(Date.now()-started<duration){
  await wait(Math.min(60000,duration-(Date.now()-started)));
  const elapsed=Date.now()-started;
  const stats=await page.evaluate(()=>({active:capture.active,batches:soak.batches,frames:soak.frames,no_face:soak.no_face,held:soak.held,failures:soak.failures,health:soak.health,workers:soakWorkers.filter(w=>!w.closed).length,tracks:soakTracks.filter(t=>t.readyState!=='ended').length,heap:performance.memory?.usedJSHeapSize || null,max_in_flight:soak.max_in_flight,max_workers:soak.max_workers,max_tracks:soak.max_tracks}));
  stats.elapsed_ms=elapsed;stats.renderer_rss_kib=await rss();samples.push(stats);console.log(JSON.stringify(stats));
  assert.equal(stats.active,true);assert.deepEqual(stats.failures,[]);assert.equal(stats.workers,1);assert.equal(stats.tracks,1);assert.ok(stats.frames>lastFrames,'detector must keep progressing');assert.ok(stats.max_in_flight<=1);assert.ok(stats.max_workers<=1);assert.ok(stats.max_tracks<=1);lastFrames=stats.frames;
  if(elapsed>120000 && !injected.has('worker')){injected.add('worker');await page.evaluate(()=>{capture.worker.terminate();});} // No error event: watchdog must detect missing reply.
  if(elapsed>240000 && !injected.has('track')){injected.add('track');await page.evaluate(()=>capture.stream.getTracks()[0].stop());}
  if(elapsed>480000 && !injected.has('visibility')){injected.add('visibility');await page.evaluate(()=>capture.setVisibility(true));await wait(5000);await page.evaluate(()=>capture.setVisibility(false));}
  if(elapsed>600000 && !injected.has('switch')){injected.add('switch');await page.evaluate(async()=>{capture.cameras=[{deviceId:'fake1'},{deviceId:'fake2'}];capture.canSwitch=true;for(let i=0;i<3;i++){capture.canSwitch=true;await capture.switchCamera();}});}
  if(elapsed>720000 && !injected.has('network_hold')){
   injected.add('network_hold');await page.evaluate(()=>{soakNetworkPending=true;});
   for(let i=0;i<20 && !await page.evaluate(()=>capture.backendPending);i++)await wait(1000);
   assert.equal(await page.evaluate(()=>capture.active && capture.backendPending && capture.stream.getTracks()[0].readyState==='live'),true);
   await wait(5000);assert.equal(await page.evaluate(()=>performance.now()-capture.health.last_video_frame<2500),true);
   await page.evaluate(()=>{soakNetworkPending=false;capture.resumeAfterRequest();});
  }
  if(elapsed>900000 && !injected.has('reopen')){injected.add('reopen');await page.evaluate(async()=>{for(let i=0;i<3;i++){capture.stop();await capture.start('attendance');}});}
  if(elapsed>1200000 && !injected.has('mute')){injected.add('mute');await page.evaluate(()=>capture.stream.getTracks()[0].dispatchEvent(new Event('mute')));await wait(1000);await page.evaluate(()=>capture.stream.getTracks()[0].dispatchEvent(new Event('unmute')));}
  if(elapsed>1320000 && !injected.has('video_pause')){
   injected.add('video_pause');await page.evaluate(()=>{window.videoRecoveryStream=capture.stream;window.videoRecoveryWorker=capture.worker;capture.video.pause();});
   await wait(20000);assert.equal(await page.evaluate(()=>!capture.video.paused && capture.stream===videoRecoveryStream && capture.worker===videoRecoveryWorker),true);
  }
 }
 const final=await page.evaluate(()=>{capture.stop();return {capture_source_sha256:soak.capture_source_sha256,batches:soak.batches,frames:soak.frames,no_face:soak.no_face,held:soak.held,events:soak.events,latencies:soak.latencies,recognitions:soak.recognitions,workers:soakWorkers.filter(w=>!w.closed).length,tracks:soakTracks.filter(t=>t.readyState!=='ended').length,in_flight:capture.busy?1:0,frame_callback:capture.frameCallback,failures:soak.failures};});
 assert.equal(final.workers,0);assert.equal(final.tracks,0);assert.equal(final.in_flight,0);assert.equal(final.frame_callback,null);assert.deepEqual(errors,[]);
 const stable=samples.filter(s=>s.elapsed_ms>300000),lo=Math.min(...stable.map(s=>s.renderer_rss_kib)),hi=Math.max(...stable.map(s=>s.renderer_rss_kib));
 assert.ok(hi-lo<128*1024,'renderer RSS growth range must remain bounded (<128MiB), including shared pages');
 const pct=(v,p)=>v.slice().sort((a,b)=>a-b)[Math.floor((v.length-1)*p)];
 const report={scope:'REAL ELAPSED simulated public-photo camera, actual YuNet/SFace/WASM; no physical camera or backend writes',duration_ms:Date.now()-started,samples,final:{...final,latencies:undefined,recognitions:undefined},detection_p50_ms:pct(final.latencies,.5),detection_p95_ms:pct(final.latencies,.95),inference_p50_ms:pct(final.recognitions,.5),inference_p95_ms:pct(final.recognitions,.95),renderer_rss_range_kib:hi-lo,passed:true};
 fs.writeFileSync('/tmp/ccc-face-stability-soak-20261010.json',JSON.stringify(report,null,2),{mode:0o600});console.log(JSON.stringify({result:'PASS',duration_ms:report.duration_ms,batches:final.batches,frames:final.frames,rss_range_kib:hi-lo}));
 }finally{await context.close();await browser.close();}
})().catch(error=>{console.error(error.message);process.exitCode=1;});
