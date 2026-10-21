const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

let now = 1000;
class FakeDate extends Date { static now() { return now; } }
const calls = [];
const context2d = {
    setTransform(){}, clearRect(){}, beginPath(){}, closePath(){}, stroke(){}, fill(){},
    moveTo(x,y){ calls.push(['move',x,y]); }, lineTo(x,y){ calls.push(['line',x,y]); },
    arc(x,y){ calls.push(['arc',x,y]); }
};
const canvas = {clientWidth:400,clientHeight:300,width:0,height:0,getContext(){return context2d;}};
class FakeWorker {
    constructor(){ FakeWorker.current = this; }
    postMessage(){}
    terminate(){ this.terminated = true; }
}
const scope = {Date:FakeDate,Worker:FakeWorker,createImageBitmap:async()=>({close(){}}),
    setTimeout,clearTimeout,devicePixelRatio:1};
const sandbox = {window:scope,Date:FakeDate,Worker:FakeWorker,performance:{now:()=>now},
    setTimeout,clearTimeout,Math,Number};
vm.runInNewContext(fs.readFileSync('pf/js/faceEnrollmentTracker.js','utf8'),sandbox);
const Tracker = scope.FaceEnrollmentLocalTracker;

function face(noseX=.5, shiftX=0) {
    const points = Array.from({length:478},()=>[.5+shiftX,.5,0]);
    const oval = [10,338,297,332,284,251,389,356,454,323,361,288,397,365,379,378,400,377,
        152,148,176,149,150,136,172,58,132,93,234,127,162,21,54,103,67,109];
    oval.forEach((index,i)=>{
        const theta = 2*Math.PI*i/oval.length;
        points[index] = [.5+shiftX+.25*Math.sin(theta),.5-.35*Math.cos(theta),0];
    });
    points[33]=[.4+shiftX,.42,0]; points[263]=[.6+shiftX,.42,0];
    points[1]=[noseX+shiftX,.52,0];
    return {points,matrix:Array(16).fill(0)};
}
function sample(time, faces=[face()], light=110) {
    now=time+5;
    // The current landmark worker always supplies its edge-gate result.
    return {observedAt:time,faces,light,isValid:true,errors:[]};
}

const tracker = new Tracker({},canvas,'environment',()=>{},()=>{});
tracker.target=0;
tracker.latest=tracker.measure(sample(1000));
assert.strictEqual(tracker.latest.correction,'Hold still.');
tracker.latest=tracker.measure(sample(1150));
assert.strictEqual(tracker.readyForCandidate(),false,'300ms must come from fresh observations');
tracker.latest=tracker.measure(sample(1301));
assert.strictEqual(tracker.readyForCandidate(),true);
now=1800;
assert.strictEqual(tracker.readyForCandidate(),false,'stale observations must not authorize a proposal');

tracker.setTarget(2);
assert.strictEqual(tracker.readyForCandidate(),false,'new target resets the hold');
tracker.latest=tracker.measure(sample(2000,[face(.48)]));
assert.strictEqual(tracker.latest.correction,'Hold still.','correct slight left turn');
tracker.latest=tracker.measure(sample(2350,[face(.48)]));
assert.strictEqual(tracker.readyForCandidate(),true);
tracker.resetHold();
assert.strictEqual(tracker.readyForCandidate(),false,'a server rejection requires a fresh stable observation');
assert.strictEqual(tracker.measure(sample(2400,[face(.5)])).correction,'Turn a little more.');
assert.match(tracker.measure(sample(2500,[face(.45)])).correction,/Turn back/);
tracker.latest=tracker.measure(sample(2600,[face(.48,.18)]));
assert.match(tracker.latest.correction,/centre/,'position must outrank turn');
assert.match(tracker.measure(sample(2700,[face(.48)],20)).correction,/light/);
assert.match(tracker.measure(sample(2800,[face(),face()])).correction,/only one face/);

tracker.latest=tracker.measure(sample(3000,[face(.48)]));
calls.length=0; tracker.draw();
const naturalX=calls.find(c=>c[0]==='move')[1];
const naturalTarget=calls.filter(c=>c[0]==='arc').at(-1)[1];
tracker.facing='user'; calls.length=0; tracker.draw();
const mirroredX=calls.find(c=>c[0]==='move')[1];
const mirroredTarget=calls.filter(c=>c[0]==='arc').at(-1)[1];
assert(Math.abs(naturalX+mirroredX-400)<1,'overlay must mirror with front camera');
assert(naturalTarget < 200 && mirroredTarget > 200,
    'the turn target must move to the matching side of natural and mirrored previews');

let failure=null;
const failed = new Tracker({},canvas,'user',()=>{},reason=>failure=reason);
failed.start();
FakeWorker.current.onmessage({data:{type:'error',reason:'LANDMARKER_INIT_FAILED'}});
assert.strictEqual(failure,'LANDMARKER_INIT_FAILED');
assert.strictEqual(FakeWorker.current.terminated,true,'worker failure must stop local tracking');
console.log('FACE-ENR-UI-27 adaptive tracker PASS');
