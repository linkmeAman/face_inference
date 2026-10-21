// Actual browser worker/models + Python API + encrypted SQLite. No PHP process.
const {chromium}=require('playwright');
const fs=require('fs'),path=require('path'),os=require('os'),net=require('net');
const {spawn,spawnSync}=require('child_process');
const assert=require('node:assert/strict');
const root=path.resolve(__dirname,'../..');
const folder=fs.mkdtempSync(path.join(os.tmpdir(),'face-standalone-ui-'));
const configPath=path.join(folder,'config.json');
const fixture=process.env.FACE_STANDALONE_CAMERA_FIXTURE;
let server,browser;
async function stopServer(){if(!server)return;const process=server;server=null;await new Promise(resolve=>{process.once('exit',resolve);process.kill('SIGTERM');});}
async function startServer(){
    server=spawn('python3',['run.py','serve','--config',configPath],{cwd:root,env:{...process.env,PYTHONDONTWRITEBYTECODE:'1'},stdio:['ignore',fs.openSync(path.join(folder,'server.log'),'a'),fs.openSync(path.join(folder,'server.log'),'a')]});
    for(let i=0;i<100;i++){if(server.exitCode!==null)throw Error('Python service failed to start');try{const result=await fetch(base+'/health');if(result.ok)return;}catch{}await new Promise(resolve=>setTimeout(resolve,100));}
    throw Error('Service startup timed out');
}
let base,config;
async function connect(page){await page.locator('#token').fill(config.api_token);await page.locator('#connect').click();await page.locator('#workspace').waitFor({state:'visible'});}
async function add(page,id,label){await page.locator('#person-id').fill(id);await page.locator('#person-name').fill(label);await page.locator('#add-person').click();await page.getByRole('row').filter({hasText:label}).waitFor();}
async function main(){
    if(!fixture || !fs.existsSync(fixture))throw Error('Set FACE_STANDALONE_CAMERA_FIXTURE to a synthetic test-only Y4M');
    const initialization=spawnSync('python3',['run.py','init','--config',configPath],{cwd:root,encoding:'utf8'});assert.equal(initialization.status,0,initialization.stderr);
    config=JSON.parse(fs.readFileSync(configPath));config.capture_source='test-fixture';
    config.port=await new Promise(resolve=>{const probe=net.createServer();probe.listen(0,'127.0.0.1',()=>{const port=probe.address().port;probe.close(()=>resolve(port));});});
    fs.writeFileSync(configPath,JSON.stringify(config),{mode:0o600});base='http://127.0.0.1:'+config.port;
    await startServer();
    browser=await chromium.launch({headless:true,args:['--use-fake-device-for-media-stream','--use-fake-ui-for-media-stream','--use-file-for-fake-video-capture='+fixture]});
    const page=await browser.newPage({viewport:{width:1100,height:1000}});const errors=[];
    page.on('pageerror',error=>errors.push(error.message));
    await page.goto(base);await connect(page);
    await page.locator('summary').click();await page.locator('#collection-id').fill('demo');await page.locator('#collection-name').fill('Demo collection');await page.locator('#add-collection').click();
    await page.waitForFunction(()=>document.querySelector('#collection').value==='demo');
    await add(page,'a','Synthetic One');await add(page,'b','Synthetic Two');await page.locator('#consent').check();
    let dropped=false;const enrollmentRequests=[];
    await page.route('**/enrollments',async route=>{
        enrollmentRequests.push(route.request().postDataJSON());const response=await route.fetch();
        if(!dropped){dropped=true;await route.abort('connectionfailed');}else await route.fulfill({response});
    });
    await page.getByRole('row').filter({hasText:'Synthetic One'}).getByRole('button',{name:'Enroll',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('#result').textContent.includes('Face saved for evaluation'),null,{timeout:60000});
    assert.ok(dropped);assert.ok(enrollmentRequests.length>=2);assert.deepEqual(enrollmentRequests[0],enrollmentRequests[1]);
    await page.locator('#stop-camera').click();
    await page.getByRole('row').filter({hasText:'Synthetic Two'}).getByRole('button',{name:'Enroll',exact:true}).click();
    await page.waitForFunction(()=>document.querySelector('#result').textContent.includes('already registered'),null,{timeout:60000});
    await page.locator('#stop-camera').click();await page.locator('#recognize').click();
    await page.waitForFunction(()=>document.querySelector('#result').textContent.includes('Evaluation suggestion: Synthetic One'),null,{timeout:60000});
    await page.locator('#stop-camera').click();
    await page.screenshot({path:path.join(folder,'workspace.png'),fullPage:true});
    // A new process and browser document must recover the same SQLite reference.
    await stopServer();await startServer();await page.reload();await connect(page);
    await page.getByRole('row').filter({hasText:'Synthetic One'}).filter({hasText:'PENDING'}).waitFor();
    await page.locator('#recognize').click();await page.waitForFunction(()=>document.querySelector('#result').textContent.includes('Evaluation suggestion: Synthetic One'),null,{timeout:60000});
    await page.locator('#stop-camera').click();
    assert.deepEqual(errors,[]);
    const check=spawnSync('python3',['-c',"import sqlite3,sys,json;d=sqlite3.connect(sys.argv[1]);print(json.dumps({'captures':d.execute('select count(*) from captures').fetchone()[0],'references':d.execute('select count(*) from people where encrypted_template is not null').fetchone()[0]}))",config.database],{encoding:'utf8'});
    const counts=JSON.parse(check.stdout);assert.equal(counts.captures,2);assert.equal(counts.references,1);
    const result={status:'PASS',actual_browser_models:true,python_sqlite_api:true,php_required:false,
        enrollment:true,duplicate_rejection:true,recognition_suggestion:true,response_loss_retry:true,restart_persistence:true,
        captures:counts.captures,references:counts.references,scope:'Synthetic simulated camera; no live accuracy/liveness claim',artifacts:folder};
    fs.writeFileSync(path.join(folder,'result.json'),JSON.stringify(result,null,2),{mode:0o600});console.log(JSON.stringify(result));
}
main().catch(async error=>{if(browser){try{const pages=browser.contexts().flatMap(c=>c.pages());if(pages[0])await pages[0].screenshot({path:path.join(folder,'failure.png'),fullPage:true});}catch{}}console.error(error.message);console.error('Private diagnostics: '+folder);process.exitCode=1;}).finally(async()=>{if(browser)await browser.close();await stopServer();});
