const {test,expect}=require('@playwright/test');
// Real YuNet/SFace in the real worker, real AutomaticCapture. Mirroring is a display-only
// transform, so the same camera frames must give the same enrollment template whichever
// side the camera reports (or when it reports nothing at all).
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('front, rear and unreported facing give the same template; only the displayed video is flipped',async({page})=>{
 await page.goto('/tests/browser-face/inference.html');
 const result=await page.evaluate(async()=>{
  const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');
  const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
  const get=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
  async function run(report){
   navigator.mediaDevices.getUserMedia=async constraints=>{const stream=await get(constraints),track=stream.getVideoTracks()[0],real=track.getSettings.bind(track);track.getSettings=()=>{const s=real();if(report)s.facingMode=report;else delete s.facingMode;return s;};return stream;};
   const holder=document.createElement('div');holder.style.cssText='position:relative;width:480px;height:360px';
   const video=document.createElement('video'),canvas=document.createElement('canvas');for(const element of [video,canvas])element.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:cover';holder.append(video,canvas);document.body.append(holder);
   let finish,fail;const done=new Promise((resolve,reject)=>{finish=resolve;fail=reject;});let mirrored=null,transform=null;
   const capture=new AutomaticCapture(video,canvas,config,{status(){},progress(){},error:e=>fail(Error(e)),batch:batch=>{mirrored=capture.mirrored;transform=video.style.transform;finish(batch);return false;}});
   await capture.start('enroll');const batch=await Promise.race([done,new Promise((_,reject)=>setTimeout(()=>reject(Error('capture timed out')),40000))]);
   const out={template:batch.template,observations:batch.observations.length,mirrored,transform};capture.stop();holder.remove();return out;
  }
  const front=await run('user'),rear=await run('environment'),unreported=await run(null);
  const cosine=(a,b)=>a.reduce((s,x,i)=>s+x*b[i],0)/Math.hypot(...a)/Math.hypot(...b);
  return {front:{mirrored:front.mirrored,transform:front.transform,n:front.observations},rear:{mirrored:rear.mirrored,transform:rear.transform,n:rear.observations},unreported:{mirrored:unreported.mirrored,transform:unreported.transform,n:unreported.observations},frontRear:cosine(front.template,rear.template),frontUnreported:cosine(front.template,unreported.template),norm:Math.hypot(...front.template)};
 });
 expect(result.front).toEqual({mirrored:true,transform:'scaleX(-1)',n:5});
 expect(result.rear).toEqual({mirrored:false,transform:'none',n:5});
 expect(result.unreported).toEqual({mirrored:true,transform:'scaleX(-1)',n:5});
 expect(result.frontRear).toBeGreaterThan(0.999);expect(result.frontUnreported).toBeGreaterThan(0.999);expect(result.norm).toBeCloseTo(1,6);
});
