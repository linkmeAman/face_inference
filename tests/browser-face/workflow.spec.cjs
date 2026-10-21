const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream',
    '--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('real camera model and authenticated PHP/MySQL enrollment, duplicate, attendance and retry',async({page,context})=>{
 page.on('dialog',dialog=>dialog.accept());
    if(!process.env.FACE_BROWSER_TEST_KEY) throw Error('Isolated integration fixture is required');
    const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});
    expect(login.ok()).toBe(true);const {csrf}=await login.json();
    await page.route('**/routes/browserFaceRoute.php?**',route=>{
        const url=new URL(route.request().url());url.pathname='/fixture-api';return route.continue({url:url.href});
    });
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    await page.goto('/tests/browser-face/embedded.php');
    await expect(page.locator('#enroll-1')).toBeVisible();
    await page.locator('#enroll-1').click();
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face enrolled',{timeout:20000});
    await expect(page.locator('#row-101')).toContainText('Face ready');
    await page.getByRole('button',{name:'Close'}).click();
    await page.locator('#enroll-2').click();
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face already registered to Public A',{timeout:20000});
    await expect.poll(async()=>(await(await context.request.get('/fixture-counts')).json()).templates).toBe(1);
    await page.getByRole('button',{name:'Retry',exact:true}).click();
    await expect.poll(()=>page.evaluate(()=>testFlow.capture.active)).toBe(true);
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face already registered to Public A',{timeout:20000});
    await page.getByRole('button',{name:'Close'}).click();
    // Approved LIVE writes automatically. Lose the committed response and
    // retry the exact key, with no routine review/confirmation controls.
    let interrupted=true;const backendTimes=[];
    await page.route('**/fixture-api?action=attendance',async route=>{
        const start=performance.now(),response=await route.fetch();backendTimes.push(performance.now()-start);
        if(interrupted)await route.abort('failed');else await route.fulfill({response});
    });
    await page.locator('#scan').click();
    await expect(page.getByRole('button',{name:'Confirm Attendance',exact:true})).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Finish Scanning',exact:true})).toHaveCount(0);
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Connection lost',{timeout:25000});
    const pendingKey=await page.evaluate(()=>testFlow.pending.payload.idempotency_key);
    const committed=await(await context.request.get('/fixture-counts')).json();expect(committed.attendance).toBe(1);expect(committed.events).toBe(1);expect(committed.deductions).toBe(1);
    interrupted=false;await page.getByRole('button',{name:'Retry',exact:true}).click();
    await expect(page.locator('#row-101')).toContainText('Recorded');await expect(page.locator('#row-101')).toContainText('Used 1');
    expect(await page.evaluate(()=>testFlow.pending===null && testFlow.capture.active && testFlow.state.draft.length===0)).toBe(true);expect(pendingKey).toBeTruthy();
    await page.getByRole('button',{name:'Close',exact:true}).click();await page.locator('#scan').click();
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Attendance already marked.',{timeout:20000});
    expect(await(await context.request.get('/fixture-counts')).json()).toEqual(committed);
    expect(errors).toEqual([]);
    expect(page.url()).toContain('/tests/browser-face/embedded.php');
    const forbidden=await context.request.post('/fixture-api?action=delete',{data:{person_id:1,idempotency_key:'unauthorized_csrf_0001'}});
    expect(forbidden.ok()).toBe(false);expect((await forbidden.json()).error_msg).toBe('CSRF_REQUIRED');
    const wrongBranch=await context.request.post('/fixture-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:4,idempotency_key:'unauthorized_person_01'}});
    expect(wrongBranch.ok()).toBe(false);
    await page.getByRole('button',{name:'Close'}).click();
    // Run inference on independent public fixtures through the same WASM worker.
    await page.goto('/tests/browser-face/inference.html');
    for(const person of ['obama','biden'])await page.route('**/'+person+'.jpg',route=>route.fulfill({contentType:'image/jpeg',body:require('fs').readFileSync('/tmp/ccc-public-'+person+'.jpg')}));
    const obama=await page.evaluate(()=>runInference('/obama.jpg'));
    const biden=await page.evaluate(()=>runInference('/biden.jpg'));
    expect(obama.embedding).toHaveLength(128);expect(biden.embedding).toHaveLength(128);
    const config=await(await context.request.get('/fixture-api?action=config')).json();
    const vectors={model_version:config.data.model_version,preprocessing_version:config.data.preprocessing_version};
    const enrollment=(person_id,template,idempotency_key)=>({...vectors,person_id,template,idempotency_key,consent:true,
        observations:[template,template,template,template,template],capture_session:idempotency_key,
        device:'camera:synthetic-integration-fixture',lighting:'measured-normal'});

    const second=await context.browser().newContext();
    const secondLogin=await second.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});
    const secondCsrf=(await secondLogin.json()).csrf;
    const simultaneous=await Promise.all([
        context.request.post('/fixture-api?action=enroll',{headers:{'X-Face-CSRF':csrf},data:enrollment(2,obama.embedding,'concurrent_enroll_a_01')}),
        second.request.post('/fixture-api?action=enroll',{headers:{'X-Face-CSRF':secondCsrf},data:enrollment(3,obama.embedding,'concurrent_enroll_b_01')})
    ]);
    const outcomes=await Promise.all(simultaneous.map(response=>response.json()));
    expect(outcomes.map(result=>result.data.state).sort()).toEqual(['ACTIVE','REJECTED_DUPLICATE']);
    if(outcomes.find(r=>r.data.state==='ACTIVE').data.person_id===3){
        await context.request.post('/fixture-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:3,idempotency_key:'fixture_delete_race_3'}});
        const eligible=await context.request.post('/fixture-api?action=enroll',{headers:{'X-Face-CSRF':csrf},data:enrollment(2,obama.embedding,'fixture_eligible_obama')});
        expect((await eligible.json()).data.state).toBe('ACTIVE');
    }
    const opened=await context.request.post('/fixture-api?action=open',{headers:{'X-Face-CSRF':csrf},data:{batch_id:11,attendance_date:new Date().toLocaleDateString('en-CA'),attendance_session_id:8,selected_time:'12:00:00',idempotency_key:'real_unknown_session1'}});
    const open=(await opened.json()).data;
    const unknown=await context.request.post('/fixture-api?action=attendance',{headers:{'X-Face-CSRF':csrf},data:{...vectors,scan_id:open.scan_id,scan_token:open.scan_token,observations:[biden.embedding,biden.embedding,biden.embedding],idempotency_key:'real_unknown_batch_01'}});
    expect((await unknown.json()).data.state).toBe('UNKNOWN');
    const counts=await(await context.request.get('/fixture-counts')).json();expect(counts.templates).toBe(2);expect(counts.attendance).toBe(1);
    await second.close();
    console.log(JSON.stringify({real_backend_attendance_round_trip_ms:backendTimes}));
});
