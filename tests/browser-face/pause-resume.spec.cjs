const {test,expect}=require('@playwright/test');
const fs=require('fs');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test.afterEach(async({context})=>{
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});const {csrf}=await login.json();
 for(const id of [1,2])await context.request.post('/fixture-pending-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:id,idempotency_key:'pause_cleanup_'+id+Date.now()}});
});
async function openControlledCamera(page,context){
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 for(const id of [1,2])await context.request.post('/fixture-pending-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:id,idempotency_key:'pause_clear_'+id+Date.now()}});
 const images={a:'/tmp/ccc-public-mixed-lena.png',b:'/tmp/ccc-public-mixed-obama.png',blank:'/tmp/ccc-public-blank.jpg'};
 await page.route('**/pause-image-*',route=>route.fulfill({contentType:'image/png',body:fs.readFileSync(images[route.request().url().split('pause-image-')[1]])}));
 await page.goto('/tests/browser-face/embedded.php?testing=1');await expect(page.locator('#enroll-1')).toBeVisible();
 await page.evaluate(()=>{
  const create=testFlow.createCapture.bind(testFlow);testFlow.createCapture=generation=>{create(generation);testFlow.capture.schedule=()=>{};};
  window.progressUpdates=[];window.reidentifications=[];const change=testFlow.change;testFlow.change=state=>{if(progressUpdates.at(-1)!==state.progress)progressUpdates.push(state.progress);change(state);};
 });
 await page.locator('#enroll-1').click();await expect.poll(()=>page.evaluate(()=>testFlow.capture?.engineReady),{timeout:20000}).toBe(true);
 await page.evaluate(()=>{const metrics=testFlow.capture.callbacks.metrics;testFlow.capture.callbacks.metrics=data=>{if(data.reidentification_ms!=null)reidentifications.push(data.reidentification_ms);metrics(data);};window.measureStart=performance.now();window.heapStart=performance.memory?.usedJSHeapSize;});
 return csrf;
}
async function frame(page,image,transform){
 await page.evaluate(async({image,transform})=>{
  const capture=testFlow.capture;let bitmap=await createImageBitmap(await(await fetch('/pause-image-'+image)).blob());
  if(transform){const canvas=new OffscreenCanvas(640,480),ctx=canvas.getContext('2d');ctx.fillStyle='black';ctx.fillRect(0,0,640,480);if(transform==='outside')ctx.drawImage(bitmap,-220,0);else{ctx.drawImage(bitmap,0,0);ctx.fillStyle='rgba(0,0,0,.98)';ctx.fillRect(0,0,640,480);}bitmap.close();bitmap=await createImageBitmap(canvas);}
  const geometry=capture.draw(null);capture.busy=true;capture.lastSample=0;capture.jobStarted=performance.now();capture.jobRevision=capture.selectionRevision || 0;
  capture.worker.postMessage({type:'frame',bitmap,epoch:capture.epoch,guide:geometry.source,guide_key:capture.guideKey,recognize:true},[bitmap]);
 },{image,transform});
 await expect.poll(()=>page.evaluate(()=>testFlow.capture.busy),{timeout:10000}).toBe(false);
}
test('real YuNet/SFace pauses 3/5, rejects another public identity and resumes original without resetting ring',async({page,context})=>{
 await openControlledCamera(page,context);let writes=0;await page.route('**/fixture-test-api?action=enroll',async route=>{writes++;await route.continue();});
 for(let i=0;i<3;i++)await frame(page,'a');expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);
 for(const [image,transform] of [['blank'],['a','outside'],['a','dark']]){await frame(page,image,transform);expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);expect(writes).toBe(0);}
 await expect(page.locator('.browser-face-progress-ring')).toBeVisible();
 for(let i=0;i<3;i++)await frame(page,'b');await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Different face detected. Return to the original face or tap Retry.');
 expect(await page.evaluate(()=>testFlow.capture.samples.length)).toBe(3);expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);expect(writes).toBe(0);
 await frame(page,'blank');for(let i=0;i<2;i++){await frame(page,'a');expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);}await frame(page,'a');expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.8);
 await frame(page,'a');await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved');expect(writes).toBe(1);
 const result=await page.evaluate(()=>({scope:'simulated public-photo frames; actual YuNet/SFace and PHP/MySQL',progress:progressUpdates,completion_ms:performance.now()-measureStart,reidentification_ms:reidentifications,heap_start:heapStart,heap_end:performance.memory?.usedJSHeapSize,resources_closed:!testFlow.capture.worker && !testFlow.capture.stream && testFlow.capture.samples.length===0 && testFlow.capture.returning.length===0}));
 expect(result.progress.filter(p=>p>0)).toEqual([.2,.4,.6,.8,1]);expect(result.resources_closed).toBe(true);console.log(JSON.stringify(result));
 await page.getByRole('button',{name:'Close',exact:true}).click();
});
test('real worker recovery retains 60%; original must reverify before fresh reference reaches backend',async({page,context})=>{
 await openControlledCamera(page,context);for(let i=0;i<3;i++)await frame(page,'a');
 await page.evaluate(()=>{window.oldWorker=testFlow.capture.worker;testFlow.capture.worker.onerror();});expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);
 await expect.poll(()=>page.evaluate(()=>testFlow.capture.worker!==oldWorker && testFlow.capture.engineReady && !testFlow.capture.recovering),{timeout:20000}).toBe(true);
 expect(await page.evaluate(()=>testFlow.capture.samples.length)).toBe(3);
 for(let i=0;i<2;i++){await frame(page,'a');expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.6);}await frame(page,'a');expect(await page.evaluate(()=>testFlow.state.progress)).toBe(.8);
 await frame(page,'a');await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved');
 await page.getByRole('button',{name:'Close',exact:true}).click();await expect(page.locator('#enroll-1')).toHaveText('Update Face');
 const before=(await(await context.request.get('/fixture-test-api?action=enrollment_status')).json()).data.persons.find(p=>Number(p.member_id)===1).calibration_id;
 await page.locator('#enroll-2').click();await expect.poll(()=>page.evaluate(()=>testFlow.capture.engineReady),{timeout:20000}).toBe(true);expect(await page.evaluate(()=>testFlow.capture.samples.length)).toBe(0);
 for(let i=0;i<5;i++)await frame(page,'a');await expect(page.locator('.browser-face-feedback p:first-child')).toContainText('Face already registered');
 const after=(await(await context.request.get('/fixture-test-api?action=enrollment_status')).json()).data.persons.find(p=>Number(p.member_id)===1).calibration_id;expect(after).toBe(before);
 expect(await page.evaluate(()=>testFlow.capture.samples.length===0 && testFlow.capture.returning.length===0)).toBe(true);
});
