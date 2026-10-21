const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
const {setup,rows,open}=require('./attendance-ui-helpers.cjs');
// Real PHP server, real pf/face-attendance-settings.php, the real rollout file and the REAL face endpoints
// (routes/browserFaceRoute.php with the real BrowserFacePolicy). The file is the disposable field fixture used
// by zzzz-field-release.spec.cjs; it is restored afterwards.
const FILE=process.env.FACE_WEB_ROLLOUT_FILE,LOG=process.env.FACE_WEB_EVENT_LOG,DB=process.env.FACE_BROWSER_TEST_DB;
const HISTORY=path.join(path.dirname(FILE),'history'),LOCK=path.join(path.dirname(FILE),'.rollout.lock');
test.describe.configure({mode:'serial'});
test.use({permissions:['camera']});
let original,hadHistory;
const base=()=>({version:'browser-face-rollout-v1',enabled:true,default_mode:'SHADOW',scopes:[]});
const writeConfig=config=>{fs.writeFileSync(FILE,JSON.stringify(config,null,2));fs.chmodSync(FILE,0o660);};
const config=()=>JSON.parse(fs.readFileSync(FILE,'utf8'));
const scopes=()=>config().scopes;
const events=()=>fs.existsSync(LOG)?fs.readFileSync(LOG,'utf8').split('\n').filter(Boolean).map(l=>JSON.parse(l)):[];
const faceEvents=()=>events().filter(e=>/^face_(feature|mode|rollout)/.test(e.event));
test.beforeAll(()=>{
 if(FILE!=='/tmp/ccc-browser-field-fixture.json' || !LOG || !DB)throw Error('Disposable rollout fixture, event log and fixture database are required');
 original=fs.readFileSync(FILE);hadHistory=fs.existsSync(HISTORY);
});
test.afterAll(()=>{
 fs.writeFileSync(FILE,original);fs.chmodSync(FILE,0o600);
 if(!hadHistory)fs.rmSync(HISTORY,{recursive:true,force:true});fs.rmSync(LOCK,{force:true});
});
test.beforeEach(()=>writeConfig(base()));

async function login(context,position){
 const response=await context.request.post('/fixture-login?shell=1&position='+position,{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});
 expect(response.ok()).toBe(true);return (await response.json()).csrf;
}
// The offline sandbox cannot reach the CDNs the shared page header names; serve the pinned local copies.
async function offline(page){
 await page.route('https://**',route=>{
  const url=route.request().url();let file;
  if(url.includes('moment.min.js'))file='/tmp/ccc-attendance-ui-moment-2.13.0.js';
  else if(url.includes('jquery.min.js'))file='/tmp/ccc-attendance-ui-jquery-3.2.1.js';
  else if(url.includes('angular-sanitize.min.js'))file='pf/js/angular-sanitize.min.js';
  else if(url.includes('angular.min.js'))file='pf/js/controllers/angular.min.js';
  return file?route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.resolve(file))}):route.abort();
 });
}
const realConfig=(context,batch=11)=>context.request.get('/routes/browserFaceRoute.php?action=config&batch_id='+batch).then(r=>r.json());
const flash=page=>page.locator('.face-settings .flash[role=status]'); // the result of the last save; the storage alert is separate
const submit=async(page,name)=>{await Promise.all([page.waitForURL(/face-attendance-settings\.php/),page.locator(`button[name=op][value=${name}]`).click()]);await page.waitForLoadState('load');};
const open_=async(page)=>{await page.goto('/pf/face-attendance-settings.php?branch=2');await expect(page.locator('.face-settings h1')).toHaveText('Face Attendance Settings');};
const pick=page=>page.locator('input.pick-batch[value="11"]').check();

test('an ordinary Attendance operator cannot open the page or change anything',async({page,context})=>{
 const csrf=await login(context,10);await offline(page);
 const response=await page.goto('/pf/face-attendance-settings.php');
 expect(response.status()).toBe(403);await expect(page.locator('body')).not.toContainText('Branches');
 const before=fs.readFileSync(FILE,'utf8');
 const post=await context.request.post('/pf/face-attendance-settings.php',{form:{op:'stop_all',csrf,branch:'2'}});
 expect(post.status()).toBe(403);expect(fs.readFileSync(FILE,'utf8')).toBe(before);
 expect(events().some(e=>e.event==='face_rollout_rejected' && e.error_code==='NOT_ADMIN' && e.operation==='page')).toBe(true);
});

test('administrator overview: branches, batches, honest enrollment figures and why automatic attendance is unavailable',async({page,context})=>{
 await login(context,1);await offline(page);await open_(page);
 await expect(page.getByText('Automatic Attendance Not Ready').first()).toBeVisible();
 await expect(page.locator('input[name=mode][value=LIVE]')).toBeEnabled();
 await expect(page.locator('.face-settings')).toContainText('Branch 2');
 const batchRow=page.locator('#batch-form tbody tr').filter({hasText:'Browser fixture'});
 await expect(batchRow).toBeVisible();await expect(batchRow).toContainText('Enrollment and scanning');
 await expect(batchRow).toContainText('Not ready'); // per-batch automatic attendance
 const text=await page.locator('.face-settings').innerText();
 expect(text).not.toMatch(/sha256|embedding|template|\{|\}|vector|score/i); // nothing technical for ordinary readers
 await expect(page.locator('.face-settings table').first().locator('th')).toContainText(['Eligible members','Saved faces','Pending','Active','Duplicate conflicts','Not enrolled']);
});

test('turning a batch off is saved, survives refresh, and the real face endpoint then refuses work',async({page,context})=>{
 const csrf=await login(context,1);await offline(page);
 expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
 await open_(page);await pick(page);await page.locator('input[name=mode][value=DISABLED]').check();
 await submit(page,'apply_batches');
 await expect(flash(page)).toContainText('Changes Saved');
 expect(scopes()).toHaveLength(1);expect(scopes()[0]).toMatchObject({database:DB,branch_id:2,batch_id:11,mode:'DISABLED'});expect(scopes()[0].approved_by).toMatch(/^admin:\d+$/);
 await page.reload();await expect(page.locator('#batch-form tbody tr').filter({hasText:'Browser fixture'})).toContainText('Off');
 const disabled=await realConfig(context);
 expect(disabled.data.operating_mode).toBe('DISABLED');expect(disabled.data.scanning_available).toBe(false);
 const opened=await context.request.post('/routes/browserFaceRoute.php?action=open',{headers:{'X-Face-CSRF':csrf,'Content-Type':'application/json'},data:{batch_id:11,attendance_date:new Date().toLocaleDateString('en-CA'),attendance_session_id:7,selected_time:'10:00:00',idempotency_key:'settings_disabled_'+Date.now()}});
 expect(JSON.stringify(await opened.json())).toContain('FIELD_RELEASE_DISABLED');
 // and back on
 await pick(page);await page.locator('input[name=mode][value=SHADOW]').check();await submit(page,'apply_batches');
 await expect(flash(page)).toContainText('Changes Saved');
 expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
});

test('enrollment only: scanning is switched off by the server, not just hidden',async({page,context,browser})=>{
 await login(context,1);await offline(page);await open_(page);await pick(page);
 await page.locator('input[name=mode][value=SHADOW]').check();await page.locator('input[name=scanning]').uncheck();
 await submit(page,'apply_batches');await expect(flash(page)).toContainText('Changes Saved');
 const real=await realConfig(context);
 expect(real.data.operating_mode).toBe('CAPTURE_ONLY');expect(real.data.scanning_available).toBe(false);expect(real.data.enrollment_collection_available).toBe(true);
 await expect(page.locator('#batch-form tbody tr').filter({hasText:'Browser fixture'})).toContainText('Enrollment only');
 // Operators in that class can still enroll, but are not offered Scan Arrivals.
 const operator=await browser.newContext({permissions:['camera']});const attendance=await operator.newPage();
 await setup(attendance,operator,{clear:false});await attendance.route('**/routes/browserFaceRoute.php*',route=>route.continue());
 await open(attendance,'16:00:00');await expect(rows(attendance)).toHaveCount(2,{timeout:20000});
 await expect(attendance.getByRole('button',{name:/(Enroll|Update) Face$/}).first()).toBeVisible({timeout:20000});
 await expect(attendance.getByRole('button',{name:/Scan Arrivals$/})).toHaveCount(0);
 await operator.close();
});

test('branch controls: preset for all branches, disable selected branch (even future batches), return to default',async({page,context})=>{
 await login(context,1);await offline(page);page.on('dialog',d=>d.accept());await open_(page);
 await submit(page,'preset_enroll_all');await expect(flash(page)).toContainText('Changes Saved');
 expect(scopes()).toEqual([expect.objectContaining({database:DB,branch_id:2,batch_id:0,mode:'CAPTURE_ONLY'})]);
 expect((await realConfig(context)).data.operating_mode).toBe('CAPTURE_ONLY');expect((await realConfig(context,999)).data.operating_mode).toBe('CAPTURE_ONLY');
 await submit(page,'preset_scan_all');expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
 await page.locator('input[name="branches[]"][value="2"]').check();await submit(page,'stop_branches');
 await expect(flash(page)).toContainText('Changes Saved');
 expect((await realConfig(context)).data.operating_mode).toBe('DISABLED');expect((await realConfig(context,999)).data.operating_mode).toBe('DISABLED');
 await expect(page.locator('.face-settings table').first()).toContainText('Off');
 await page.locator('input[name=mode][value=SHADOW]').check();await submit(page,'apply_branch');
 expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
 expect(config().default_mode).toBe('SHADOW');expect(config().scopes.every(s=>s.database===DB && s.branch_id===2)).toBe(true);
 await page.locator('input[name="branches[]"][value="2"]').check();await submit(page,'reset_branches');
 await expect(flash(page)).toContainText('Changes Saved');expect(config().scopes.filter(s=>s.branch_id===2 && s.batch_id===0)).toHaveLength(0);
 expect((await realConfig(context)).data.operating_mode).not.toBe('DISABLED');
});

test('automatic attendance (LIVE) cannot be selected or forced, and the refusal is logged',async({page,context})=>{
 const csrf=await login(context,1);await offline(page);await open_(page);
 const before=fs.readFileSync(FILE,'utf8');
 const token=await page.locator('input[name=csrf]').first().inputValue(),cfg=await page.locator('input[name=cfg]').first().inputValue();
 const forced=await context.request.post('/pf/face-attendance-settings.php',{form:{op:'apply_batches',csrf:token,cfg,branch:'2',mode:'LIVE','batches[]':'11'},maxRedirects:0});
 expect(forced.status()).toBe(303);
 await open_(page);
 await expect(flash(page)).toContainText('Unable to Save');await expect(flash(page)).toContainText('Automatic Attendance Not Ready');
 expect(fs.readFileSync(FILE,'utf8')).toBe(before);
 expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
 expect(events().some(e=>e.event==='face_rollout_rejected' && e.error_code==='MATCHING_NOT_APPROVED' && e.persistence_succeeded===false)).toBe(true);
 const bad=await context.request.post('/pf/face-attendance-settings.php',{form:{op:'stop_all',csrf:'wrong',branch:'2'},maxRedirects:0});
 expect(bad.status()).toBe(303);expect(config().enabled).toBe(true); // a request without the right form token changes nothing
});

test('a stale form cannot overwrite a newer configuration, and an unavailable settings file is reported, never faked',async({page,context})=>{
 await login(context,1);await offline(page);await open_(page);await pick(page);await page.locator('input[name=mode][value=DISABLED]').check();
 const external={...config(),scopes:[{database:DB,branch_id:2,batch_id:11,mode:'CAPTURE_ONLY',approved_by:'admin:99',approved_at:new Date().toISOString(),expires_at:new Date(Date.now()+864e5).toISOString()}]};
 writeConfig(external);
 await submit(page,'apply_batches');
 await expect(flash(page)).toContainText('Unable to Save');await expect(flash(page)).toContainText('changed by someone else');
 expect(scopes()[0].mode).toBe('CAPTURE_ONLY');
 fs.renameSync(FILE,FILE+'.away');
 try{
  await open_(page);await expect(page.locator('.face-settings .flash[role=alert]')).toContainText('Settings cannot be changed right now');
  await pick(page);await submit(page,'apply_batches');await expect(flash(page)).toContainText('Unable to Save');await expect(flash(page)).not.toContainText('Changes Saved');
 }finally{fs.renameSync(FILE+'.away',FILE);}
});

test('stop everything immediately, keep manual attendance working, then resume',async({page,context,browser})=>{
 await login(context,1);await offline(page);page.on('dialog',d=>d.accept());await open_(page);
 await submit(page,'stop_all');await expect(flash(page)).toContainText('Changes Saved');
 expect(config().enabled).toBe(false);
 expect((await realConfig(context)).data.operating_mode).toBe('DISABLED');
 await expect(page.getByText('Stopped for everyone')).toBeVisible();await expect(page.locator('button[name=op][value=resume_all]')).toBeVisible();
 // The real attendance page in its own session (the shared helper signs in as an ordinary operator):
 // face controls disappear while manual attendance keeps working.
 const operator=await browser.newContext({permissions:['camera']});const attendance=await operator.newPage();
 await setup(attendance,operator,{clear:false});
 await attendance.route('**/routes/browserFaceRoute.php*',route=>route.continue()); // real policy, real controller
 await open(attendance,'16:00:00');await expect(rows(attendance)).toHaveCount(2,{timeout:20000});
 await expect(rows(attendance).first().locator('label',{hasText:'Present'})).toBeVisible({timeout:20000});
 await expect(attendance.getByRole('button',{name:/Scan Arrivals$/})).toHaveCount(0);await expect(attendance.getByRole('button',{name:/(Enroll|Update) Face$/})).toHaveCount(0);
 await expect(attendance.locator('.attendance-notice')).toHaveCount(0);
 await operator.close();
 await submit(page,'resume_all');await expect(flash(page)).toContainText('Changes Saved');
 expect(config().enabled).toBe(true);expect((await realConfig(context)).data.operating_mode).toBe('SHADOW');
 const second=await browser.newContext({permissions:['camera']});const normal=await second.newPage();
 await setup(normal,second,{clear:false});await normal.route('**/routes/browserFaceRoute.php*',route=>route.continue());
 await open(normal,'16:00:00');await expect(rows(normal)).toHaveCount(2,{timeout:20000});
 await expect(normal.getByRole('button',{name:/Scan Arrivals$/})).toBeVisible({timeout:20000});await expect(normal.getByRole('button',{name:/(Enroll|Update) Face$/}).first()).toBeVisible();
 await second.close();
});

test('every change reached the private log with who, what, scope and outcome, and nothing biometric',async({page,context})=>{
 await login(context,1);await offline(page);page.on('dialog',d=>d.accept());await open_(page);
 await pick(page);await page.locator('input[name=mode][value=DISABLED]').check();await submit(page,'apply_batches');
 await pick(page);await page.locator('input[name=mode][value=SHADOW]').check();await submit(page,'apply_batches');
 await submit(page,'stop_all');
 const mine=faceEvents().slice(-3);
 expect(mine.map(e=>e.event)).toEqual(['face_feature_disabled','face_feature_enabled','face_rollout_rolled_back']);
 for(const e of mine){
  expect(e.actor_id).toBeGreaterThan(0);expect(e.request_id).toMatch(/^[a-f0-9]{16}$/);expect(e.tenant).toBe(DB);expect(e.result).toBe('saved');expect(e.persistence_succeeded).toBe(true);expect(e.timestamp).toBeTruthy();expect(e.release).toBeTruthy();
  expect(e.previous_mode).toBeTruthy();expect(e.new_mode).toBeTruthy();expect(JSON.stringify(e)).not.toMatch(/embedding|vector|template|token|secret|password/i);
 }
 expect(mine[0]).toMatchObject({branch_id:2,batch_id:11,previous_mode:'SHADOW',new_mode:'DISABLED',scope_type:'BATCH'});
 expect(mine[2]).toMatchObject({scope_type:'GLOBAL',previous_mode:'ENABLED',new_mode:'DISABLED'});
 expect(fs.readdirSync(HISTORY).filter(f=>/^rollout-.*\.json$/.test(f)).length).toBeGreaterThanOrEqual(3);
});

test('the page is usable on a phone without sideways scrolling',async({browser})=>{
 const context=await browser.newContext({viewport:{width:390,height:844},isMobile:true,hasTouch:true,deviceScaleFactor:2});const page=await context.newPage();
 await login(context,1);await offline(page);await open_(page);
 expect(await page.evaluate(()=>document.scrollingElement.scrollWidth-innerWidth)).toBeLessThanOrEqual(0);
 await expect(page.locator('button[name=op][value=apply_batches]')).toBeVisible();
 const box=await page.locator('button[name=op][value=apply_batches]').boundingBox();expect(box.height).toBeGreaterThanOrEqual(36);
 await context.close();
});
