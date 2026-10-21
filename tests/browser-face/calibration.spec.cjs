const {test,expect}=require('@playwright/test');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream',
    '--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('encrypted calibration collection uses real inference without enrolling or marking',async({page,context})=>{
    const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);
    await page.route('**/routes/browserFaceRoute.php?**',route=>{const url=new URL(route.request().url());url.pathname='/fixture-api';return route.continue({url:url.href});});
    const before=await(await context.request.get('/fixture-counts')).json();
    await page.goto('/pf/face-calibration.php');await expect(page.locator('#person option')).toHaveCount(4);
    await page.locator('#person').selectOption('1');
    await page.locator('#visit').fill('synthetic-test-visit');await page.locator('#device').fill('simulated-camera-test');
    await page.locator('#lighting').fill('public-photo-test');await page.locator('#consent').check();
    await page.locator('#collect').click();
    await expect(page.locator('#status')).toContainText('saved securely',{timeout:20000});
    expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
    await expect(page.locator('#counts')).toContainText('1/100 observations');
    await page.locator('#erase').click();await expect(page.locator('#counts')).toContainText('0/100 observations');
    // Never feed this explicitly synthetic test export into live calibration.
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
});
test('anonymous production API and malformed inputs fail safely',async({request})=>{
    const anonymous=await request.get('/routes/browserFaceRoute.php?action=config');expect(anonymous.status()).toBe(401);
    expect((await anonymous.json()).error_msg).toBe('AUTH_REQUIRED');
    const malformed=await request.post('/routes/browserFaceRoute.php?action=enroll',{headers:{'Content-Type':'application/json'},data:Buffer.from('{')});
    expect(malformed.status()).toBe(400);expect((await malformed.json()).error_msg).toBe('Invalid JSON request');
});
