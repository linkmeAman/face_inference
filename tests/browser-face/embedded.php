<?php
// Shared production Angular bridge/panel, real API and real models. CLI test host only.
if(PHP_SAPI!=='cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
session_start();$csrf=htmlspecialchars($_SESSION['face_csrf'] ?? '',ENT_QUOTES,'UTF-8');
?>
<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="face-csrf" content="<?php echo $csrf; ?>"><link rel="stylesheet" href="/pf/css/browser-face-embedded.css"><script src="/pf/js/controllers/angular.min.js"></script></head>
<body><main ng-controller="TestAttendance">
<select id="session" ng-model="selectedSession"><option value="7">10:00</option><option value="8">12:00</option><option value="9">14:00</option></select>
<button id="scan" ng-click="openAutomaticFace('attendance')">Scan Arrivals</button>
<p>Clicking Enroll Face confirms the person's consent to encrypted face enrollment and matching setup. Pending setup samples are retained for 30 days.</p>
<div ng-repeat="row in list"><span id="row-{{row.contact_id}}">{{row.fullname}} {{row.browser_face_enrolled ? 'Face ready' : row.browser_face_pending ? 'Face captured; enrollment will finish automatically' : 'Not enrolled'}} {{row.attendance_id ? 'Recorded' : 'Unmarked'}} Used {{row.sessions_used}}</span><button id="enroll-{{row.id}}" ng-click="openAutomaticFace('enroll', row)">{{faceCaptureButtonLabel(row)}}</button><span id="test-state-{{row.contact_id}}">{{row.browser_face_test_attendance_state}}</span></div>
<button id="test-punch" ng-disabled="faceTestPunchBusy || ((!faceTestPending || !faceTestPending.length) && !faceTestPunchRequest)" ng-click="punchFaceTestAttendance()">Punch Attendance</button>
<?php include dirname(__DIR__,2).'/pf/inc/browser-face-panel.php'; ?>
</main><script type="module">
import {installAttendanceFace} from '/pf/js/browser-face/attendance.mjs';
angular.module('faceEmbeddedTest',[]).controller('TestAttendance',function($scope){
 window.testScope=$scope;$scope.selectedSession=new URLSearchParams(location.search).get('session') || '7';$scope.list=[];
 window.testFlow=installAttendanceFace($scope,{csrf:document.querySelector('meta[name=face-csrf]').content,url:new URLSearchParams(location.search).has('testing')?'/fixture-test-api':new URLSearchParams(location.search).has('shadow')?'/fixture-shadow-api':new URLSearchParams(location.search).has('pending')?'/fixture-pending-api':'/fixture-api',video:document.getElementById('automaticFaceVideo'),canvas:document.getElementById('automaticFaceOverlay'),notify:message=>{$scope.$evalAsync(()=>{$scope.notice=message;});},
 getContext:()=>({batch_id:11,date:new Date().toLocaleDateString('en-CA'),session_id:Number($scope.selectedSession),time:$scope.selectedSession==='7'?'10:00:00':$scope.selectedSession==='9'?'14:00:00':'12:00:00'})});
 testFlow.api.get('roster',{batch_id:11,attendance_date:new Date().toLocaleDateString('en-CA')}).then(result=>$scope.$evalAsync(()=>{$scope.list=result.persons.map(p=>({...p,fullname:p.name,sessions_used:Number(p.sessions_used),attendance_id:0}));})).then(async()=>{const opts=await testFlow.api.get('options',{batch_id:11});$scope.$evalAsync(()=>{for(const row of $scope.list){const p=opts.persons.find(p=>Number(p.id)===Number(row.id));row.browser_face_test_ready=p?.test_ready===true;}});if(new URLSearchParams(location.search).has('testing'))await $scope.refreshFaceTestAttendance();});
 // Like attendance.php, a session change re-reads that session's saved test attendance.
 $scope.$watch('selectedSession',(next,old)=>{if(next!==old && new URLSearchParams(location.search).has('testing'))$scope.refreshFaceTestAttendance();});
});angular.bootstrap(document,['faceEmbeddedTest']);
</script></body></html>
