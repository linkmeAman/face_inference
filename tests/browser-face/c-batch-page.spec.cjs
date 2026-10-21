const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('normal attendance.php offers shadow capture and preserves manual controls without pilot permission',async({page,context})=>{
 page.on('dialog',dialog=>dialog.accept());
 const login=await context.request.post('/fixture-login?shell=1',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 const date=new Date().toLocaleDateString('en-CA');
 const roster=(await(await context.request.get('/fixture-shadow-api?action=roster&batch_id=11&attendance_date='+date)).json()).data;
 const before=await(await context.request.get('/fixture-counts')).json();const rejectedWrites=[];
 await page.route('https://**',route=>{
  const url=route.request().url();let file;
  if(url.includes('moment.min.js'))file='/tmp/ccc-attendance-ui-moment-2.13.0.js';
  else if(url.includes('jquery.min.js'))file='/tmp/ccc-attendance-ui-jquery-3.2.1.js';
  else if(url.includes('angular-sanitize.min.js'))file='pf/js/angular-sanitize.min.js';
  else if(url.includes('angular.min.js'))file='pf/js/controllers/angular.min.js';
  return file?route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.resolve(file))}):route.abort();
 });
 // Existing legacy list responses are read fixtures only. Camera, inference,
 // face API, authorization and persistence run the actual normal code.
 await page.route('**/routes/**',route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action'),file=path.basename(url.pathname);
  if(file==='browserFaceRoute.php'){url.pathname='/fixture-shadow-api';return route.continue({url:url.href});}
  let data=[],error=0;
  if(file==='batchRoute.php' && action==='select')data=[{id:'11',batch:'Browser fixture',offline:'0',employee:[],batch_time:[{session_id:'7',start_time:'10:00:00',end_time:'11:00:00',module:'',title:'',module_id:'0'}]}];
  else if(file==='memberRoute.php' && action==='selectBatchMember')data=roster.persons.map(p=>({...p,fullname:p.name,code:'TEST',item_id:Number(p.id)===1?'21':'22',start_date:'2000-01-01',end_date:'2100-01-01',negative:1,attendance_id:'0'}));
  else if(file==='settingRoute.php' && action==='getSetting')data=[{back_date_att:'2',back_dated_days:'2',mark_absent:'2'}];
  else if(file==='attendanceRoute.php' && action==='select')error=1;
  else{rejectedWrites.push(file+':'+action);error=1;}
  return route.fulfill({contentType:'application/json',body:JSON.stringify({error,data,message:'Read fixture only'})});
 });
 await page.goto('/pf/attendance.php?batch_id=11&date='+date+'&time=10:00:00');
 await expect(page.locator('.attendance-focused-table tbody tr')).toHaveCount(2,{timeout:15000});
 expect(await page.evaluate(()=>window.browserFacePilot)).toBe(false);expect(await page.evaluate(()=>window.browserFaceEnabled)).toBe(true);
 const first=page.locator('.attendance-focused-table tbody tr').filter({hasText:'Public A'});
 await expect(first.getByLabel('Present',{exact:true})).toBeVisible();await expect(first.getByLabel('Absent',{exact:true})).toBeVisible();
 await first.getByLabel('Present',{exact:true}).check();await expect(first.getByLabel('Present',{exact:true})).toBeChecked();
 await expect(page.getByRole('button',{name:/Punch Attendance$/}).first()).toBeVisible();
 await first.getByRole('button',{name:/Enroll Face$/}).click();await expect(first.locator('.face-status')).toHaveText('Pending Setup',{timeout:20000});
 await page.getByRole('button',{name:'Close',exact:true}).click();
 await page.getByRole('button',{name:/Scan Arrivals$/}).click();await expect(page.locator('.browser-face-mode-note')).toHaveText('Suggestions need your identity check. Attendance is saved only after confirmation.');
 await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Public A suggested for review. Please verify.',{timeout:20000});
 await page.getByRole('button',{name:'Close',exact:true}).click();await expect(first.getByLabel('Present',{exact:true})).toBeChecked();
 expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 expect(rejectedWrites.filter(w=>/insert|update|delete|reset/i.test(w))).toEqual([]);
 await context.request.post('/fixture-pending-api?action=calibration_delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:1,idempotency_key:'normal_shell_cleanup_'+Date.now()}});
});
