const {expect}=require('@playwright/test');
const fs=require('fs'),path=require('path');
// Shared by the attendance.php UI specs. Legacy list routes are read fixtures
// whose timing/failures are controlled by the tests; the face API, camera,
// models and TEST punch run the actual code against the disposable fixture.
const date=new Date().toLocaleDateString('en-CA');
const sleep=ms=>new Promise(resolve=>setTimeout(resolve,ms));
async function setup(page,context,{roster:rosterOverride,faceDelay=0,clear=true}={}){
 const login=await context.request.post('/fixture-login?shell=1',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 if(clear)for(const id of [1,2])await context.request.post('/fixture-pending-api?action=delete',{headers:{'X-Face-CSRF':csrf},data:{person_id:id,idempotency_key:'ui_clear_'+id+Date.now()}});
 const roster=(await(await context.request.get('/fixture-test-api?action=roster&batch_id=11&attendance_date='+date)).json()).data;
 const calls={roster:[],punch:0,writes:[]};
 await page.route('https://**',route=>{
  const url=route.request().url();let file;
  if(url.includes('moment.min.js'))file='/tmp/ccc-attendance-ui-moment-2.13.0.js';
  else if(url.includes('jquery.min.js'))file='/tmp/ccc-attendance-ui-jquery-3.2.1.js';
  else if(url.includes('angular-sanitize.min.js'))file='pf/js/angular-sanitize.min.js';
  else if(url.includes('angular.min.js'))file='pf/js/controllers/angular.min.js';
  return file?route.fulfill({contentType:'application/javascript',body:fs.readFileSync(path.resolve(file))}):route.abort();
 });
 await page.route('**/routes/**',async route=>{
  const url=new URL(route.request().url()),action=url.searchParams.get('action'),file=path.basename(url.pathname);
  if(file==='browserFaceRoute.php'){
   if(action==='test_punch')calls.punch++;
   if(faceDelay && ['config','options','migration_status'].includes(action))await sleep(faceDelay);
   url.pathname='/fixture-test-api';return route.continue({url:url.href});
  }
  let data=[],error=0;
  if(file==='batchRoute.php' && action==='select')data=[{id:'11',batch:'Browser fixture',offline:'0',employee:[],batch_time:[{session_id:'7',start_time:'10:00:00',end_time:'11:00:00',module:'',title:'',module_id:'0'},{session_id:'8',start_time:'12:00:00',end_time:'13:00:00',module:'',title:'',module_id:'0'},{session_id:'9',start_time:'14:00:00',end_time:'15:00:00',module:'',title:'',module_id:'0'},{session_id:'10',start_time:'16:00:00',end_time:'17:00:00',module:'',title:'',module_id:'0'}]}];
  else if(file==='memberRoute.php' && action==='selectBatchMember'){
   const body=route.request().postDataJSON() || {};const session=String(body.attendance_session_id);calls.roster.push(session);
   const custom=rosterOverride && await rosterOverride(session,calls.roster.length);
   if(custom && custom.hang)return; // never answered
   if(custom && custom.status)return route.fulfill({status:custom.status,contentType:'text/plain',body:'failure'});
   if(custom && custom.delay)await sleep(custom.delay);
   if(custom && 'error' in custom)return route.fulfill({contentType:'application/json',body:JSON.stringify({error:custom.error,data:[],message:'fixture'})});
   data=roster.persons.map(p=>({...p,fullname:custom && custom.prefix?custom.prefix+p.name:p.name,code:'TEST',item_id:Number(p.id)===1?'21':'22',sessions:'12',sessions_used:'4',start_date:'2000-01-01',end_date:'2100-01-01',negative:1,attendance_id:'0'}));
  }
  else if(file==='settingRoute.php' && action==='getSetting')data=[{back_date_att:'2',back_dated_days:'2',mark_absent:'2'}];
  else if(file==='attendanceRoute.php' && action==='select')error=1;
  else{calls.writes.push(file+':'+action);error=1;}
  try{await route.fulfill({contentType:'application/json',body:JSON.stringify({error,data,message:'Read fixture only'})});}catch{/* page closed */}
 });
 return calls;
}
const rows=page=>page.locator('.attendance-focused-table tbody tr');
const open=(page,time='10:00:00')=>page.goto('/pf/attendance.php?batch_id=11&date='+date+'&time='+time);


module.exports={date,sleep,setup,rows,open};
