const {test,expect}=require('@playwright/test');
const {date,sleep,setup,rows,open}=require('./attendance-ui-helpers.cjs');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});

test('slow roster shows Loading then Still loading, never an error or empty state, then renders rows',async({page,context})=>{
 const calls=await setup(page,context,{roster:()=>({delay:7500})});
 await open(page);
 const loading=page.locator('.roster-skeleton');
 await expect(loading).toHaveText(/Loading attendance…/);
 await expect(page.getByText('No members found',{exact:false})).toHaveCount(0);await expect(page.locator('.attendance-stage-panel.is-error')).toHaveCount(0);
 await expect(loading).toHaveText(/Still loading attendance…/,{timeout:9000});
 await expect(page.locator('.attendance-stage-panel.is-error')).toHaveCount(0);
 await expect(rows(page)).toHaveCount(2,{timeout:10000});await expect(loading).toHaveCount(0);
 expect(calls.roster).toEqual(['7']);
});

test('genuine failure shows Retry, retry recovers; empty and permission results are distinct',async({page,context})=>{
 let mode='fail';
 await setup(page,context,{roster:()=>mode==='fail'?{status:500}:mode==='empty'?{error:1}:mode==='denied'?{error:3}:null});
 await open(page);
 const failure=page.locator('.attendance-stage-panel.is-error');
 await expect(failure).toContainText("Couldn't load attendance.");
 mode='ok';await failure.getByRole('button',{name:'Retry'}).click();
 await expect(rows(page)).toHaveCount(2);await expect(failure).toHaveCount(0);
 mode='empty';await page.reload();
 await expect(page.locator('.attendance-stage-panel.is-empty')).toHaveText(/No members found for this class\./);
 await expect(page.locator('.attendance-stage-panel.is-error')).toHaveCount(0);
 mode='denied';await page.reload();
 await expect(failure).toContainText("You don't have permission to view this class.");await expect(page.locator('.attendance-stage-panel.is-empty')).toHaveCount(0);
});

test('a request that never answers reaches a bounded error instead of an infinite spinner',async({page,context})=>{
 await page.clock.install();
 await setup(page,context,{roster:()=>({hang:true}),clear:false});
 await open(page);
 await expect(page.locator('.roster-skeleton')).toBeVisible();
 await page.clock.fastForward(7000);await expect(page.locator('.roster-skeleton')).toHaveText(/Still loading/);
 await page.clock.fastForward(40000);
 await expect(page.locator('.attendance-stage-panel.is-error')).toContainText("Couldn't load attendance.");
});

test('changing session while a slow response is pending keeps only the newest roster',async({page,context})=>{
 const calls=await setup(page,context,{roster:session=>session==='7'?{delay:3000,prefix:'Old '}:{prefix:'New '},clear:false});
 await open(page);
 await expect(page.locator('.roster-skeleton')).toBeVisible();
 await page.locator('#selectTime').selectOption('1');
 await expect(rows(page).first()).toContainText('New ');
 await sleep(3500);
 await expect(rows(page)).toHaveCount(2);await expect(page.locator('.attendance-focused-table')).not.toContainText('Old ');
 expect(calls.roster).toEqual(['7','8']);
});

test('face status loads before row labels and actions appear; no premature Not Captured or manual controls',async({page,context})=>{
 await setup(page,context,{faceDelay:2500});
 await open(page);
 await expect(rows(page)).toHaveCount(2);
 const first=rows(page).filter({hasText:'Public B'});
 await expect(first.locator('.face-status')).toHaveText('Checking…');
 await expect(first.getByText('Not Captured',{exact:true})).toHaveCount(0);await expect(first.getByRole('radio')).toHaveCount(0);await expect(first.getByRole('button')).toHaveCount(0);
 await expect(page.getByRole('button',{name:/Punch Attendance$/})).toBeDisabled();
 // After loading, the label is the backend state (earlier specs may have captured this member).
 await expect(first.locator('.face-status')).toHaveText(/^(Not Captured|Needs Attention|Saved for Testing|Pending Setup|Enrolled)$/,{timeout:10000});
 await expect(first.locator('.attendance-status')).toHaveText(/^(Not Detected|Present)$/);await expect(first.getByRole('button',{name:/^(Enroll|Update) Face$/})).toBeVisible();
 await expect(page.locator('.attendance-mode-pill')).toHaveText('Test mode');
 await expect(page.getByText('Scan arrivals to enable Punch Attendance.')).toBeVisible();
 // Testing tools are removed from attendance page.
 await expect(page.locator('.attendance-testing-tools')).toHaveCount(0);
 await expect(page.getByRole('button',{name:/Reset Face Attendance Test Data/})).toHaveCount(0);
});

test('enrollment and scan modals: concise layout, focus, Escape, pending row, single punch submission',async({page,context})=>{
 const calls=await setup(page,context);
 // The 14:00 session keeps this punch separate from the other specs (10:00/12:00).
 await open(page,'14:00:00');await expect(rows(page)).toHaveCount(2);
 const first=rows(page).filter({hasText:'Public A'});
 const enroll=first.getByRole('button',{name:'Enroll Face',exact:true});await expect(enroll).toBeVisible({timeout:15000});
 await enroll.click();
 const panel=page.locator('.browser-face-panel');
 await expect(page.locator('#automaticFaceTitle')).toHaveText('Enroll Face — Public A');
 // The title must render inside the dialog (the theme fixes <header> elements to the page top).
 const dialogBox=await page.locator('.browser-face-dialog').boundingBox(),titleBox=await page.locator('#automaticFaceTitle').boundingBox();
 expect(titleBox.y).toBeGreaterThanOrEqual(dialogBox.y);expect(titleBox.x).toBeGreaterThanOrEqual(dialogBox.x);expect(titleBox.y+titleBox.height).toBeLessThanOrEqual(dialogBox.y+dialogBox.height);
 await expect(panel.locator('.browser-face-instruction')).toHaveText('Position face inside the circle');
 await expect(panel.getByText('Pending Punch',{exact:false})).toHaveCount(0);await expect(panel.locator('.browser-face-mode')).toHaveCount(0);
 await expect(page.locator('.browser-face-dialog')).toBeFocused();
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved',{timeout:30000});
 await expect(page.locator('.browser-face-feedback')).toHaveClass(/is-success/);
 await page.keyboard.press('Escape');await expect(panel).toBeHidden();
 await expect(first.getByRole('button',{name:'Update Face',exact:true})).toBeFocused();
 await expect(first.locator('.face-status')).toHaveText('Saved for Testing');
 await expect(first.getByText('Face saved',{exact:false})).toHaveCount(0); // no repeated status in the action column
 await page.getByRole('button',{name:/Scan Arrivals$/}).click();
 await expect(page.locator('#automaticFaceTitle')).toHaveText('Scan Arrivals');
 await expect(panel.locator('.browser-face-subtitle')).toHaveText('Browser fixture · 02:00 PM – 03:00 PM');
 await expect(panel.locator('.browser-face-mode')).toHaveText('Test mode');
 const modalPunch=panel.getByRole('button',{name:'Punch Attendance',exact:true});
 await expect(modalPunch).toBeDisabled();
 await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible({timeout:30000});
 await expect(modalPunch).toBeEnabled();
 // Delay the real backend answer: no success is shown before it arrives, and
 // a double click sends one request.
 await page.route('**/fixture-test-api?action=test_punch',async route=>{await sleep(1500);await route.continue();});
 await modalPunch.dblclick();
 await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible();
 await expect(first.locator('.attendance-status')).toHaveText('Present',{timeout:20000});await expect(first.locator('.attendance-test-tag')).toHaveText('Test');
 expect(calls.punch).toBe(1);
 await expect(page.getByRole('button',{name:/Punch Attendance$/})).toBeDisabled();
 expect(calls.writes.filter(w=>/insert|update|delete|reset/i.test(w))).toEqual([]);
});

test('phone layout: no sideways scrolling, modal controls stay on screen, camera keeps its 4:3 circle geometry',async({browser})=>{
 const context=await browser.newContext({viewport:{width:375,height:667},isMobile:true,hasTouch:true,deviceScaleFactor:2,permissions:['camera']});const page=await context.newPage();
 await setup(page,context,{clear:false});
 await open(page);await expect(rows(page)).toHaveCount(2);
 await expect(rows(page).first().getByRole('button',{name:/(Enroll|Update) Face$/})).toBeVisible({timeout:15000});
 const overflow=await page.evaluate(()=>document.scrollingElement.scrollWidth-window.innerWidth);expect(overflow).toBeLessThanOrEqual(0);
 for(const button of await page.locator('.attendance-primary-actions .btn').all()){const box=await button.boundingBox();expect(box.height).toBeGreaterThanOrEqual(44);}
 await page.getByRole('button',{name:/Scan Arrivals$/}).click();
 const camera=page.locator('.browser-face-camera');await expect(camera).toBeVisible();
 await expect.poll(()=>page.evaluate(()=>!!angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.busy),{timeout:20000}).toBe(true);
 const box=await camera.boundingBox();expect(Math.abs(box.width/box.height-4/3)).toBeLessThan(.02);
 for(const name of ['Punch Attendance','Close']){const b=await page.locator('.browser-face-panel').getByRole('button',{name,exact:true}).boundingBox();expect(b.y+b.height).toBeLessThanOrEqual(667);expect(b.x).toBeGreaterThanOrEqual(0);expect(b.x+b.width).toBeLessThanOrEqual(375);expect(b.height).toBeGreaterThanOrEqual(44);}
 await page.waitForTimeout(1500);
 const guide=await page.evaluate(()=>angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope().automaticFace.guide);
 if(guide){expect(guide.x-guide.radius).toBeGreaterThanOrEqual(0);expect(guide.y-guide.radius).toBeGreaterThanOrEqual(-1);expect(guide.x+guide.radius).toBeLessThanOrEqual(box.width+1);expect(guide.y+guide.radius).toBeLessThanOrEqual(box.height+1);}
 await page.locator('.browser-face-panel').getByRole('button',{name:'Close',exact:true}).click();await expect(page.locator('.browser-face-panel')).toBeHidden();
 await context.close();
});
