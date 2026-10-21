const {test,expect}=require('@playwright/test');
const {date,sleep,setup,rows,open}=require('./attendance-ui-helpers.cjs');
// Real attendance.php (actual PHP page, AngularJS 1.5.8, controller and face
// modules). Boot problems are injected by delaying/removing real script
// requests; roster problems by controlling the legacy list responses.
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});

// Records what the operator could actually see, from the first parsed byte
// until the test ends: raw template text, which roster panels were visible and
// which status phrases were on screen at the same time.
async function installTrace(page){
 await page.addInitScript(()=>{
  window.__trace=[];
  const phrases=['Loading attendance','Still loading attendance',"Couldn't load attendance",'No members found','Saving attendance','Unable to start attendance'];
  const snap=()=>{
   const body=document.body;if(!body)return;
   const text=body.innerText || '';
   const region=document.getElementById('rosterRegion');
   const visible=[...document.querySelectorAll('.roster-skeleton,.roster-empty,.roster-error,.attendance-roster')].filter(e=>e.offsetParent!==null).map(e=>e.className.split(' ')[0]);
   const boot=document.getElementById('attendanceBoot'),failed=document.getElementById('attendanceBootFailed');
   const entry={state:region?.getAttribute('data-roster-state') || null,visible,raw:/\{\{|\}\}/.test(text),boot:!!(boot && boot.offsetParent!==null),failed:!!(failed && failed.offsetParent!==null),texts:phrases.filter(s=>text.includes(s)),rows:[...document.querySelectorAll('.attendance-focused-table tbody tr')].filter(r=>r.offsetParent!==null).length};
   const key=JSON.stringify(entry),last=window.__trace[window.__trace.length-1];
   if(!last || last.key!==key){entry.key=key;window.__trace.push(entry);}
  };
  document.addEventListener('DOMContentLoaded',()=>new MutationObserver(snap).observe(document.documentElement,{subtree:true,childList:true,attributes:true,characterData:true}));
  setInterval(snap,80);
 });
}
const trace=page=>page.evaluate(()=>window.__trace);
function expectClean(entries,label=''){
 expect(entries.length,label+' trace recorded').toBeGreaterThan(0);
 for(const e of entries){
  expect(e.raw,label+' raw template text visible: '+JSON.stringify(e)).toBe(false);
  expect(e.texts.length,label+' contradictory messages: '+JSON.stringify(e)).toBeLessThanOrEqual(1);
  expect(e.visible.length,label+' several roster panels: '+JSON.stringify(e)).toBeLessThanOrEqual(1);
 }
}
const states=entries=>entries.map(e=>e.state).filter((s,i,a)=>s && s!==a[i-1]);
const region=page=>page.locator('#rosterRegion');

test('normal load: INITIAL/LOADING skeleton, then LOADED; never raw text, errors, empty or saving messages',async({page,context})=>{
 await installTrace(page);await setup(page,context,{roster:()=>({delay:900}),clear:false});
 await open(page);await expect(rows(page)).toHaveCount(2,{timeout:15000});await expect(region(page)).toHaveAttribute('data-roster-state','LOADED');
 const entries=await trace(page);expectClean(entries,'normal');
 expect(states(entries)).toEqual(expect.arrayContaining(['LOADING','LOADED']));expect(states(entries)).not.toContain('SLOW_LOADING');expect(states(entries)).not.toContain('ERROR');
 for(const e of entries)expect(e.texts).not.toContain('Saving attendance');
});

test('slow response: skeleton then "Still loading attendance…", no error, then the rows',async({page,context})=>{
 await installTrace(page);await setup(page,context,{roster:()=>({delay:8000}),clear:false});
 await open(page);
 await expect(region(page)).toHaveAttribute('data-roster-state','LOADING',{timeout:8000});await expect(page.locator('.roster-skeleton')).toBeVisible();
 await expect(region(page)).toHaveAttribute('data-roster-state','SLOW_LOADING',{timeout:10000});await expect(page.locator('.roster-skeleton')).toContainText('Still loading attendance…');
 await expect(page.locator('.roster-error')).toHaveCount(0);await expect(page.locator('.roster-empty')).toHaveCount(0);
 await expect(rows(page)).toHaveCount(2,{timeout:12000});await expect(region(page)).toHaveAttribute('data-roster-state','LOADED');
 const entries=await trace(page);expectClean(entries,'slow');expect(states(entries)).toEqual(expect.arrayContaining(['LOADING','SLOW_LOADING','LOADED']));expect(states(entries)).not.toContain('ERROR');
});

test('failed API: ERROR with Retry only after the failure; Retry recovers',async({page,context})=>{
 let mode='fail';
 await installTrace(page);await setup(page,context,{roster:()=>({delay:600,...(mode==='fail'?{status:500}:{})}),clear:false});
 await open(page);
 const error=page.locator('.roster-error');await expect(error).toBeVisible({timeout:15000});await expect(error).toContainText("Couldn't load attendance.");await expect(error.getByRole('button',{name:'Retry'})).toBeVisible();
 await expect(region(page)).toHaveAttribute('data-roster-state','ERROR');await expect(page.locator('.roster-skeleton')).toHaveCount(0);await expect(page.locator('.roster-empty')).toHaveCount(0);
 mode='ok';await error.getByRole('button',{name:'Retry'}).click();
 await expect(rows(page)).toHaveCount(2,{timeout:15000});await expect(error).toHaveCount(0);
 const entries=await trace(page);expectClean(entries,'failed');
 // The error never appeared while the first request was still in flight.
 const first=entries.findIndex(e=>e.state==='ERROR');expect(entries.slice(0,first).every(e=>!e.texts.includes("Couldn't load attendance"))).toBe(true);
});

test('empty response: EMPTY only after a successful empty answer',async({page,context})=>{
 await installTrace(page);await setup(page,context,{roster:()=>({delay:700,error:1}),clear:false});
 await open(page);
 const empty=page.locator('.roster-empty');await expect(empty).toBeVisible({timeout:15000});await expect(empty).toHaveText(/No members found for this class\./);
 await expect(region(page)).toHaveAttribute('data-roster-state','EMPTY');await expect(page.locator('.roster-error')).toHaveCount(0);
 const entries=await trace(page);expectClean(entries,'empty');
 const firstEmpty=entries.findIndex(e=>e.state==='EMPTY');expect(entries.slice(0,firstEmpty).every(e=>!e.texts.includes('No members found'))).toBe(true);
});

test('expired session and missing permission are not "empty"; expired offers Reload Page',async({page,context})=>{
 let code=2;
 await installTrace(page);await setup(page,context,{roster:()=>({error:code}),clear:false});
 await open(page);
 const error=page.locator('.roster-error');await expect(error).toContainText('Your session has expired. Please sign in again.',{timeout:15000});
 await expect(error.getByRole('button',{name:'Reload Page'})).toBeVisible();await expect(page.locator('.roster-empty')).toHaveCount(0);
 code=3;await page.reload();await expect(error).toContainText("You don't have permission to view this class.",{timeout:15000});await expect(page.locator('.roster-empty')).toHaveCount(0);
 expectClean(await trace(page),'session');
});

test('reload while the roster is loading ends in LOADED, not a stuck or false state',async({page,context})=>{
 let first=true;
 await installTrace(page);await setup(page,context,{roster:()=>{const slow=first;first=false;return slow?{hang:true}:{delay:300};},clear:false});
 await open(page);await expect(region(page)).toHaveAttribute('data-roster-state','LOADING',{timeout:10000});
 await page.reload();await expect(rows(page)).toHaveCount(2,{timeout:15000});await expect(region(page)).toHaveAttribute('data-roster-state','LOADED');
 expectClean(await trace(page),'reload');
});

test('changing session while loading keeps states exclusive and shows only the newest class',async({page,context})=>{
 await installTrace(page);await setup(page,context,{roster:session=>session==='7'?{delay:3500,prefix:'Old '}:{delay:300,prefix:'New '},clear:false});
 await open(page);await expect(region(page)).toHaveAttribute('data-roster-state','LOADING',{timeout:10000});
 await page.locator('#selectTime').selectOption('1');
 await expect(rows(page).first()).toContainText('New ',{timeout:10000});await sleep(4000);
 await expect(page.locator('.attendance-focused-table')).not.toContainText('Old ');
 const entries=await trace(page);expectClean(entries,'switch');
 for(const e of entries.filter(e=>e.rows>0))expect(e.state).toBe('LOADED');
});

test('slow bottom-of-page scripts: only the plain boot skeleton shows until AngularJS has compiled the page',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 // 510KB CKEditor is a synchronous footer script; delaying it keeps the page
 // parsed and painted but uncompiled, exactly the window users reported.
 await page.route('**/js/ckeditor/ckeditor.js',async route=>{await sleep(6000);await route.continue();});
 await page.goto('/pf/attendance.php?batch_id=11&date='+date+'&time=10:00:00',{waitUntil:'commit'});
 await expect.poll(async()=>(await trace(page).catch(()=>[])).some(e=>e.boot),{timeout:10000}).toBe(true);
 await sleep(3000);
 const during=await trace(page);expectClean(during,'boot-window');
 expect(during.some(e=>e.boot)).toBe(true);expect(during.some(e=>e.rows>0)).toBe(false);
 await expect(rows(page)).toHaveCount(2,{timeout:20000});await expect(page.locator('#attendanceBoot')).toBeHidden();
 const entries=await trace(page);expectClean(entries,'boot-full');
 const afterReady=entries.slice(entries.findIndex(e=>e.rows>0));for(const e of afterReady)expect(e.boot).toBe(false);
});

async function expectFallback(page,{within=12000}={}){
 const failed=page.locator('#attendanceBootFailed');
 await expect(failed).toBeVisible({timeout:within});await expect(failed).toContainText('Unable to start attendance.');
 await expect(failed.getByRole('button',{name:'Reload Page'})).toBeVisible();
 await expect(page.locator('#attendanceBoot .attendance-skeleton')).toBeHidden();await expect(page.locator('#list-content')).toBeHidden();
 const text=await page.evaluate(()=>document.body.innerText);expect(text).not.toMatch(/\{\{|\}\}/);expect(text).not.toContain('Saving attendance');expect(text).not.toContain('No members found');
}
test('AngularJS itself fails to load: plain fallback with a working Reload Page button',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/angular.min.js',route=>route.abort());
 let loads=0;page.on('request',r=>{if(new URL(r.url()).pathname==='/pf/attendance.php')loads++;});
 await open(page);await expectFallback(page);expectClean(await trace(page),'no-angular');
 const before=loads;await Promise.all([page.waitForRequest(r=>new URL(r.url()).pathname==='/pf/attendance.php'),page.getByRole('button',{name:'Reload Page'}).click()]);expect(loads).toBeGreaterThan(before);
});

test('controller script missing (404): fallback appears quickly',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/batchAttendanceController.js*',route=>route.fulfill({status:404,contentType:'text/plain',body:'missing'}));
 await open(page);await expectFallback(page,{within:8000});expectClean(await trace(page),'no-controller');
});

test('a required AngularJS module is missing (ngSanitize): modulerr leads to the fallback, not raw text',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/angular-sanitize.min.js',route=>route.abort());
 await open(page);await expectFallback(page);expectClean(await trace(page),'no-sanitize');
});

test('a footer dependency fails (Chart.js / CKEditor glue): the whole app cannot start and the fallback shows',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/js/lib/angular-chart.js',route=>route.abort());
 await open(page);await expectFallback(page);expectClean(await trace(page),'no-chart');
});

test('controller throws during initialization: fallback instead of a half-working page',async({page,context})=>{
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/batchAttendanceController.js*',async route=>{const response=await route.fetch();const body=(await response.text()).replace('$scope.batch = {};',"throw new Error('boot test failure');$scope.batch = {};");await route.fulfill({response,body});});
 await open(page);await expectFallback(page);expectClean(await trace(page),'throws');
});

test('saving has its own state: TEST punch shows Saving on the button while the roster stays LOADED',async({page,context})=>{
 // Reset Public A/B first: earlier specs in the suite may have left an enrollment.
 await installTrace(page);const calls=await setup(page,context);
 // Session 10 (16:00) is used by no other spec, so Public A starts unpunched.
 await open(page,'16:00:00');await expect(rows(page)).toHaveCount(2,{timeout:15000});
 const first=rows(page).filter({hasText:'Public A'});await expect(first.getByRole('button',{name:/^(Enroll|Update) Face$/})).toBeVisible({timeout:15000});
 await first.getByRole('button',{name:/^(Enroll|Update) Face$/}).click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved',{timeout:30000});await page.getByRole('button',{name:'Close',exact:true}).click();
 await page.getByRole('button',{name:/Scan Arrivals$/}).click();await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible({timeout:30000});
 await page.route('**/fixture-test-api?action=test_punch',async route=>{await sleep(1800);await route.continue();});
 await page.locator('.browser-face-panel').getByRole('button',{name:'Punch Attendance',exact:true}).click();
 // The camera dialog closes first; the page's own Punch button then shows the saving state.
 await expect(page.locator('.attendance-primary-actions').getByRole('button',{name:'Saving…'})).toBeVisible();
 await expect(region(page)).toHaveAttribute('data-roster-state','LOADED');await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible();
 await expect(first.locator('.attendance-status')).toHaveText('Present',{timeout:20000});expect(calls.punch).toBe(1);
 const entries=await trace(page);expectClean(entries,'saving');for(const e of entries)expect(e.texts).not.toContain('Saving attendance');
});

test('manual attendance save: "Saving attendance…" appears only while saving, with the roster still LOADED',async({page,context})=>{
 await installTrace(page);const calls=await setup(page,context,{clear:false});
 // Manual mode: the class has no face scanning, so rows show Present/Absent controls.
 await page.route('**/routes/browserFaceRoute.php?action=config*',async route=>{
  const url=new URL(route.request().url());url.pathname='/fixture-test-api';const response=await route.fetch({url:url.href});const body=await response.json();
  body.data.operating_mode='CAPTURE_ONLY';body.data.scanning_available=false;await route.fulfill({response,json:body});
 });
 let saves=0;await page.route('**/routes/attendanceRoute.php?action=insertBatchAttendance*',async route=>{saves++;await sleep(2000);await route.fulfill({contentType:'application/json',body:JSON.stringify({error:0,message:'Attendance saved',data:[]})});});
 await open(page,'16:00:00');await expect(rows(page)).toHaveCount(2,{timeout:15000});
 const first=rows(page).filter({hasText:'Public A'});await expect(first.getByText('Present',{exact:true}).first()).toBeVisible({timeout:15000});
 await first.locator('label',{hasText:'Present'}).click();
 const before=await trace(page);for(const e of before)expect(e.texts).not.toContain('Saving attendance');
 await page.locator('.attendance-primary-actions').getByRole('button',{name:'Punch Attendance'}).click();
 await page.getByRole('button',{name:'Yes, Punch Attendance'}).click();
 await expect(page.locator('.attendance-saving')).toBeVisible({timeout:5000});await expect(page.locator('.attendance-saving')).toContainText('Saving attendance…');
 await expect(region(page)).toHaveAttribute('data-roster-state','LOADED');await expect(page.locator('.roster-skeleton')).toHaveCount(0);await expect(page.locator('.roster-error')).toHaveCount(0);
 await expect(page.locator('.attendance-saving')).toHaveCount(0,{timeout:10000});expect(saves).toBe(1);
 await expect(page.locator('.attendance-complete-card')).toContainText('Attendance saved');
 const entries=await trace(page);expectClean(entries,'manual-save');
 // The saving message was never visible together with loading/empty/error phrases.
 for(const e of entries.filter(e=>e.texts.includes('Saving attendance')))expect(e.texts).toEqual(['Saving attendance']);
});

test('class list: expired session offers Reload Page, a failed request offers Retry that recovers',async({page,context})=>{
 let mode='session';
 await installTrace(page);await setup(page,context,{clear:false});
 await page.route('**/routes/batchRoute.php?action=select*',async route=>{
  if(mode==='session')return route.fulfill({contentType:'application/json',body:JSON.stringify({error:2,data:[],message:'check session id or token'})});
  if(mode==='fail')return route.fulfill({status:500,contentType:'text/plain',body:'failure'});
  return route.fallback();
 });
 await open(page);
 const placeholder=page.locator('.attendance-field-placeholder.is-error');
 await expect(placeholder).toContainText('Your session has expired. Please sign in again.',{timeout:15000});
 await expect(placeholder.getByRole('button',{name:'Reload Page'})).toBeVisible();await expect(placeholder.getByRole('button',{name:'Retry'})).toHaveCount(0);
 mode='fail';await page.reload();
 await expect(placeholder).toContainText("Couldn't load classes.",{timeout:15000});await expect(placeholder.getByRole('button',{name:'Retry'})).toBeVisible();
 mode='ok';await placeholder.getByRole('button',{name:'Retry'}).click();
 await expect(page.locator('#selectBatch')).toBeVisible({timeout:15000});await expect(rows(page)).toHaveCount(2,{timeout:15000});
 expectClean(await trace(page),'class-list');
});
