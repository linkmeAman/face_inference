const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('unvalidated actual shadow suggestions require independent checkbox verification and explicit manual confirmation',async({page,context})=>{
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);
 const before=await(await context.request.get('/fixture-counts')).json();
 await page.goto('/tests/browser-face/embedded.php?shadow=1');await expect(page.locator('#scan')).toBeVisible();await page.locator('#session').selectOption('9');await page.locator('#scan').click();
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Public A suggested for review. Please verify.',{timeout:20000});
 expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);expect(await page.evaluate(()=>testFlow.capture.active)).toBe(true);
 await page.getByRole('button',{name:'Finish Scanning',exact:true}).click();await expect(page.getByRole('checkbox',{name:'Public A',exact:true})).not.toBeChecked();
 await page.getByRole('button',{name:'Confirm Attendance',exact:true}).click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Select the members you have verified.');expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 // The controlled input is the known public Lena fixture. This independent
 // test-input label is not a predicted identity or real-child validation.
 await page.getByRole('checkbox',{name:'Public A',exact:true}).check();await page.getByRole('button',{name:'Confirm Attendance',exact:true}).click();
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Attendance confirmed.');await expect(page.locator('#row-101')).toContainText('Recorded');
 const after=await(await context.request.get('/fixture-counts')).json();expect(after.attendance-before.attendance).toBe(1);expect(after.events-before.events).toBe(1);expect(after.deductions-before.deductions).toBe(1);expect(after.templates).toBe(before.templates);
 const config=(await(await context.request.get('/fixture-shadow-api?action=config&batch_id=11')).json()).data;expect(config.acceptance_ready).toBe(false);expect(config.operating_mode).toBe('SHADOW');
 expect(await page.evaluate(()=>testFlow.capture.worker===null && testFlow.capture.stream===null)).toBe(true);await page.getByRole('button',{name:'Close',exact:true}).click();
});
