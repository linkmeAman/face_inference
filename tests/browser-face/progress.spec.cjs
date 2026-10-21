const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('real enrollment observations fill the shared circular guide ring without a straight progress bar',async({page,context})=>{
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 await page.goto('/tests/browser-face/embedded.php?pending=1');await expect(page.locator('#enroll-1')).toBeVisible();
 await page.evaluate(()=>{window.actualProgress=[];const change=testFlow.change;testFlow.change=state=>{if(actualProgress.at(-1)!==state.progress)actualProgress.push(state.progress);change(state);};});
 await page.locator('#enroll-1').click();await expect.poll(()=>page.evaluate(()=>testFlow.state.progress),{timeout:20000}).toBeGreaterThan(0);
 await expect(page.locator('.browser-face-dialog progress')).toHaveCount(0);await expect(page.locator('.browser-face-progress-ring')).toBeVisible();

 const geometry=await page.evaluate(()=>{const guide=testFlow.state.guide,canvas=document.getElementById('automaticFaceOverlay').getBoundingClientRect(),ring=document.querySelector('.browser-face-progress-ring').getBoundingClientRect();return {centerError:Math.hypot(ring.x+ring.width/2-canvas.x-guide.x,ring.y+ring.height/2-canvas.y-guide.y),radiusError:Math.abs(ring.width/2-guide.radius)};});
 expect(geometry.centerError).toBeLessThan(1);expect(geometry.radiusError).toBeLessThan(1);
 await page.emulateMedia({reducedMotion:'reduce'});expect(await page.locator('.browser-face-progress-ring circle').evaluate(el=>getComputedStyle(el).transitionDuration)).toBe('0s');
 await expect(page.locator('.browser-face-feedback p:first-child')).toContainText('Face saved',{timeout:20000});expect(await page.evaluate(()=>actualProgress.filter(p=>p>0))).toEqual([.2,.4,.6,.8,1]);
 await expect(page.locator('.browser-face-camera')).toBeHidden();await expect(page.locator('.browser-face-progress-ring')).toBeHidden();expect(await page.evaluate(()=>testFlow.state.progress)).toBe(0);
 await page.getByRole('button',{name:'Close',exact:true}).click();
 await context.request.post('/fixture-pending-api?action=calibration_delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:1,idempotency_key:'progress_fixture_cleanup_'+Date.now()}});
});
