import {AutomaticCapture} from '/pf/js/browser-face/capture.mjs';
import {FaceClient} from './client.mjs';

const $=id=>document.getElementById(id);
let client,config,capture,generation=0,currentCollection='',operation=null;
function message(value){$('result').textContent=value;}
function error(error){message(error.message || String(error));$('retry').hidden=!client?.pending;}
function path(resource,collection=currentCollection){return '/api/v1/collections/'+encodeURIComponent(collection)+'/'+resource;}
function guard(){if(client?.pending)throw Error('Retry the unresolved save before changing person or collection');if(capture?.active)throw Error('Stop the current camera before changing person or collection');}
function stop(){generation++;capture?.stop();capture=null;operation=null;$('camera-panel').hidden=true;}
function ready(){return !!client && !!currentCollection;}
async function refreshPeople(){
    const selected=currentCollection;if(!selected){$('people').replaceChildren();return;}
    const people=await client.get(path('people',selected));if(selected!==currentCollection)return;
    $('people').replaceChildren();$('empty').hidden=people.length>0;
    for(const person of people){
        const row=document.createElement('tr');
        for(const value of [person.name,person.id,person.state]){const cell=document.createElement('td');cell.textContent=value;row.append(cell);}
        const actions=document.createElement('td');
        for(const [label,handler] of [
            ['Enroll',()=>start('enroll',person)],
            ['Check face',()=>start('observe',person)],
            ['Delete face',async()=>{guard();if(!confirm('Delete the saved face and capture evidence for '+person.name+'?'))return;await client.send(path('people/'+encodeURIComponent(person.id)+'/face'),{},'DELETE');message('Face data deleted.');await refreshPeople();}]
        ]){const button=document.createElement('button');button.type='button';button.className='secondary';button.textContent=label;button.addEventListener('click',()=>Promise.resolve().then(handler).catch(error));actions.append(button);}
        row.append(actions);$('people').append(row);
    }
}
async function refreshCollections(preferred=currentCollection){
    const items=await client.get('/api/v1/collections');$('collection').replaceChildren();
    for(const item of items){const option=document.createElement('option');option.value=item.id;option.textContent=item.name;$('collection').append(option);}
    currentCollection=items.some(item=>item.id===preferred)?preferred:items[0]?.id || '';
    $('collection').value=currentCollection;$('recognize').disabled=!currentCollection;await refreshPeople();
}
function showResult(result){
    const text={PENDING:'Face saved for evaluation. Approval is pending.',ACTIVE:'Face enrolled.',REJECTED_DUPLICATE:result.ambiguous?'This capture conflicts with multiple saved people.':'This face is already registered to another person.',OBSERVATION_SAVED:'Labeled observation saved without changing the enrolled reference.',UNKNOWN:'Face not recognized. '+(result.reason || ''),COLLECTION_ONLY:'This workspace is configured for collection only.'};
    message(result.state==='SUGGESTED'?'Evaluation suggestion: '+result.person_name+' (unverified)':result.state==='MATCHED'?'Matched: '+result.person_name:text[result.state] || result.state);
    window.dispatchEvent(new CustomEvent('face-result',{detail:result}));
}
async function start(mode,person=null){
    guard();if(!ready())throw Error('Select a collection first');if(person && !$('consent').checked)throw Error('Confirm consent for the selected person before capture');
    const own=++generation,collection=currentCollection,captureSession=crypto.randomUUID();
    operation={mode,collection,person};$('camera-panel').hidden=false;$('progress').value=0;$('camera-title').textContent=person?'Capture '+person.name:'Recognize in '+$('collection').selectedOptions[0].textContent;
    let held='';
    capture=new AutomaticCapture($('video'),$('overlay'),{...config.contract,diagnostics:false},{
        status:value=>{if(own===generation && !held)$('camera-status').textContent=value;},
        progress:value=>{if(own===generation)$('progress').value=value;},
        camera:camera=>{if(own===generation)$('switch-camera').disabled=!camera.canSwitch;},
        trackReset:()=>{held='';},
        error:value=>{if(own===generation)error(Error(value));},
        batch:async batch=>{
            const resource=mode==='enroll'?'enrollments':mode==='observe'?'observations':'recognitions';
            const body={capture_session:mode==='recognize'?crypto.randomUUID():captureSession,observations:batch.observations,model_version:config.contract.model_version,preprocessing_version:config.contract.preprocessing_version};
            if(person){
                const settings=$('video').srcObject?.getVideoTracks()[0]?.getSettings() || {};
                const bytes=await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify({device:settings.deviceId || '',width:settings.width,height:settings.height,facing:settings.facingMode || ''})));
                const device='camera-'+Array.from(new Uint8Array(bytes)).map(value=>value.toString(16).padStart(2,'0')).join('').slice(0,24);
                Object.assign(body,{person_id:person.id,consent:true,template:batch.template,device,
                    lighting:batch.brightness<70?'measured-dark':batch.brightness>180?'measured-bright':'measured-normal'});
            }
            if(own!==generation)return false;
            try{
                const result=await client.send(path(resource,collection),body);
                if(own!==generation)return false;
                showResult(result);
                try{await refreshPeople();}catch{message($('result').textContent+' Saved status is confirmed; refresh the list when connected.');}
                if(mode!=='recognize'){held=$('result').textContent;$('camera-status').textContent=held;return false;}
                if(['MATCHED','SUGGESTED'].includes(result.state)){held=$('result').textContent;$('camera-status').textContent=held;return {waitForDeparture:true};}
                return true;
            }catch(failure){if(own===generation){error(failure);$('camera-status').textContent=failure.message;}return client.pending?{pending:true}:false;}
        }
    });
    await capture.start(mode==='recognize'?'attendance':'enroll');
}
function bind(id,callback){$(id).addEventListener('click',()=>Promise.resolve().then(callback).catch(error));}
bind('connect',async()=>{guard();const token=$('token').value.trim();if(!token)throw Error('Enter your private API token');const next=new FaceClient({token});const nextConfig=await next.get('/api/v1/config');client=next;config=nextConfig;$('token').value='';$('connection').hidden=true;$('workspace').hidden=false;$('notice').hidden=false;$('notice').textContent=config.approved?'Approved matching policy loaded. Use this workspace only for the policy’s validated scope.':config.mode==='COLLECT'?'Collection mode: faces are saved; recognition is unavailable.':'Evaluation mode: recognition suggestions are provisional and unverified. They do not establish liveness or trigger actions.';await refreshCollections();message('Connected.');});
bind('add-collection',async()=>{guard();const id=$('collection-id').value.trim();await client.send('/api/v1/collections',{id,name:$('collection-name').value.trim()});await refreshCollections(id);message('Collection created.');});
bind('add-person',async()=>{guard();if(!ready())throw Error('Create a collection first');await client.send(path('people'),{id:$('person-id').value.trim(),name:$('person-name').value.trim(),metadata:{}});await refreshPeople();message('Person saved.');});
bind('refresh',async()=>{guard();await refreshCollections();});
bind('recognize',()=>start('recognize'));
bind('switch-camera',async()=>{await capture?.switchCamera();});
bind('stop-camera',()=>{stop();message(client?.pending?'Camera stopped. A submitted request may have saved; use Retry save.':'Camera stopped.');});
bind('retry',async()=>{const result=await client.retry();$('retry').hidden=true;stop();showResult(result);await refreshCollections();});
$('collection').addEventListener('change',()=>{const selected=$('collection').value;Promise.resolve().then(()=>{guard();currentCollection=selected;return refreshPeople();}).catch(failure=>{$('collection').value=currentCollection;error(failure);});});
document.addEventListener('visibilitychange',()=>capture?.setVisibility(document.hidden));
window.addEventListener('pagehide',stop);
window.addEventListener('beforeunload',event=>{if(client?.pending){event.preventDefault();event.returnValue='';}});
