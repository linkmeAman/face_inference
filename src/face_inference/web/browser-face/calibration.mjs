import {EmbeddedFaceFlow} from './embedded.mjs';
const $=id=>document.getElementById(id);
const flow=new EmbeddedFaceFlow($('video'),$('overlay'),{csrf:document.querySelector('meta[name=face-csrf]').content,
 change:state=>{$('status').textContent=state.status;$('error').textContent=state.error;$('progress').value=state.progress;$('retry').hidden=!state.retry;for(const id of ['person','visit','device','lighting','split','consent','collect','erase'])$(id).disabled=state.busy || !!flow.pending;},
 result:async()=>{await refresh();await flow.close();flow.update({status:'Calibration observation saved securely; no enrollment or attendance created'});}});
async function refresh(){const result=await flow.api.get('calibration_progress');$('counts').textContent=`${result.identities}/20 identities; ${result.samples}/100 observations; ${result.validation_samples} independent validation observations. Retention: 30 days.`;
 $('observations').replaceChildren(...result.observations.slice(0,100).map(row=>{const item=document.createElement('li');item.textContent=`Person ${row.member_id}: ${row.visit}, ${row.device}, ${row.lighting}, ${row.dataset_split}; expires ${row.expires_at}`;return item;}));}
$('collect').onclick=async()=>{if(!$('person').value || !$('consent').checked || ['visit','device','lighting'].some(id=>!$(id).value.trim())){$('error').textContent='Select a consenting person and enter the actual visit, device and lighting.';return;}
 await flow.open({mode:'calibration',person_id:Number($('person').value),consent:true,visit:$('visit').value.trim(),device:$('device').value.trim(),lighting:$('lighting').value.trim(),split:$('split').value});};
$('erase').onclick=async()=>{if(!$('person').value)return;try{await flow.close();await flow.api.post('calibration_delete',{person_id:Number($('person').value),idempotency_key:crypto.randomUUID()});await refresh();$('status').textContent='Calibration observations erased';}catch(error){$('error').textContent=error.message;}};
$('stop').onclick=()=>flow.close();$('retry').onclick=()=>flow.retry();
try{await flow.initialize();const result=await flow.api.get('options');for(const person of result.persons)$('person').add(new Option(person.name,person.id));await refresh();}catch(error){$('error').textContent=error.message;}
