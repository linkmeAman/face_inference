import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {AutomaticCapture} from '../../pf/js/browser-face/capture.mjs';
import {continuityDecision,continuityPolicy} from '../../pf/js/browser-face/continuity.mjs';
const base=JSON.parse(readFileSync(new URL('../../pf/js/browser-face/contract.json',import.meta.url)));
// Unchanged genuine-only pilot settings, not biometric accuracy evidence.
const policy={same_min:.746782791039185,different_max:.6513243536866755,observations:3};
const A=[1,0],B=[0,1],uncertain=[.7,Math.sqrt(1-.7**2)],box={x:240,y:160,width:100,height:100};
async function harness(run){
 const progress=[],status=[],states=[],batches=[];
 const capture=new AutomaticCapture({}, {getContext:()=>({clearRect(){}})}, {...base,dimension:2,capture_continuity:policy},
  {status:v=>status.push(v),progress:v=>progress.push(v),captureState:v=>states.push(v),batch:b=>{batches.push(b);return false;},error:e=>{throw Error(e);}});
 capture.draw=()=>{};capture.mode='enroll';capture.active=true;capture.started=performance.now();
 const feed=async(embedding=A,reason='SUITABLE',other={})=>{capture.lastSample=0;await capture.observe({face_count:reason==='NO_FACE'?0:1,box,embedding:reason==='SUITABLE'?embedding:null,quality_reason:reason,...other});clearTimeout(capture.timer);};
 try{await run({capture,feed,progress,status,states,batches});}finally{capture.stop();}
}
test('normal five suitable observations finish, normalized and isolated from input buffers',()=>harness(async h=>{
 const mutable=[1,0];await h.feed(mutable);mutable[0]=0;assert.deepEqual(h.capture.samples[0],A);
 for(let i=0;i<4;i++)await h.feed();assert.equal(h.batches.length,1);assert.deepEqual(h.batches[0].observations,Array(5).fill(A));assert.deepEqual(h.progress,[.2,.4,.6,.8,1]);
}));
test('3/5 survives face loss; three suitable returns verify before 80%',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();await h.feed(null,'NO_FACE');assert.equal(h.capture.samples.length,3);assert.equal(h.progress.at(-1),.6);assert.equal(h.capture.captureState,'PAUSED_NO_FACE');
 for(let i=0;i<2;i++){await h.feed();assert.equal(h.progress.at(-1),.6);}assert.equal(h.capture.captureState,'VERIFYING_RETURNING_FACE');
 await h.feed();assert.equal(h.progress.at(-1),.8);await h.feed();assert.equal(h.batches.length,1);assert.deepEqual(h.batches[0].observations,Array(5).fill(A));
}));
test('outside guide, competing faces, brief darkness and blur preserve samples without accepting frames',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();
 for(const reason of ['OUTSIDE_GUIDE','TOO_DARK','BLUR','MULTIPLE_FACES']){await h.feed(B,reason,reason==='MULTIPLE_FACES'?{face_count:2}:{});assert.equal(h.capture.samples.length,3);assert.equal(h.progress.at(-1),.6);}
 for(let i=0;i<3;i++)await h.feed();assert.equal(h.progress.at(-1),.8);
}));
test('a coherent wrong face is refused even in the same box; original returns without contaminating samples',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();
 for(let i=0;i<8;i++)await h.feed(B);assert.equal(h.capture.captureState,'DIFFERENT_FACE_DETECTED');assert.equal(h.progress.at(-1),.6);assert.equal(h.capture.samples.length,3);assert.equal(h.capture.returning.length,3);assert.match(h.status.at(-1),/original face/);
 await h.feed(null,'NO_FACE');for(let i=0;i<3;i++)await h.feed();assert.equal(h.progress.at(-1),.8);await h.feed();assert.deepEqual(h.batches[0].observations,Array(5).fill(A));
}));
test('uncertain returns and mixed probes never advance progress or move identity anchor',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();await h.feed(null,'NO_FACE');
 for(let i=0;i<5;i++)await h.feed(uncertain);assert.equal(h.capture.captureState,'PAUSED_IDENTITY_UNCERTAIN');assert.equal(h.progress.at(-1),.6);
 await h.feed(A);await h.feed(B);await h.feed(A);assert.equal(h.progress.at(-1),.6);assert.deepEqual(h.capture.samples,Array(3).fill(A));
}));
test('position/time tracking alone cannot verify identity; stale layout pauses and preserves',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();h.capture.guideKey='current';await h.feed(B,'SUITABLE',{guide_key:'old'});assert.equal(h.progress.at(-1),.6);assert.equal(h.capture.needsVerification,true);
 await h.feed(B,'SUITABLE',{box:{...box,x:400}});assert.equal(h.progress.at(-1),.6);
}));
test('unconfigured continuity fails closed after loss; explicit reset frees all partial biometrics',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();h.capture.config={...h.capture.config,capture_continuity:null};await h.feed(null,'NO_FACE');for(let i=0;i<10;i++)await h.feed();assert.equal(h.progress.at(-1),.6);assert.equal(h.capture.returning.length,3);assert.match(h.status.at(-1),/Retry/);
 h.capture.reset();assert.equal(h.progress.at(-1),0);assert.equal(h.capture.samples.length,0);assert.equal(h.capture.returning.length,0);
}));
test('cancel/new member cannot reuse unfinished observations and async completion cannot revive closed session',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();await h.feed(B);h.capture.stop();assert.equal(h.capture.samples.length,0);assert.equal(h.capture.returning.length,0);await h.feed();assert.equal(h.batches.length,0);assert.equal(h.capture.samples.length,0);
}));
test('pause cancels collection deadline; no backend submit until five verified accepted samples',()=>harness(async h=>{
 for(let i=0;i<3;i++)await h.feed();await h.feed(null,'NO_FACE');assert.equal(h.capture.batches,undefined);assert.equal(h.batches.length,0);assert.equal(h.capture.processing,false);assert.equal(h.capture.needsVerification,true);
}));
test('multi-observation decision requires every retained reference and coherent returning sequence',()=>{
 assert.equal(continuityDecision([A,A,A],[A,A],policy),'VERIFYING');assert.equal(continuityDecision([A,A,A],[A,A,A],policy),'SAME');
 assert.equal(continuityDecision([A,A,A],[B,B,B],policy),'DIFFERENT');assert.equal(continuityDecision([A,A,A],[uncertain,uncertain,uncertain],policy),'UNCERTAIN');
 assert.equal(continuityDecision([A,A,B],[A,A,A],policy),'UNCERTAIN');assert.equal(continuityDecision([A],[A,B,A],policy),'UNCERTAIN');
 for(const capture_continuity of [null,{...policy,same_min:NaN},{...policy,different_max:.99},{...policy,observations:1}])assert.equal(continuityPolicy({capture_continuity}),null);
});
