const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-mixed-camera.y4m']}});
test('two distinct real-model identities automatically mark LIVE attendance sequentially',async({page,context})=>{
 page.on('dialog',dialog=>dialog.accept());
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);
 const {csrf}=await login.json();
 await page.goto('/tests/browser-face/inference.html');
 for(const person of ['lena','obama'])await page.route('**/mixed-'+person+'.png',route=>route.fulfill({contentType:'image/png',body:require('fs').readFileSync('/tmp/ccc-public-mixed-'+person+'.png')}));
 const references=[];
 const config=(await(await context.request.get('/fixture-api?action=config')).json()).data;
 const obamaReference=await page.evaluate(()=>runInference('/mixed-obama.png'));
 await page.evaluate(async()=>{
    const {AutomaticCapture}=await import('/pf/js/browser-face/capture.mjs');const config=await(await fetch('/pf/js/browser-face/contract.json')).json();
    const video=document.createElement('video'),canvas=document.createElement('canvas');document.body.append(video,canvas);window.prepared=[];
    window.preparation=new AutomaticCapture(video,canvas,config,{status(){},progress(){},error(error){window.preparationError=error;},batch(batch){prepared.push(batch);}});await preparation.start('attendance');
 });
 await expect.poll(()=>page.evaluate(()=>prepared.length),{timeout:20000}).toBeGreaterThan(0);
 references.push(await page.evaluate(()=>prepared[0].template));
 await expect.poll(()=>page.evaluate(ref=>prepared.find(batch=>Math.min(...batch.observations.map(o=>o.reduce((s,x,i)=>s+x*ref[i],0)))>.9)?.template || null,obamaReference.embedding),{timeout:30000}).not.toBeNull();
 references.push(await page.evaluate(ref=>prepared.find(batch=>Math.min(...batch.observations.map(o=>o.reduce((s,x,i)=>s+x*ref[i],0)))>.9).template,obamaReference.embedding));
 await page.evaluate(()=>preparation.stop());
 // These labels are the known public-photo input sequence, not live evidence.
 // Templates come from actual camera observations, preserving YUV preprocessing.
 for(const [offset,member] of [[0,1],[1,2]]){
    await context.request.post('/fixture-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:member,idempotency_key:'continuous_reset_'+member+Date.now()}});
    const captureKey='continuous_real_enroll'+member+Date.now();
    const enrolled=await context.request.post('/fixture-api?action=enroll',{headers:{'X-Face-CSRF':csrf},data:{person_id:member,template:references[offset],model_version:config.model_version,preprocessing_version:config.preprocessing_version,idempotency_key:captureKey,capture_session:captureKey,consent:true,observations:Array(5).fill(references[offset]),device:'camera:synthetic-integration-only',lighting:'measured-normal'}});
    expect((await enrolled.json()).data.state).toBe('ACTIVE');
 }
 await page.goto('/tests/browser-face/embedded.php');await expect(page.locator('#enroll-1')).toBeVisible();
 await page.locator('#session').selectOption('8');const before=await(await context.request.get('/fixture-counts')).json();
 let requests=0;await page.route('**/fixture-api?action=attendance',async route=>{requests++;const response=await route.fetch();await route.fulfill({response});});
 await page.locator('#scan').click();await expect.poll(()=>page.evaluate(()=>testScope.list.find(r=>r.id===1).attendance_id>0),{timeout:20000}).toBe(true);
 const epoch=await page.evaluate(()=>testFlow.capture.epoch);
 await expect.poll(()=>page.evaluate(()=>testFlow.capture.waitForDeparture)).toBe(true);
 const firstRequests=requests;await page.waitForTimeout(1500);expect(requests).toBe(firstRequests);
 await expect.poll(()=>page.evaluate(()=>testScope.list.find(r=>r.id===2).attendance_id>0),{timeout:35000}).toBe(true);
 expect(await page.evaluate(()=>testFlow.capture.epoch)).toBe(epoch);expect(await page.evaluate(()=>testFlow.capture.active && !!testFlow.capture.worker)).toBe(true);
 // Third public-photo segment is unenrolled and must not create a new record.
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face not recognized',{timeout:25000});
 const afterUnknown=await(await context.request.get('/fixture-counts')).json();expect(afterUnknown.attendance).toBe(before.attendance+2);expect(afterUnknown.events).toBe(before.events+2);
 await expect(page.getByRole('button',{name:'Confirm Attendance',exact:true})).toHaveCount(0);
 const after=await(await context.request.get('/fixture-counts')).json();expect(after.attendance-before.attendance).toBe(2);expect(after.events-before.events).toBe(2);await expect(page.locator('#row-101')).toContainText('Recorded');await expect(page.locator('#row-102')).toContainText('Recorded');
 await page.getByRole('button',{name:'Close'}).click();expect(await page.evaluate(()=>testFlow.capture.worker===null && testFlow.capture.stream===null)).toBe(true);
 expect(page.url()).toContain('/tests/browser-face/embedded.php');
});
