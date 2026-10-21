const {test,expect}=require('@playwright/test');
const fs=require('fs');
test('serving real controller enforces field emergency stop and broad Attendance-authorized shadow without LIVE',async({context})=>{
 const file=process.env.FACE_WEB_ROLLOUT_FILE;
 if(file!=='/tmp/ccc-browser-field-fixture.json')throw Error('Exact private disposable rollout file required');
 const original=fs.readFileSync(file);
 const login=await context.request.post('/fixture-login',{headers:{'X-Test-Key':process.env.FACE_BROWSER_TEST_KEY}});expect(login.ok()).toBe(true);const {csrf}=await login.json();
 const before=await(await context.request.get('/fixture-counts')).json();
 try{
  fs.writeFileSync(file,JSON.stringify({version:'browser-face-rollout-v1',default_mode:'DISABLED',enabled:true,scopes:[]}));
  const config=(await(await context.request.get('/fixture-shadow-api?action=config&batch_id=11')).json()).data;
  expect(config.operating_mode).toBe('DISABLED');expect(config.enrollment_collection_available).toBe(false);expect(config.scanning_available).toBe(false);
  for(const action of ['enroll','open','attendance']){
   const r=await context.request.post('/fixture-shadow-api?action='+action,{headers:{'X-Face-CSRF':csrf},data:{batch_id:11,idempotency_key:'field_direct_block_'+action}});
   expect(r.status()).toBe(400);expect((await r.json()).error_msg).toBe('FIELD_RELEASE_DISABLED');
  }
  expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
  fs.writeFileSync(file,JSON.stringify({version:'browser-face-rollout-v1',default_mode:'SHADOW',enabled:true,scopes:[]}));
  const enabled=(await(await context.request.get('/fixture-shadow-api?action=config&batch_id=11')).json()).data;
  expect(enabled.operating_mode).toBe('SHADOW');expect(enabled.enrollment_collection_available).toBe(true);expect(enabled.acceptance_ready).toBe(false);
  // Old face-specific allowlists must not overrule actual central Attendance access.
  const today=new Date().toISOString();
  fs.writeFileSync(file,JSON.stringify({version:'browser-face-rollout-v1',default_mode:'DISABLED',enabled:true,scopes:[{database:process.env.FACE_BROWSER_TEST_DB,branch_id:2,batch_id:11,mode:'SHADOW',operator_ids:[],approved_by:'disposable-api-regression',approved_at:today,expires_at:new Date(Date.now()+3600000).toISOString()}]}));
  const scoped=(await(await context.request.get('/fixture-shadow-api?action=config&batch_id=11')).json()).data;
  expect(scoped.operating_mode).toBe('SHADOW');expect(scoped.scanning_available).toBe(true);expect(scoped.acceptance_ready).toBe(false);
  expect((await(await context.request.get('/fixture-shadow-api?action=config&batch_id=12')).json()).data.operating_mode).toBe('DISABLED');
  fs.writeFileSync(file,'broken configuration');
  expect((await(await context.request.get('/fixture-shadow-api?action=config&batch_id=11')).json()).data.operating_mode).toBe('DISABLED');
  expect(await(await context.request.get('/fixture-counts')).json()).toEqual(before);
 }finally{fs.writeFileSync(file,original);}
});
