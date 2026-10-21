import {test} from 'node:test';
import assert from 'node:assert/strict';
import {responseMessage,errorMessage} from '../../pf/js/browser-face/messages.mjs';
test('actual PHP lifecycle outcomes have truthful operator messages',()=>{
 for(const state of ['DUPLICATE','REJECTED_DUPLICATE'])assert.equal(responseMessage({state,message:'private details'}),'Face already registered');
 for(const state of ['PENDING','ENROLLMENT_PENDING'])assert.equal(responseMessage({state}),'Face saved · Setup pending');
 for(const state of ['ACTIVE','ENROLLED','ALREADY_ENROLLED'])assert.equal(responseMessage({state}),'Face enrolled');
 assert.equal(responseMessage({state:'ACTIVE',enrollment_state:'PENDING'}),'Face saved · Setup pending');
 assert.equal(responseMessage({state:'RECORDED',person_name:'Authorized member'}),'Attendance marked for Authorized member.');
 assert.equal(responseMessage({state:'REJECTED',message:'raw internal failure'}),'Unable to mark attendance. Please use manual attendance.');
 assert.equal(responseMessage({state:'UNEXPECTED',message:'SQL PRIVATE'}),'Please try again');
});
test('transport, session and camera failures are sanitized rather than falsely successful',()=>{
 assert.equal(errorMessage({code:'CSRF_REQUIRED',message:'private'}),'Session changed or expired. Please reopen the scanner.');
 assert.equal(errorMessage('Connection interrupted; result may be saved'),'Connection lost');
 assert.equal(errorMessage({name:'NotAllowedError'}),'Camera unavailable. Allow camera access.');
 assert.equal(errorMessage('Camera stopped. Tap Retry to continue.'),'Camera stopped. Tap Retry.');
 assert.equal(errorMessage('PRIVATE SQL / stack trace'),'Please try again');
});

test('server and rate-limit responses are not mislabeled as lost connectivity',()=>{
 assert.equal(errorMessage({httpStatus:503,confirmed:false}),'Server busy. Please try again.');
 assert.equal(errorMessage({httpStatus:429,confirmed:false}),'Please wait a moment, then tap Retry.');
});

test('real-batch shadow and restricted pilot results are clear and never claim attendance',()=>{
 assert.equal(responseMessage({state:'PENDING',reason:'PILOT_NOT_APPROVED'}),'Face captured. Only Test One can be scanned in this practice session.');
 assert.equal(responseMessage({state:'SHADOW_EVALUATED',person_name:'Candidate'}),'Face check complete. No attendance was marked.');
 assert.equal(responseMessage({state:'SCAN_MODE_CHANGED'}),'Face scanning changed. Please reopen the scanner.');
 assert.equal(errorMessage('This face was captured today. Please check it at a later visit.'),'Face already captured today. Check it at a later visit.');
 assert.equal(responseMessage({state:'SHADOW_EVALUATED',diagnostics:{decision:'NO_ELIGIBLE_REFERENCE'}}),'No enrolled faces are available in this class. Enroll a member first.');
 assert.equal(errorMessage('Batch is not authorized on this date'),'Select a current class and session, then try again.');
});
test('duplicate identity disclosure requires explicit server visibility flag',()=>{
 assert.equal(responseMessage({state:'REJECTED_DUPLICATE',duplicate_person_name:'Private child'}),'Face already registered');
 assert.equal(responseMessage({state:'REJECTED_DUPLICATE',duplicate_visible:true,duplicate_person_name:'Child A'}),'Face already registered to Child A');
 assert.equal(responseMessage({state:'REJECTED_DUPLICATE',duplicate_visible:true,duplicate_person_name:'Child A',duplicate_branch_name:'Testing'}),'Face already registered to Child A at Testing');
});

test('field disablement uses one actionable operator message without exposing internals',()=>{assert.equal(errorMessage({code:'FIELD_RELEASE_DISABLED'}),'Face capture and scanning are not enabled for this class. Please use manual attendance.');assert.equal(errorMessage(Error('Face capture and scanning are not enabled for this class. Please use manual attendance.')),'Face capture and scanning are not enabled for this class. Please use manual attendance.');});
 test('Testing messages distinguish persistence, activation and non-writing recognition',()=>{
  assert.equal(responseMessage({state:'PENDING',test_ready:true}),'Face saved');
  assert.equal(responseMessage({state:'ACTIVE',enrollment_state:'ACTIVE'}),'Face enrolled');
  assert.equal(responseMessage({state:'RECOGNIZED',person_name:'Child A'}),'Child A recognized · Marked Present');
  assert.equal(responseMessage({state:'TEST_RECOGNIZED',person_name:'Child A'}),'Child A recognized');
  assert.equal(responseMessage({state:'UNKNOWN',reason:'AMBIGUOUS'}),"Couldn't identify. Try again.");
  assert.equal(responseMessage({state:'TEST_UNKNOWN',reason:'AMBIGUOUS'}),"Couldn't identify. Try again.");
  assert.equal(responseMessage({state:'UNKNOWN',reason:'BELOW_CUTOFF'}),'Face not recognized');
  assert.equal(responseMessage({state:'TEST_UNKNOWN',reason:'BELOW_CUTOFF'}),'Face not recognized');
 });

test('empty Testing roster gives actionable enrollment guidance',()=>{assert.equal(errorMessage('Capture a face for an eligible member before scanning.'),'No saved faces are available in this class. Enroll a member first.');assert.equal(errorMessage('Enroll a member before scanning.'),'No saved faces are available in this class. Enroll a member first.');});

test('ambiguous enrollment never guesses a duplicate name; conflicts are not test-ready success',()=>{
 assert.equal(responseMessage({state:'REJECTED_DUPLICATE',capture_decision:'AMBIGUOUS_DUPLICATE',duplicate_visible:true,duplicate_person_name:'Do not guess'}),"Couldn't verify face. Please try again.");
 assert.equal(responseMessage({state:'PENDING',test_gallery_conflict:true}),'Face saved · Needs attention');
 assert.equal(responseMessage({state:'TEST_UNKNOWN',reason:'GALLERY_CONFLICT'}),"Couldn't identify. Try again.");
});
