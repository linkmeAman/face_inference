const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('attendance enrollment automatically collects real-model pending samples without identity acceptance',async({page,context})=>{
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 const before=await(await context.request.get('/fixture-counts')).json();
 await page.goto('/tests/browser-face/embedded.php?pending=1');await expect(page.locator('#enroll-1')).toBeVisible();await page.locator('#enroll-1').click();
 await expect(page.locator('#row-101')).toContainText('Face captured; enrollment will finish automatically',{timeout:20000});
 await expect(page.locator('.browser-face-feedback p:first-child')).toContainText('Face saved');
 expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 const options=(await(await context.request.get('/fixture-pending-api?action=options')).json()).data;
 const member=options.persons.find(p=>Number(p.id)===1);expect(Number(member.pending_samples)).toBe(1);expect(Number(member.enrolled)).toBe(0);
 const progress=(await(await context.request.get('/fixture-pending-api?action=calibration_progress')).json()).data;
 expect(progress.observations[0].visit).toMatch(/^automatic-date:/);expect(progress.observations[0].device).toMatch(/^camera:[a-f0-9]{64}$/);expect(progress.observations[0].lighting).toMatch(/^measured-/);expect(progress.validation_samples).toBe(0);
 await page.getByRole('button',{name:'Close'}).click();await page.locator('#scan').click();await expect(page.locator('.browser-face-feedback p:first-child')).toContainText('Scanning is not enabled for this class yet');
 expect(await page.evaluate(()=>testFlow.capture?.active || false)).toBe(false);expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 await context.request.post('/fixture-pending-api?action=calibration_delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:1,idempotency_key:'pending_fixture_cleanup_'+Date.now()}});
});
