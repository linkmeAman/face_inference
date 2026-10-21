const {test,expect}=require('@playwright/test');
 test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
 test('simulated mobile camera switching keeps real model capture and one circular guide',async({page})=>{
  await page.goto('/tests/browser-face/inference.html');
  await page.evaluate(async()=>{
   const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
   const holder=document.createElement('div');holder.style.cssText='position:relative;width:100%;max-width:560px;aspect-ratio:4/3';
   const video=document.createElement('video'),canvas=document.createElement('canvas');for(const element of [video,canvas])element.style.cssText='position:absolute;inset:0;width:100%;height:100%;object-fit:contain';holder.append(video,canvas);document.body.append(holder);
   // Chromium's file camera exposes one device. Simulate only mobile device
   // enumeration/settings; every acquired stream still uses actual browser
   // video and real YuNet/SFace inference. No identity result is supplied.
   window.switchRequests=[];const get=navigator.mediaDevices.getUserMedia.bind(navigator.mediaDevices);
   navigator.mediaDevices.enumerateDevices=async()=>['environment','user'].map(deviceId=>({kind:'videoinput',deviceId}));
   navigator.mediaDevices.getUserMedia=async constraints=>{
    switchRequests.push(constraints);const stream=await get(constraints),track=stream.getVideoTracks()[0],settings=track.getSettings.bind(track),facingMode=constraints.video.facingMode?.ideal || 'user';
    track.getSettings=()=>({...settings(),facingMode,deviceId:facingMode});return stream;
   };
   window.switchBatches=[];window.switchErrors=[];window.switchProgress=0;window.guideArcs=0;window.greenRectangles=0;
   const ctx=canvas.getContext('2d'),arc=ctx.arc.bind(ctx),rect=ctx.strokeRect.bind(ctx);ctx.arc=(...args)=>{guideArcs++;return arc(...args);};ctx.strokeRect=(...args)=>{greenRectangles++;return rect(...args);};
   window.switchCapture=new AutomaticCapture(video,canvas,config,{status(){},progress:p=>{switchProgress=p;},batch:b=>{switchBatches.push(b);},error:e=>{switchErrors.push(e);}});await switchCapture.start('enroll');
   window.originalCameraTracks=switchCapture.stream.getTracks();window.originalWorker=switchCapture.worker;window.originalCameraId=switchCapture.cameraSelection.deviceId;
  });
  await expect.poll(()=>page.evaluate(()=>switchProgress)).toBeGreaterThan(0);
  expect(await page.evaluate(()=>switchCapture.canSwitch)).toBe(true);
  await page.evaluate(async()=>{await switchCapture.switchCamera();});
  expect(await page.evaluate(()=>originalCameraTracks.every(track=>track.readyState==='ended'))).toBe(true);
  expect(await page.evaluate(()=>switchCapture.worker!==originalWorker)).toBe(true);
  expect(await page.evaluate(()=>switchCapture.cameraSelection.deviceId!==originalCameraId)).toBe(true);
  expect(await page.evaluate(()=>switchCapture.samples.length)).toBe(0);
  await expect.poll(()=>page.evaluate(()=>switchBatches.length),{timeout:20000}).toBe(1);
  const result=await page.evaluate(()=>({requests:switchRequests,guideArcs,greenRectangles,count:switchBatches[0].observations.length,norm:Math.hypot(...switchBatches[0].template),active:switchCapture.active,errors:switchErrors,mirroring:switchCapture.video.style.transform,facing:switchCapture.cameraSelection.facingMode}));
  expect(result.requests[0].video.facingMode).toEqual({ideal:'environment'});expect(result.guideArcs).toBeGreaterThan(0);expect(result.greenRectangles).toBe(0);expect(result.count).toBe(5);expect(result.norm).toBeCloseTo(1,6);expect(result.active).toBe(false);expect(result.errors).toEqual([]);expect(result.mirroring).toBe(result.facing==='user'?'scaleX(-1)':'none');
 });
