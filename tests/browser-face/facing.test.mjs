import {test} from 'node:test';
import assert from 'node:assert/strict';
import {resolveCameraFacing,isMobileBrowser} from '../../pf/js/browser-face/facing.mjs';
const facing=input=>resolveCameraFacing(input);
test('stream settings are authoritative, including over a contradictory label',()=>{
 assert.deepEqual(facing({settings:{facingMode:'user'}}),{facing:'user',source:'settings'});
 assert.deepEqual(facing({settings:{facingMode:'environment'},label:'Front Camera'}),{facing:'environment',source:'settings'});
 assert.deepEqual(facing({settings:{facingMode:'USER'}}),{facing:'user',source:'settings'});
});
test('a single advertised facing mode is used; several are ambiguous',()=>{
 assert.deepEqual(facing({capabilities:{facingMode:['environment']}}),{facing:'environment',source:'capabilities'});
 assert.deepEqual(facing({capabilities:{facingMode:['user']}}),{facing:'user',source:'capabilities'});
 assert.equal(facing({capabilities:{facingMode:['user','environment']},label:'Back Camera'}).source,'label');
});
test('device labels from Android, iOS Safari and desktop browsers',()=>{
 for(const label of ['Back Camera','Back Dual Wide Camera','Back Ultra Wide Camera','camera2 0, facing back','Rear Camera','Environment facing'])assert.deepEqual(facing({label}),{facing:'environment',source:'label'},label);
 for(const label of ['Front Camera','camera2 1, facing front','FaceTime HD Camera','Integrated Camera','Integrated Webcam','HD Webcam','User facing','Built-in camera'])assert.deepEqual(facing({label}),{facing:'user',source:'label'},label);
 assert.equal(facing({label:'Back Camera (front mount)'}).source,'default'); // contradictory: do not guess from it
});
test('no metadata: phones with several cameras keep the side we asked for; everything else is a front webcam',()=>{
 assert.deepEqual(facing({requested:'environment',deviceCount:2,mobile:true}),{facing:'environment',source:'requested'});
 assert.deepEqual(facing({requested:'user',deviceCount:3,mobile:true}),{facing:'user',source:'requested'});
 assert.deepEqual(facing({requested:'environment',deviceCount:2,mobile:false}),{facing:'user',source:'default'});
 assert.deepEqual(facing({requested:'environment',deviceCount:1,mobile:true}),{facing:'user',source:'default'});
 assert.deepEqual(facing({}),{facing:'user',source:'default'});
 assert.deepEqual(facing(),{facing:'user',source:'default'});
});
test('malformed inputs never throw',()=>{
 for(const input of [{settings:null},{settings:{facingMode:5}},{capabilities:{facingMode:{a:1}}},{label:null},{label:42},{settings:undefined,capabilities:null}])assert.doesNotThrow(()=>facing(input));
});
test('mobile detection covers Android, iPhone, iPadOS desktop mode and excludes desktops',()=>{
 assert.equal(isMobileBrowser({userAgent:'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36'}),true);
 assert.equal(isMobileBrowser({userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 Version/17.5 Mobile/15E148 Safari/604.1'}),true);
 assert.equal(isMobileBrowser({userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15',maxTouchPoints:5}),true);
 assert.equal(isMobileBrowser({userAgent:'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/17.5 Safari/605.1.15',maxTouchPoints:0}),false);
 assert.equal(isMobileBrowser({userAgent:'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/129.0 Safari/537.36'}),false);
 assert.equal(isMobileBrowser(undefined),false);
});
