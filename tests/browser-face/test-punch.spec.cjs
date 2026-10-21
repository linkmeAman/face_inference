const {test,expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
test.use({permissions:['camera'],launchOptions:{args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture=/tmp/ccc-public-camera.y4m']}});
test('normal attendance.php stages, punches isolated TEST attendance, refreshes Update Face and preserves originals',async({page,context})=>{
 let declineSelection=false;page.on('dialog',dialog=>declineSelection?dialog.dismiss():dialog.accept());
 const login=await context.request.post('/fixture-login?shell=1',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 for(const id of [1,2])await context.request.post('/fixture-pending-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:id,idempotency_key:'punch_shell_clear_'+id+Date.now()}});
 const date=new Date().toLocaleDateString('en-CA');
 const roster=(await(await context.request.get('/fixture-test-api?action=roster&batch_id=11&attendance_date='+date)).json()).data;
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
  if(file==='browserFaceRoute.php'){url.pathname='/fixture-test-api';return route.continue({url:url.href});}
  let data=[],error=0;
  if(file==='batchRoute.php' && action==='select')data=[{id:'11',batch:'Browser fixture',offline:'0',employee:[],batch_time:[{session_id:'7',start_time:'10:00:00',end_time:'11:00:00',module:'',title:'',module_id:'0'},{session_id:'8',start_time:'12:00:00',end_time:'13:00:00',module:'',title:'',module_id:'0'}]}];
  else if(file==='memberRoute.php' && action==='selectBatchMember')data=roster.persons.map(p=>({...p,fullname:p.name,code:'TEST',item_id:Number(p.id)===1?'21':'22',start_date:'2000-01-01',end_date:'2100-01-01',negative:1,attendance_id:'0'}));
  else if(file==='settingRoute.php' && action==='getSetting')data=[{back_date_att:'2',back_dated_days:'2',mark_absent:'2'}];
  else if(file==='attendanceRoute.php' && action==='select')error=1;
  else{rejectedWrites.push(file+':'+action);error=1;}
  return route.fulfill({contentType:'application/json',body:JSON.stringify({error,data,message:'Read fixture only'})});
 });
 await page.goto('/pf/attendance.php?batch_id=11&date='+date+'&time=10:00:00');
 await expect(page.locator('.attendance-focused-table tbody tr')).toHaveCount(2,{timeout:15000});
 const first=page.locator('.attendance-focused-table tbody tr').filter({hasText:'Public A'});
 await expect(first.getByLabel('Present',{exact:true})).toHaveCount(0);await expect(first.getByText('Not Detected',{exact:true})).toBeVisible();
 const punch=page.getByRole('button',{name:/Punch Attendance$/}).first();await expect(punch).toBeDisabled();
 await first.getByRole('button',{name:/Enroll Face$/}).click();await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved',{timeout:25000});
 await expect(first.getByRole('button',{name:/Update Face$/})).toBeVisible();await page.getByRole('button',{name:'Close',exact:true}).click();
 const original=(await(await context.request.get('/fixture-test-api?action=enrollment_status')).json()).data.persons.find(p=>Number(p.member_id)===1).calibration_id;
 await first.getByRole('button',{name:/Update Face$/}).click();await expect(page.locator('#automaticFaceTitle')).toHaveText('Update Face — Public A');await expect(page.locator('.browser-face-feedback p:first-child')).toHaveText('Face saved',{timeout:25000});await page.getByRole('button',{name:'Close',exact:true}).click();
 expect((await(await context.request.get('/fixture-test-api?action=enrollment_status')).json()).data.persons.find(p=>Number(p.member_id)===1).calibration_id).toBe(original);
 await page.reload();await expect(first.getByRole('button',{name:/Update Face$/})).toBeVisible();
 const beforeTests=await(await context.request.get('/fixture-test-counts')).json();await page.getByRole('button',{name:/Scan Arrivals$/}).click();
 await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible({timeout:25000});expect(await(await context.request.get('/fixture-test-counts')).json()).toEqual(beforeTests);expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 declineSelection=true;await page.evaluate(()=>{const scope=angular.element(document.querySelector('[ng-controller="batchAttendanceController"]')).scope();scope.$apply(()=>{scope.timeIndex='1';scope.timeSelected();});});await expect(page.locator('#selectTime')).toHaveValue('0');await expect(first.getByText('Present — Pending Punch',{exact:true})).toBeVisible();declineSelection=false;
 await expect(page.locator('.browser-face-panel').getByRole('button',{name:'Punch Attendance',exact:true})).toBeEnabled();
 let lost=false;await page.route('**/fixture-test-api?action=test_punch',async route=>{if(!lost){lost=true;await route.fetch();return route.fulfill({status:503,contentType:'application/json',body:'{"error":1}'});}return route.continue();});
 await page.locator('.browser-face-panel').getByRole('button',{name:'Punch Attendance',exact:true}).click();await expect(first.locator('.attendance-status')).toHaveText('Present',{timeout:20000});await expect(first.locator('.attendance-test-tag')).toHaveText('Test');await expect(punch).toBeDisabled();
 expect(await(await context.request.get('/fixture-test-counts')).json()).toEqual({attendance:beforeTests.attendance+1,events:beforeTests.events+1});expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 await page.reload();await expect(first.locator('.attendance-status')).toHaveText('Present');await expect(first.locator('.attendance-test-tag')).toHaveText('Test');await expect(first.getByRole('button',{name:/Update Face$/})).toBeVisible();await expect(punch).toBeDisabled();
 expect(rejectedWrites.filter(w=>/insert|update|delete|reset/i.test(w))).toEqual([]);
});
