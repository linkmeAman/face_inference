const {test,expect}=require('@playwright/test');const fs=require('fs');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('TEST actual automatic capture persists and recognizes after refresh; logs correlate; no writes or review',async({page,context})=>{
 page.on('dialog',d=>d.accept());const {csrf}=await(await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}})).json();const headers={'X-Face-CSRF':csrf};
 for(const id of [1,2])await context.request.post('/fixture-pending-api?action=calibration_delete',{headers,data:{person_id:id,idempotency_key:'testing_browser_clear_'+id+Date.now()}});
 const before=await(await context.request.get('/fixture-counts')).json();await page.goto('/tests/browser-face/embedded.php?testing=1');await expect(page.locator('#enroll-1')).toBeVisible();await page.locator('#enroll-1').click();
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved',{timeout:25000});expect(await page.evaluate(()=>testFlow.state.operating_mode)).toBe('TEST');
 const opts=(await(await context.request.get('/fixture-test-api?action=options')).json()).data;expect(opts.persons.find(p=>Number(p.id)===1).enrollment_state).toBe('PENDING');expect(opts.persons.find(p=>Number(p.id)===1).test_ready).toBe(true);
 await page.getByRole('button',{name:'Close',exact:true}).click();await page.reload();await expect(page.locator('#scan')).toBeVisible();await page.locator('#scan').click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Public A recognized',{timeout:25000});
 expect(await page.evaluate(()=>testFlow.state.draft.length)).toBe(0);await expect(page.getByRole('button',{name:'Finish Scanning',exact:true})).toHaveCount(0);await expect(page.getByRole('button',{name:'Confirm Attendance',exact:true})).toHaveCount(0);expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 await expect.poll(()=>page.evaluate(()=>testFlow.capture.waitForDeparture)).toBe(true);await page.waitForTimeout(1500);expect(await page.evaluate(()=>testFlow.capture.active && testFlow.capture.worker!==null)).toBe(true);
 await page.getByRole('button',{name:'Close',exact:true}).click();await page.locator('#enroll-2').click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face already registered to Public A',{timeout:25000});
 await expect(page.locator('.browser-face-camera')).toBeHidden();await expect(page.locator('.browser-face-progress-ring')).toBeHidden();
 expect(await page.evaluate(()=>testFlow.capture.worker===null && testFlow.capture.stream===null && testFlow.capture.samples.length===0 && testFlow.state.progress===0 && testFlow.state.guide===null)).toBe(true);
 const rejectedSession=await page.evaluate(()=>testFlow.context.capture_session);
 await page.getByRole('button',{name:'Retry',exact:true}).click();await expect(page.locator('.browser-face-camera')).toBeVisible();expect(await page.evaluate(()=>testFlow.context.person_id)).toBe(2);expect(await page.evaluate(()=>testFlow.context.capture_session)).not.toBe(rejectedSession);
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face already registered to Public A',{timeout:25000});
 await expect(page.locator('.browser-face-camera')).toBeHidden();await expect(page.locator('.browser-face-progress-ring')).toBeHidden();
 await page.getByRole('button',{name:'Close',exact:true}).click();await page.locator('#scan').click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Public A recognized',{timeout:25000});await page.getByRole('button',{name:'Close',exact:true}).click();expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 // A genuine rejected backend request produces a sanitized, traceable event.
 const failure=await context.request.post('/fixture-test-api?action=enroll',{headers,data:{person_id:1,model_version:'incompatible',preprocessing_version:'incompatible',idempotency_key:'testing_browser_failed01'}});expect(failure.ok()).toBe(false);
 const entries=fs.readFileSync(process.env.FACE_WEB_EVENT_LOG,'utf8').trim().split('\n').map(JSON.parse);for(const event of ['enrollment_saved','enrollment_duplicate','recognition_matched','enrollment_failed'])expect(entries.some(e=>e.event===event)).toBe(true);
 const saved=entries.find(e=>e.event==='enrollment_saved' && e.tenant===process.env.FACE_BROWSER_TEST_DB && e.observation_count===5);expect(saved).toBeTruthy();expect(entries.some(e=>e.event==='capture_completed' && e.request_id===saved.request_id)).toBe(true);expect(saved.persistence_succeeded).toBe(true);expect(saved.enrollment_id).toBeGreaterThan(0);
 expect(entries.some(e=>e.event==='enrollment_failed' && e.request_id==='testing_browser_failed01')).toBe(true);
 for(const id of [1,2])await context.request.post('/fixture-pending-api?action=calibration_delete',{headers,data:{person_id:id,idempotency_key:'testing_browser_cleanup_'+id+Date.now()}});
});
