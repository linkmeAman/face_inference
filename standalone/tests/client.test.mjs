import assert from 'node:assert/strict';
import {FaceClient} from '../web/client.mjs';

const good=data=>({ok:true,status:200,json:async()=>({error:0,data})});
const nativeFetch=globalThis.fetch;
globalThis.fetch=function(){assert.equal(this,globalThis);return Promise.resolve(good({connected:true}));};
assert.equal((await new FaceClient().get('/api/v1/config')).connected,true);
globalThis.fetch=nativeFetch;
const sent=[];let count=0;
const client=new FaceClient({token:'test-token',fetcher:async(url,options)=>{
    sent.push(JSON.parse(options.body));if(count++===0)throw Error('Response lost');return good({state:'PENDING'});
}});
assert.equal((await client.send('/api/v1/collections/x/enrollments',{capture_session:'test-only'})).state,'PENDING');
assert.equal(sent.length,2);assert.deepEqual(sent[0],sent[1]);assert.equal(client.pending,null);

const offline=new FaceClient({fetcher:async()=>{throw Error('offline');}});
await assert.rejects(offline.send('/api/v1/collections/x/people',{id:'a'}),/Retry save/);
const original=offline.pending;assert.ok(original);
await assert.rejects(offline.send('/other',{}),/previous request/);
offline.fetcher=async()=>good({state:'CREATED'});
assert.equal((await offline.retry()).state,'CREATED');assert.equal(offline.pending,null);

const rejected=new FaceClient({fetcher:async()=>({ok:false,status:400,json:async()=>({error:1,error_msg:'Bad capture',code:'INVALID_VECTOR'})})});
await assert.rejects(rejected.send('/path',{}),/Bad capture/);assert.equal(rejected.pending,null);
console.log('PASS lost response, identical retry payload/key, unresolved-save guard, manual recovery and terminal rejection');
