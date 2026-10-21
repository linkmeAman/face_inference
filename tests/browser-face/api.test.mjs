import {test} from 'node:test';
import assert from 'node:assert/strict';
import {FaceApi} from '../../pf/js/browser-face/api.mjs';
test('transport retry uses exactly the same body and key',async()=>{
    const original=globalThis.fetch,bodies=[];
    globalThis.fetch=async(url,options)=>{bodies.push(options.body);if(bodies.length===1)throw new TypeError('Lost response');return new Response(JSON.stringify({error:0,data:{state:'ENROLLED'}}));};
    try{const result=await new FaceApi('/face','csrf').post('enroll',{idempotency_key:'same_request_001',template:[1,0]});
        assert.equal(result.state,'ENROLLED');assert.equal(bodies.length,2);assert.equal(bodies[0],bodies[1]);
    }finally{globalThis.fetch=original;}
});
test('cancel during retry wait prevents another submission',async()=>{
    const original=globalThis.fetch,abort=new AbortController();let calls=0;
    globalThis.fetch=async()=>{calls++;throw new TypeError('offline');};
    try{const promise=new FaceApi('/face','csrf',()=>setTimeout(()=>abort.abort(),5)).post('attendance',{idempotency_key:'cancel_request_1'},abort.signal);
        await assert.rejects(promise,error=>error.name==='AbortError');assert.equal(calls,1);
    }finally{globalThis.fetch=original;}
});
test('authorization errors do not retry',async()=>{
    const original=globalThis.fetch;let calls=0;
    globalThis.fetch=async()=>{calls++;return new Response(JSON.stringify({error:1,error_msg:'CSRF_REQUIRED'}),{status:403});};
    try{await assert.rejects(new FaceApi('/face','csrf').post('delete',{}),/CSRF_REQUIRED/);assert.equal(calls,1);}
    finally{globalThis.fetch=original;}
});

test('GET startup/config requests have a cancellable timeout and clean up their timer',async()=>{
 const fetch=globalThis.fetch,timeout=globalThis.setTimeout,clear=globalThis.clearTimeout;let expire,cleared=false;
 globalThis.setTimeout=callback=>{expire=callback;return 91;};globalThis.clearTimeout=id=>{if(id===91)cleared=true;};
 globalThis.fetch=async(url,{signal})=>new Promise((resolve,reject)=>signal.addEventListener('abort',()=>reject(new DOMException('Timeout','AbortError'))));
 try{const request=new FaceApi('/face','test').get('config');expire();await assert.rejects(request,/Connection interrupted/);assert.equal(cleared,true);}finally{globalThis.fetch=fetch;globalThis.setTimeout=timeout;globalThis.clearTimeout=clear;}
});

test('server errors preserve unknown outcome, same retry payload and distinct safe feedback',async()=>{
 const fetch=globalThis.fetch,bodies=[],statuses=[];
 globalThis.fetch=async(url,options)=>{bodies.push(options.body);return new Response(JSON.stringify({error:1,error_msg:'Unavailable'}),{status:503});};
 try{await assert.rejects(new FaceApi('/face','csrf',s=>statuses.push(s)).post('attendance',{idempotency_key:'same_server_error_key'}),error=>error.httpStatus===503 && error.confirmed===false);assert.equal(bodies.length,3);assert.equal(new Set(bodies).size,1);assert.deepEqual(statuses,['Server unavailable. Reconnecting...','Server unavailable. Reconnecting...']);}finally{globalThis.fetch=fetch;}
});
