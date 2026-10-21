const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
test.use({permissions:['camera'],launchOptions:{...(process.env.FACE_BROWSER_USE_SHM==='1'?{ignoreDefaultArgs:['--disable-dev-shm-usage']}:{ }),args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('actual attendance.php embeds enrollment, duplicates, scanning and refresh in isolated pilot',async({page,context,browser})=>{
 page.on('dialog',dialog=>dialog.accept());
    const login=await context.request.post('/fixture-pilot-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);
    // Use repository copies of existing UI libraries so CDN availability is not
    // part of this camera regression. No camera/model/API result is mocked.
    let faceAttendanceRequests=0;
    const preparePage=async page=>{
    await page.route('https://**',route=>{
        const url=route.request().url();let file;
        if(url.includes('moment.min.js'))file='/tmp/ccc-attendance-ui-moment-2.13.0.js';
        else if(url.includes('jquery.min.js'))file='/tmp/ccc-attendance-ui-jquery-3.2.1.js';
        else if(url.includes('angular-sanitize.min.js'))file='pf/js/angular-sanitize.min.js';
        else if(url.includes('angular.min.js'))file='pf/js/controllers/angular.min.js';
        if(file)return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.resolve(file))});
        return route.abort(); // No notifications, third-party writes, or widgets.
    });
    await page.route('**/routes/browserFaceRoute.php?**',route=>{const url=new URL(route.request().url());if(url.searchParams.get('action')==='attendance')faceAttendanceRequests++;url.pathname='/fixture-pilot-api';return route.continue({url:url.href});});
    };
    await preparePage(page);
    const errors=[];page.on('pageerror',e=>errors.push(e.message));page.on('console',msg=>{if(msg.type()==='error' && !msg.text().includes('Failed to load resource'))errors.push(msg.text());});
    const writes=[];page.on('request',request=>{if(request.method()==='POST' && request.url().includes('/routes/') && !request.url().includes('settingRoute.php'))writes.push(request.url());});
    await page.goto('/pf/attendance.php?face_pilot=1');
    await expect(page.getByText('Practice attendance', {exact:false})).toBeVisible();
    try{await expect(page.locator('.attendance-focused-table tbody tr')).toHaveCount(3,{timeout:20000});}catch(error){console.log(JSON.stringify({shell_errors:errors,scope:await page.evaluate(()=>{const s=angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope();return {rows:s?.list?.length,batch:s?.batch,stage:s?.attendanceStage};})}));throw error;}
    const first=page.locator('.attendance-focused-table tbody tr').filter({hasText:'Face Camera Test One'});
    const second=page.locator('.attendance-focused-table tbody tr').filter({hasText:'Face Camera Test Two'});
    await page.evaluate(()=>{
        window.normalGuideArcs=0;window.normalDetectionRectangles=0;
        const ctx=document.getElementById('automaticFaceOverlay').getContext('2d'),arc=ctx.arc.bind(ctx),rect=ctx.strokeRect.bind(ctx);
        ctx.arc=(...args)=>{normalGuideArcs++;return arc(...args);};ctx.strokeRect=(...args)=>{normalDetectionRectangles++;return rect(...args);};
    });
    await first.getByRole('button',{name:/Enroll Face$/}).click();
    await expect(first.getByText('Enrolled',{exact:true})).toBeVisible({timeout:20000});
    expect(await page.evaluate(()=>normalGuideArcs)).toBeGreaterThan(0);expect(await page.evaluate(()=>normalDetectionRectangles)).toBe(0);
    expect(await page.locator('#automaticFaceVideo').evaluate(video=>video.muted && video.playsInline)).toBe(true);
    await page.getByRole('button',{name:'Close',exact:true}).click();
    const csrf=(await login.json()).csrf;
    const enabled=await context.request.post('/fixture-pilot-demo-enable',{headers:{'X-Face-CSRF':csrf}});expect(enabled.ok()).toBe(true);expect((await enabled.json()).enabled).toBe(true);
    const ready=await(await context.request.get('/fixture-pilot-api?action=config')).json();expect(ready.data.acceptance_ready).toBe(true);expect(ready.data.pilot_mode).toBe('SINGLE_IDENTITY_LIVE_DEMO');
    // Subsequent camera/API requests now use the actual protected report and
    // normal controller/service, without injected matching readiness/results.
    await second.getByRole('button',{name:/Enroll Face$/}).click();
    await expect(second.getByText('Needs Attention',{exact:true})).toBeVisible({timeout:20000});
    const duplicate=await page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.diagnostics);
    expect(duplicate.decision).toBe('DUPLICATE_MATCH');expect(duplicate.comparison_source).toBe('SUBMITTED_CAPTURE');expect(duplicate.duplicate_check_ran).toBe(true);
    expect(duplicate.candidate_count).toBe(1);expect(duplicate.best_matching_person_id).toBe(623);expect(duplicate.best_similarity).toBeGreaterThanOrEqual(duplicate.duplicate_threshold);
    expect(duplicate.calibration_id).toBeGreaterThan(0);expect(duplicate.capture_session).toBeTruthy();expect(duplicate.request_key).toBeTruthy();expect(duplicate.embedding).toBeUndefined();
    await page.getByRole('button',{name:'Close',exact:true}).click();
    await page.getByRole('button',{name:/Scan Arrivals$/}).click();
    await expect(page.locator('#automaticFaceTitle')).toHaveText('Scan Arrivals');
    await expect(page.locator('.browser-face-instruction')).toHaveText('Position one face inside the circle');
    await expect(page.locator('.browser-face-dialog pre')).toHaveCount(0);
    await expect.poll(()=>page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().list.filter(r=>Number(r.attendance_id)>0).length),{timeout:20000}).toBe(1);
    const beforeConfirm=await(await context.request.get('/fixture-pilot-counts')).json();expect(beforeConfirm.attendance).toBe(1);expect(beforeConfirm.events).toBe(1);
    await expect.poll(()=>page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.diagnostics?.backend_state)).toBe('RECORDED');
    const diagnostics=await page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.diagnostics);
    expect(diagnostics.engine_ready).toBe(true);expect(diagnostics.face_count).toBe(1);expect(diagnostics.inference_ms).toBeGreaterThan(0);expect(diagnostics.observation_count).toBe(5);expect(diagnostics.candidate_count).toBe(1);expect(diagnostics.attendance_id).toBeGreaterThan(0);expect(diagnostics.decision).toBe('MATCHED');expect(diagnostics.embedding).toBeUndefined();
    const markedRequests=faceAttendanceRequests;
    await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Ready to scan',{timeout:5000});
    expect(faceAttendanceRequests).toBe(markedRequests);
    await expect(page.locator('.browser-face-dialog details')).toHaveCount(0);await expect(page.locator('.browser-face-dialog pre')).toHaveCount(0);
    const bounds=await page.locator('.browser-face-dialog').boundingBox();expect(bounds.y).toBeGreaterThanOrEqual(0);expect(bounds.y+bounds.height).toBeLessThanOrEqual(page.viewportSize().height);
    for(const viewport of [{width:390,height:844},{width:844,height:390},{width:1280,height:720}]){
        await page.setViewportSize(viewport);
        await expect.poll(()=>page.locator('#automaticFaceOverlay').evaluate(canvas=>{
            const ctx=canvas.getContext('2d'),w=canvas.width,h=canvas.height;
            return {outside:Array.from(ctx.getImageData(2,2,1,1).data),center:Array.from(ctx.getImageData(Math.floor(w/2),Math.floor(h/2),1,1).data)};
        })).toEqual({outside:[0,0,0,255],center:[0,0,0,0]});
        const preview=await page.locator('.browser-face-camera').boundingBox();expect(preview.height).toBeLessThanOrEqual(viewport.height*.55+1);
        await page.getByRole('button',{name:'Close',exact:true}).scrollIntoViewIfNeeded();
        const close=await page.getByRole('button',{name:'Close',exact:true}).boundingBox();expect(close.y).toBeGreaterThanOrEqual(0);expect(close.y+close.height).toBeLessThanOrEqual(viewport.height);
    }
    expect(page.url()).toContain('/pf/attendance.php?face_pilot=1');
    await expect.poll(()=>page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.busy)).toBe(true);
    await expect(page.getByRole('button',{name:'Confirm Attendance',exact:true})).toHaveCount(0);
await expect(first.locator('.attendance-status').first()).toHaveText('Already recorded');
    await page.getByRole('button',{name:'Close',exact:true}).click();
    const counts=await(await context.request.get('/fixture-pilot-counts')).json();expect(counts.templates).toBe(1);expect(counts.attendance).toBe(1);expect(counts.events).toBe(1);
    await page.reload();await expect(page.locator('.attendance-focused-table tbody tr')).toHaveCount(3,{timeout:20000});
    await expect(first.getByText('Enrolled',{exact:true})).toBeVisible();await expect(first.locator('.attendance-status').first()).toHaveText('Already recorded');
    await page.evaluate(()=>{const scope=angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope();scope.punch();scope.resetFaceAttendanceTestData();});
    expect(writes.filter(value=>{const url=new URL(value);return url.pathname!=='/routes/browserFaceRoute.php' || url.searchParams.get('pilot')!=='1';})).toEqual([]);
    // A fresh browser session must use the persisted encrypted identity,
    // without any enrollment or browser-retained embeddings.
    const fresh=await browser.newContext({permissions:['camera']});
    try{
        const loginAgain=await fresh.request.post('/fixture-pilot-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(loginAgain.ok()).toBe(true);
        const reopened=await fresh.newPage();reopened.on('dialog',dialog=>dialog.accept());await preparePage(reopened);
        await reopened.goto('/pf/attendance.php?face_pilot=1');
        await expect(reopened.locator('.attendance-focused-table tbody tr')).toHaveCount(3,{timeout:20000});
        const persisted=reopened.locator('.attendance-focused-table tbody tr').filter({hasText:'Face Camera Test One'});
        await expect(persisted.getByText('Enrolled',{exact:true})).toBeVisible();
        await reopened.getByRole('button',{name:/Scan Arrivals$/}).click();
        await expect(reopened.locator('.browser-face-feedback p:first-child')).toHaveText('Attendance already marked.',{timeout:20000});
        await reopened.getByRole('button',{name:'Close',exact:true}).click();
        const again=await(await fresh.request.get('/fixture-pilot-counts')).json();expect(again.templates).toBe(1);expect(again.attendance).toBe(1);expect(again.events).toBe(1);
    }finally{await fresh.close();}
    // Existing optional shell widgets may fail when deliberately offline; the
    // attendance/camera controller itself must remain operational.
    expect(errors.filter(e=>/BrowserFace|AutomaticCapture|batchAttendance|pilot/i.test(e))).toEqual([]);
});
