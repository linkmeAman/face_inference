
app.controller('batchAttendanceController',function($scope,apiCall,$http){
    // Practice mode keeps all mutations in the guarded face API/database.
    $scope.browserFacePilot=window.browserFacePilot===true;
    if($scope.browserFacePilot){
        var pilotHttp=$http,productionApi=apiCall;
        $http=function(){return Promise.reject(Error('Use the practice camera controls.'));};
        apiCall=Object.create(productionApi);
        apiCall.callEndpoint=function(request,route,action,headers){
            var contextDate=moment($scope.batch.date,'DD-MM-YYYY').format('YYYY-MM-DD');
            var readUrl='../routes/browserFaceRoute.php?pilot=1&attendance_date='+contextDate+'&session_id='+Number($scope.batch.session_id || 0);
            if(route==='batchRoute.php' && action==='select')return pilotHttp.get(readUrl+'&action=pilot_batches').then(function(response){return {data:{error:response.data.error || 0,data:response.data.data.records || []}};});
            if(route==='memberRoute.php' && action==='selectBatchMember')return pilotHttp.get(readUrl+'&action=pilot_roster').then(function(response){return {data:{error:response.data.error || 0,data:response.data.data.records || []}};});
            if(route==='attendanceRoute.php' && action==='select')return pilotHttp.get(readUrl+'&action=pilot_attendance').then(function(response){
                var rows=response.data.data.records || [];var filters=request.filter || [];
                filters.forEach(function(filter){if(filter[0]==='status' && filter[1]==='in')rows=rows.filter(function(row){return filter[2].map(String).indexOf(String(row.status))>=0;});});
                return {data:{error:rows.length?0:1,data:rows}};
            });
            if((route==='batchRoute.php' && action==='select') || (route==='settingRoute.php' && action==='getSetting'))return productionApi.callEndpoint(request,route,action,headers).then(function(response){
                if(route==='batchRoute.php')response.data.data=(response.data.data || []).filter(function(batch){return Number(batch.id)===33;}).map(function(batch){batch.employee=[];return batch;});return response;
            });
            return Promise.reject(Error('This control is unavailable during practice attendance.'));
        };
    }
    $scope.batch = {};
    $scope.asst_trainer_arr={};
    var today = moment().format("DD-MM-YYYY");
    var attendanceSelectionStorageKey = 'batchAttendanceSelection:'
        + String(session && session.bid ? session.bid : 'branch')
        + ':' + String(session && session.user_id ? session.user_id : 'employee');
    var legacyFaceQuery=new URLSearchParams(window.location.search), legacyFaceOpened=false;
    var savedAttendanceSelection = $scope.browserFacePilot?{batch_id:'33',date:today,time_index:0}:readAttendanceSelection();
    if(!$scope.browserFacePilot && /^\d+$/.test(legacyFaceQuery.get('batch_id') || '') && moment(legacyFaceQuery.get('date'),'YYYY-MM-DD',true).isValid())savedAttendanceSelection={batch_id:legacyFaceQuery.get('batch_id'),date:moment(legacyFaceQuery.get('date'),'YYYY-MM-DD').format('DD-MM-YYYY'),time_index:null};
    $scope.batch.date = savedAttendanceSelection && savedAttendanceSelection.date
        ? savedAttendanceSelection.date
        : today;

    //methods
    $scope.selectedBatch = selectedBatch;
    $scope.punchAttendance = punchAttendance;
    $scope.reschedule = reschedule;
    $scope.dateChange = dateChange;
    $scope.selectMembers = selectMembers;
    $scope.selectAll = selectAll;
    $scope.punch = punch;
    $scope.cancelBatch = cancelBatch;
    $scope.selectDeselectItem = selectDeselectItem;
    // $scope.selectCheck = selectCheck;
    $scope.timeSelected = timeSelected;
    $scope.selectSkip = selectSkip;
    $scope.open_modal = open_modal;
    $scope.update_support = update_support;
    $scope.mark_absent = false;
    $scope.att_id = '0';

    $scope.disableBatch = true;
    $scope.preLoadDivShow = false;
    $scope.postLoadDivShow = false;
    $scope.noResults = false;
    $scope.defaultPunch = true;
    $scope.processingPunch = false;
    $scope.allCheck = false;
    $scope.dupsPunchWarning = false;
    $scope.punchBtn = true;
    $scope.continueBtn = false;
    $scope.disableCancelBtn = false;
    $scope.disableRescheduleBtn = false;
    $scope.disablePunchBtn = false;
    $scope.cancelledBatch = false;
    $scope.rescheduledBatch = false;
    $scope.dupsMemPunchWarning = false;
    $scope.members = "";
    $scope.isSessionBased = false;
    $scope.attSettings = [];
    $scope.showDateError = false;
    $scope.dateError = "";
    $scope.timeIndex = '-1';
    $scope.punchmem="";
    $scope.testbtn =[];
    $scope.showAsstTrainer = false;
    $scope.offline = false;
    $scope.pending_att_err = false;
    $scope.action_disable = false;
    $scope.disableShowAttendees = false;
    $scope.skip_attendance = false;
    $scope.support_mode = false;
    $scope.attendanceStage = 'SELECTING';
    // The roster region is always in exactly one of these states:
    // INITIAL, LOADING, SLOW_LOADING, LOADED, EMPTY, ERROR. Saving attendance
    // has its own flag and never appears while the roster loads.
    $scope.rosterState = 'INITIAL';
    $scope.rosterErrorKind = '';
    $scope.savingAttendance = false;
    $scope.refreshFailed = false;
    function setRosterState(state){
        $scope.rosterState = state;
        // attendanceStage still drives the older review / scan flow.
        $scope.attendanceStage = {INITIAL:'SELECTING', LOADING:'LOADING', SLOW_LOADING:'LOADING', LOADED:'READY', EMPTY:'EMPTY', ERROR:'ERROR'}[state];
    }
    $scope.showRosterSkeleton = function(){
        return $scope.rosterState === 'LOADING' || $scope.rosterState === 'SLOW_LOADING'
            || ($scope.rosterState === 'INITIAL' && !!$scope.batchesLoading);
    };
    $scope.rosterLoadError = '';
    $scope.faceSetupMode = false;
    $scope.reviewWithoutScan = false;
    $scope.commitError = '';
    $scope.faceState = 'IDLE';
    $scope.faceMessage = '';
    $scope.faceMatched = [];
    $scope.faceLiveWriteMode = false;
    $scope.faceScanTimingSamples = [];
    $scope.faceScanStatusLabel = 'Ready to start';
    $scope.faceScanStatusClass = 'is-idle';
    var faceScannedMemberIds = {};
    var faceScanSelections = {};
    $scope.faceChallengeActive = false;
    $scope.faceChallengePrompt = '';
    $scope.faceAttendanceDetectionMessage = 'Position one child inside the face guide.';
    $scope.faceAttendanceDetectionStatusClass = 'is-waiting';
    $scope.faceAttendanceDetectionReady = false;
    $scope.faceAttendanceBoxStyle = {display: 'none'};
    $scope.browserFaceUiEnabled = window.browserFaceEnabled===true;
    $scope.faceUiEnabled = $scope.browserFacePilot && !!window.faceAttendanceFeatureEnabled && (typeof window.FaceAttendanceStateMachine === 'function');
    $scope.faceSelectionUiEnabled = $scope.faceUiEnabled && !!window.faceTeacherSelectionEnabled;
    $scope.facePunchOutUiEnabled = $scope.faceUiEnabled && !!window.facePunchOutFeatureEnabled;
    $scope.faceEnrollmentUiEnabled = !!window.faceEnrollmentFeatureEnabled;
    $scope.faceGuidedAdaptiveEnabled = !!window.faceGuidedAdaptiveV2Enabled;
    $scope.faceAdminReviewer = parseInt(session.position_id, 10) === 1;
	$scope.faceResetBusy = false;
    $scope.faceRecalibrationUiEnabled = !!window.faceRecalibrationFeatureEnabled;
    $scope.faceEnrollmentState = 'IDLE';
    $scope.faceEnrollmentMessage = '';
    $scope.faceEnrollmentMemberName = '';
    $scope.faceEnrollmentIssue = {visible: false, reason: ''};
    $scope.faceEnrollmentDetectionMessage = 'Place one face inside the guide.';
    $scope.faceEnrollmentDetectionStatusClass = 'is-waiting';
    $scope.faceEnrollmentDetectorEnforced = false;
    $scope.faceEnrollmentDetected = false;
    $scope.faceEnrollmentDetectionReady = false;
    $scope.faceEnrollmentCaptured = 0;
    $scope.faceEnrollmentFrameCount = 0;
    $scope.faceEnrollmentCalibrationPending = false;
    $scope.faceEnrollmentVerificationSummary = '';
    $scope.faceEnrollmentProgressStyle = {width: '0%'};
    $scope.faceEnrollmentRingSteps = [];
    $scope.faceEnrollmentBoxStyle = {display: 'none'};
    $scope.faceEnrollmentPosePrompt = '';
    $scope.faceEnrollmentVisualDirection = 'centre';
    $scope.faceEnrollmentPhotoMarkers = [false, false];
    $scope.faceEnrollmentPosePhotoCounts = [0, 0, 0, 0, 0];
    $scope.faceEnrollmentShowPoseTip = false;
    $scope.faceEnrollmentReferenceConfirmationPending = false;
    $scope.faceEnrollmentDiagnosticSamples = [];
    $scope.faceEnrollmentStepStartedAt = null;
    $scope.faceEnrollmentPaused = false;
    $scope.faceEnrollmentPauseReason = '';
    $scope.faceEnrollmentCaptureState = 'CLEAR';
    $scope.faceEnrollmentHasReference = false;
    $scope.faceEnrollmentReferencePortraitUrl = null;
    $scope.faceEnrollmentReferenceBusy = false;
    $scope.cameraFacingMode = (function(){
        try {
            return window.localStorage.getItem('face_camera_facing_mode') || 'user';
        } catch (e) {
            return 'user';
        }
    })();

    function getCameraConstraints(mode){
        var facing = mode || $scope.cameraFacingMode || 'user';
        return {
            video: {
                facingMode: { ideal: facing },
                width: { ideal: 1280 },
                height: { ideal: 960 },
                aspectRatio: { ideal: 4 / 3 }
            },
            audio: false
        };
    }
    $scope.faceEnrollmentStatusLabel = faceEnrollmentStatusLabel;
    $scope.faceEnrollmentStatusClass = faceEnrollmentStatusClass;
    $scope.dismissFaceEnrollmentIssue = dismissFaceEnrollmentIssue;
    $scope.startFaceEnrollment = startFaceEnrollment;
    var automaticFlow, automaticLoading;
    var automaticErrorMessage=function(){return 'Unable to open face capture. Please try again.';};
    $scope.automaticFace={open:false};
    function automaticContext(){var time=$scope.batch.times && $scope.batch.times[$scope.timeIndex];return {batch_id:Number($scope.batch.id),date:moment($scope.batch.date,'DD-MM-YYYY').format('YYYY-MM-DD'),session_id:Number($scope.batch.session_id || (time && time.session_id) || 0),time:time && time.start_time};}
    async function getAutomaticFlow(){
        if(automaticFlow)return automaticFlow;
        if(!automaticLoading)automaticLoading=import(new URL('js/browser-face/attendance.mjs?v=frictionless-20261016-1',document.baseURI).href).then(function(module){
            automaticErrorMessage=module.errorMessage;
            automaticFlow=module.installAttendanceFace($scope,{csrf:window.browserFaceCsrf,url:'../routes/browserFaceRoute.php'+($scope.browserFacePilot?'?pilot=1':''),getContext:automaticContext,restoreContext:function(c){$scope.batch.id=String(c.batch_id);$scope.batch.date=moment(c.date,'YYYY-MM-DD').format('DD-MM-YYYY');$scope.batch.session_id=c.session_id;$scope.timeIndex=String(($scope.batch.times || []).findIndex(function(t){return Number(t.session_id)===c.session_id;}));},notify:show_snack,video:document.getElementById('automaticFaceVideo'),canvas:document.getElementById('automaticFaceOverlay')});return automaticFlow;
        }).catch(function(error){automaticLoading=null;throw error;});return automaticLoading;
    }
    async function refreshAutomaticStatus(){
        var context=JSON.stringify(automaticContext()),testStatus=null;
        $scope.$evalAsync(function(){if(context===JSON.stringify(automaticContext())){$scope.faceStatusPending=$scope.browserFaceUiEnabled && !$scope.faceStatusLoaded;$scope.browserFaceStatusError='';}});
        try{var flow=await getAutomaticFlow();
            // Independent read-only requests: fetch together instead of three round trips.
            var batchId=Number($scope.batch.id),settled=await Promise.allSettled([flow.api.get('migration_status'),flow.api.get('options',{batch_id:batchId}),flow.api.get('config',{batch_id:batchId})]);
            // A class an administrator has turned off is a normal state, not a load failure: hide the face controls quietly.
            if(settled[2].status==='fulfilled' && settled[2].value.operating_mode==='DISABLED'){
                $scope.$evalAsync(function(){if(context!==JSON.stringify(automaticContext()))return;$scope.browserFaceOperatingMode='DISABLED';$scope.browserFaceStatusError='';$scope.faceStatusLoaded=true;$scope.faceStatusPending=false;});
                return;
            }
            settled.forEach(function(entry){if(entry.status==='rejected')throw entry.reason;});
            var data=settled[0].value,options=settled[1].value,config=settled[2].value;
            if(config.operating_mode==='TEST' && context===JSON.stringify(automaticContext()))testStatus=$scope.refreshFaceTestAttendance();
            $scope.$evalAsync(function(){if(context!==JSON.stringify(automaticContext()))return;$scope.browserFaceStatusError='';$scope.browserFaceOperatingMode=config.operating_mode;($scope.list || []).forEach(function(row){
                var legacy=data.legacy_templates.filter(function(t){return String(t.contact_id)===String(row.contact_id);});var person=options.persons.find(function(p){return String(p.contact_id)===String(row.contact_id);});
                row.browser_face_legacy=legacy.length>0;row.browser_face_enrolled=!!person && Number(person.enrolled)>0 && !row.browser_face_legacy;row.face_mapping_active=row.browser_face_enrolled;row.browser_face_pending=!!person && (person.enrollment_state ? person.enrollment_state==='PENDING' : Number(person.pending_samples)>0);row.browser_face_state=person && person.enrollment_state;row.browser_face_test_ready=!!person && person.test_ready===true;row.browser_face_conflict=!!person && person.test_gallery_conflict===true;row.browser_face_excluded=!!person && person.test_excluded===true;row.browser_face_latest_attempt=person && person.latest_attempt_state;
                row.browser_face_migration_authorized=legacy.length>0 && legacy.every(function(t){return t.migration_authorized;});row.browser_face_legacy_ids=legacy.map(function(t){return Number(t.template_id);});
            });
                if(!legacyFaceOpened && legacyFaceQuery.has('mode')){legacyFaceOpened=true;
                    if(legacyFaceQuery.get('mode')==='attendance')$scope.openAutomaticFace('attendance');
                    else{var selected=($scope.list || []).find(function(row){return String(row.contact_id)===legacyFaceQuery.get('contact_id');});if(selected)$scope.openAutomaticFace('enroll',selected);}
                }
            });
            if(testStatus)await testStatus;
            $scope.$evalAsync(function(){if(context!==JSON.stringify(automaticContext()))return;$scope.faceStatusLoaded=true;$scope.faceStatusPending=false;});
        }catch(error){$scope.$evalAsync(function(){if(context!==JSON.stringify(automaticContext()))return;$scope.faceStatusPending=false;var detail=automaticErrorMessage(error);$scope.browserFaceStatusError="Couldn't load face status."+(detail==='Please try again'?'':' '+detail);});}
    }
    $scope.retryFaceStatus=function(){refreshAutomaticStatus();};
    // One actionable notice at a time, in priority order. Text comes only from vetted messages.
    function activeNotice(){
        var face=$scope.automaticFace || {};
        if($scope.refreshFailed)return {text:"Couldn't refresh attendance.",action:'refresh'};
        if($scope.browserFaceStatusError && $scope.browserFaceUiEnabled && $scope.rosterState==='LOADED' && ($scope.list || []).length)return {text:$scope.browserFaceStatusError,action:'status'};
        if(!face.open && face.retry)return {text:'A face request was interrupted.',action:'face'};
        return null;
    }
    $scope.noticeText=function(){var notice=activeNotice();return notice?notice.text:'';};
    $scope.runNotice=function(){
        var notice=activeNotice();if(!notice)return;
        if(notice.action==='refresh')$scope.retryRosterLoad();
        else if(notice.action==='status')$scope.retryFaceStatus();
        else $scope.retryAutomaticFace();
    };
    $scope.toggleFacePracticeControls=function(){$scope.facePracticeControls=!$scope.facePracticeControls;};
    // Row labels come only from loaded backend state; until it arrives the row says so.
    $scope.faceStatusLabel=function(row){
        if($scope.faceStatusPending)return 'Checking…';
        if($scope.browserFaceUiEnabled && !$scope.faceStatusLoaded)return '—';
        if(row.browser_face_excluded)return 'Not Captured';
        if(row.browser_face_conflict)return 'Needs Attention';
        if(row.browser_face_state==='ACTIVE' && row.browser_face_enrolled)return 'Enrolled';
        if(row.browser_face_state==='PENDING')return row.browser_face_test_ready?'Saved for Testing':'Pending Setup';
        if(row.browser_face_legacy || row.browser_face_state==='REJECTED_DUPLICATE' || row.browser_face_state==='RECAPTURE_REQUIRED')return 'Needs Attention';
        return 'Not Captured';
    };
    $scope.faceStatusClass=function(row){
        var label=$scope.faceStatusLabel(row);
        return label==='Enrolled'?'is-enrolled':label==='Saved for Testing'?'is-saved':label==='Needs Attention' || label==='Pending Setup'?'is-attention':label==='Not Captured'?'is-none':'is-checking';
    };
    $scope.testAttendanceLabel=function(row){
        var state=row.browser_face_test_attendance_state;
        return state==='PRESENT_PUNCHED_TEST'?'Present':state==='PRESENT_PENDING_PUNCH'?'Present — Pending Punch':'Not Detected';
    };
    $scope.rosterRowClass=function(row){
        if($scope.faceStatusPending)return '';
        if($scope.browserFaceOperatingMode==='TEST')return row.browser_face_test_attendance_state==='PRESENT_PUNCHED_TEST'?'is-row-present':row.browser_face_test_attendance_state==='PRESENT_PENDING_PUNCH'?'is-row-pending':'';
        return row.selected===true || (row.attendance_id>0 && row.negative==0)?'is-row-present':'';
    };
    $scope.automaticFaceStatusClass=function(){
        var face=$scope.automaticFace || {},status=face.status || '';
        if(face.error)return 'is-error';
        return /^(?!Face not ).+ recognized$|^Face saved$|^Face enrolled$|^Attendance (marked|confirmed)/.test(status)?'is-success':'';
    };
    // Camera dialog: focus moves in on open, stays inside while open and returns on close.
    var automaticFaceOpener=null;
    $scope.$watch('automaticFace.open',function(open,was){
        if(!!open===!!was)return;
        document.body.classList.toggle('browser-face-modal-open',!!open);
        if(open){
            automaticFaceOpener=document.activeElement;
            setTimeout(function(){var dialog=document.querySelector('.browser-face-dialog');if(dialog && !dialog.contains(document.activeElement))dialog.focus({preventScroll:true});},0);
        }else{
            var opener=automaticFaceOpener;automaticFaceOpener=null;
            if(opener && document.body.contains(opener) && typeof opener.focus==='function')setTimeout(function(){opener.focus({preventScroll:true});},0);
        }
    });
    $scope.automaticFaceKeydown=function(event){
        if(event.key==='Escape'){event.preventDefault();$scope.closeAutomaticFace();return;}
        if(event.key!=='Tab')return;
        var dialog=event.currentTarget.querySelector('.browser-face-dialog');
        var items=Array.prototype.filter.call(dialog.querySelectorAll('button,[href],input,select,textarea,summary,[tabindex]:not([tabindex="-1"])'),function(el){return !el.disabled && el.offsetParent!==null;});
        if(!items.length){event.preventDefault();dialog.focus();return;}
        var first=items[0],last=items[items.length-1];
        if(event.shiftKey && (document.activeElement===first || document.activeElement===dialog)){event.preventDefault();last.focus();}
        else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first.focus();}
    };
    $scope.openAutomaticFace=async function(mode,row){
        if(mode==='attendance' && !memberRows().length){
            show_snack('No members found for this class.');
            return;
        }
        var context=JSON.stringify(automaticContext());
        try{
            await getAutomaticFlow();
            if($scope.$$destroyed || context!==JSON.stringify(automaticContext()))return;
            return $scope.openAutomaticFace(mode,row);
        }catch(error){
            show_snack(automaticErrorMessage(error));
        }
    };
    $scope.closeAutomaticFace=function(){if(automaticFlow)return automaticFlow.close();};
    $scope.retryAutomaticFace=function(){if(automaticFlow)return automaticFlow.retry();};
    $scope.onboardReferencePortrait = onboardReferencePortrait;
    $scope.recalibrateFace = recalibrateFace;
    $scope.clearFaceReference = clearFaceReference;
    $scope.loadFaceAdminReviews = loadFaceAdminReviews;
    $scope.submitFaceAdminReview = submitFaceAdminReview;
    $scope.faceAdminReviews = [];
    $scope.faceAdminReviewMessage = '';
    $scope.captureFaceEnrollment = captureFaceEnrollment;
    $scope.submitFaceEnrollment = submitFaceEnrollment;
    $scope.cancelFaceEnrollment = cancelFaceEnrollment;
    $scope.retryFaceEnrollment = retryFaceEnrollment;
    $scope.continueFaceSetup = continueFaceSetup;
    $scope.startAttendanceAfterEnrollment = startAttendanceAfterEnrollment;
    $scope.startFacePunchIn = startFacePunchIn;
    $scope.startFacePunchOut = startFacePunchOut;
    $scope.captureFaceAttempt = captureFaceAttempt;
    $scope.useManualFaceFallback = useManualFaceFallback;
    $scope.useManualFaceAttendance = useManualFaceAttendance;
    $scope.retryFaceAttempt = retryFaceAttempt;
    $scope.completeFaceScan = completeFaceScan;
    $scope.cancelFaceScan = cancelFaceScan;
    $scope.finishFaceScan = completeFaceScan;
    $scope.finishWithoutScanning = finishWithoutScanning;
    $scope.continueFaceScanning = continueFaceScanning;
    $scope.toggleFaceSetupMode = toggleFaceSetupMode;
    $scope.finishAttendanceFlow = finishAttendanceFlow;
	$scope.resetFaceAttendanceTestData = resetFaceAttendanceTestData;
    $scope.rosterPresentCount = rosterPresentCount;
    $scope.rosterAbsentCount = rosterAbsentCount;
    $scope.rosterNotReadyCount = rosterNotReadyCount;
    $scope.rosterStatusLabel = rosterStatusLabel;
    $scope.rosterStatusClass = rosterStatusClass;
    $scope.rosterSessionsLabel = rosterSessionsLabel;
    $scope.memberRows = memberRows;

    var rosterLoadGeneration = 0;
    var batchLoadGeneration = 0;
    var scanRosterSnapshot = null;
    var activeFaceEventType = 'PUNCH_IN';
    var activeFaceEventLabel = 'arrivals';

    var faceStream = null;
    var faceCameraGeneration = 0;
    var faceScan = null;
    var facePlanarWorker = null;
    var facePlanarWorkerReady = false;
    var facePlanarRequestId = 0;
    var facePlanarCallbacks = {};
    var faceWalkUpTimer = null;
    var faceWalkUpBusy = false;
    var candidateEpoch = 0;
    var faceLeaseHeartbeatTimer = null;
    var faceLeaseRenewAt = 0;
    var faceLeaseRenewing = false;
    $scope.faceLeaseExpired = false;
    $scope.faceManualFallbackAvailable = false;
    var faceNonceBusy = false;
    var faceFinishRequested = false;
    var faceAttemptTimer = null;
    var faceStartRetryTimer = null;
    var faceRecoveryTimer = null;
    var faceRecoveryCount = 0;
    var faceRecovering = false;
    var faceStartRetryCount = 0;
    var faceAttemptGeneration = 0;
    var faceStartRetryGeneration = 0;
    var facePreviewTimer = null;
    var facePreviewBusy = false;
    var facePreviewGeneration = 0;
    var facePreviewObservedAt = 0;
    var facePreviewAvailable = false;
    var faceReadyAt = 0;
    var facePreviewLatencyMs = 0;
    var faceNextChildTimer = null;
    var faceAwaitingNextChild = false;
    var faceAttendanceSingleFacePresent = false;
    var faceAttendanceFaceCount = 0;
    var faceWaitSawNoFace = false;
    var faceChallenge = null;
    $scope.faceRetryAvailable = false;
    var faceRetryUsed = false;
    var enrollmentStream = null;
    var enrollmentCameraGeneration = 0;
    var enrollmentPreviewGeneration = 0;
    var enrollmentMember = null;
    var captureSessionToken = null;
    var captureRequestSequence = 0;
    var referenceCaptureTimer = null;
    var referenceMismatchCount = 0;
    var referenceMismatchStartedAt = null;
    var referenceMismatchObservedAt = null;
    $scope.faceEnrollmentReferenceMismatch = false;
    $scope.faceEnrollmentCanRetakeReference = false;
    $scope.faceEnrollmentInstructionMode = function(){
        if ($scope.faceEnrollmentIssue && $scope.faceEnrollmentIssue.visible) return 'CONFLICT';
        if ($scope.faceEnrollmentState === 'READY' && !$scope.faceEnrollmentDetectionReady && !$scope.faceEnrollmentReferenceBusy) return 'PREPARING';
        return 'GUIDANCE';
    };
    $scope.faceEnrollmentPrimaryInstruction = function(){
        if ($scope.faceEnrollmentReferenceConfirmationPending) return 'Return straight-on to confirm the reference.';
        if ($scope.faceEnrollmentReferenceMismatch) return 'Look straight ahead like the reference photo.';
        if ($scope.faceEnrollmentReferenceBusy || ['STARTING_CAMERA', 'PROCESSING', 'COMPLETE', 'ERROR', 'REFERENCE_REQUIRED'].indexOf($scope.faceEnrollmentState) !== -1) return $scope.faceEnrollmentMessage;
        if ($scope.faceEnrollmentState === 'CAPTURING' && $scope.faceEnrollmentPaused && $scope.faceEnrollmentPauseReason) {
            return $scope.faceEnrollmentPauseReason;
        }
        return $scope.faceEnrollmentDetectionMessage || $scope.faceEnrollmentMessage;
    };
    function resetReferenceMismatch(){
        referenceMismatchCount = 0;
        referenceMismatchStartedAt = null;
        referenceMismatchObservedAt = null;
        $scope.faceEnrollmentReferenceMismatch = false;
        $scope.faceEnrollmentCanRetakeReference = false;
    }
    function isMeasuredCentre(body){
        var selected = body && Number.isInteger(body.selected_face_index) && body.faces
            ? body.faces[body.selected_face_index] : null;
        var quality = selected && selected.quality;
        var windows = enrollmentGuidedCapability && enrollmentGuidedCapability.windows;
        var yaw = quality && quality.pose_yaw;
        var pitch = quality && quality.pose_pitch;
        return !!windows && typeof yaw === 'number' && Number.isFinite(yaw)
            && typeof pitch === 'number' && Number.isFinite(pitch)
            && Math.abs(yaw) <= windows.centre_yaw_max
            && pitch >= windows.centre_pitch_min && pitch <= windows.centre_pitch_max
            && ($scope.faceEnrollmentCentrePitch === null
                || Math.abs(pitch - $scope.faceEnrollmentCentrePitch) <= windows.pitch_tolerance);
    }
    function observeReferenceMismatch(body, capturedAt){
        if (referenceMismatchObservedAt !== null && capturedAt <= referenceMismatchObservedAt) return;
        var mismatch = body && body.reference_match_state === 'MISMATCH' && !body.collision
            && $scope.faceEnrollmentHasReference && ['READY', 'CAPTURING'].indexOf($scope.faceEnrollmentState) !== -1;
        if (!mismatch) {
            resetReferenceMismatch();
        } else {
            // A deliberate turn or chin tip lowers frontal similarity. Only a
            // measured frontal mismatch can suggest replacing the reference.
            if (enrollmentGuidedCapability && $scope.faceEnrollmentState === 'CAPTURING'
                && !isMeasuredCentre(body)) {
                referenceMismatchCount = 0;
                referenceMismatchStartedAt = null;
                $scope.faceEnrollmentReferenceMismatch = true;
                $scope.faceEnrollmentCanRetakeReference = false;
                referenceMismatchObservedAt = capturedAt;
                return;
            }
            if (referenceMismatchStartedAt === null) referenceMismatchStartedAt = capturedAt;
            referenceMismatchCount++;
            $scope.faceEnrollmentReferenceMismatch = true;
            $scope.faceEnrollmentCanRetakeReference = referenceMismatchCount >= 3 && capturedAt - referenceMismatchStartedAt >= 1000;
        }
        referenceMismatchObservedAt = capturedAt;
    }
    var enrollmentGuidedCapability = null;
    var enrollmentLocalTracker = null;
    var adaptiveCandidateTokens = [];
    var adaptiveServerCorrection = null;
    var enrollmentSetupStartedAt = null;
    var enrollmentReferencePresentAtStart = false;
    var enrollmentRetrying = false;
    var enrollmentRetryCount = 0;
    var enrollmentRunId = null;
    var enrollmentRunLogged = false;
    var adaptiveTrackerFailed = false;
    function enrollmentDeviceMetadata(){
        var track = enrollmentStream && enrollmentStream.getVideoTracks()[0];
        var ua = (navigator.userAgent || '').slice(0, 300);
        var browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox'
            : /CriOS\//.test(ua) ? 'Chrome iOS' : /Chrome\//.test(ua) ? 'Chrome'
            : /Safari\//.test(ua) ? 'Safari' : 'Other';
        return {camera_label:track ? (track.label || 'unavailable').slice(0, 160) : 'unavailable',
            camera_facing_mode:$scope.cameraFacingMode, browser:browser, userAgent:ua,
            run_id:enrollmentRunId,
            platform:navigator.platform, device:typeof get_device === 'function' ? get_device() : ua,
            setup_elapsed_ms:enrollmentSetupStartedAt === null ? null : Date.now() - enrollmentSetupStartedAt,
            reference_was_present:enrollmentReferencePresentAtStart,
            first_pass:enrollmentRetryCount === 0};
    }
    $scope.faceEnrollmentPoseProtocol = null;
    $scope.faceEnrollmentPoseProgress = '';
    $scope.faceEnrollmentCentrePitch = null;

    function resetGuidedEnrollment(){
        enrollmentGuidedCapability = null;
        $scope.faceEnrollmentPoseProtocol = null;
        $scope.faceEnrollmentPoseProgress = '';
        $scope.faceEnrollmentVisualDirection = 'centre';
        $scope.faceEnrollmentPhotoMarkers = [false, false];
        $scope.faceEnrollmentPosePhotoCounts = [0, 0, 0, 0, 0];
        $scope.faceEnrollmentShowPoseTip = false;
        $scope.faceEnrollmentReferenceConfirmationPending = false;
        $scope.faceEnrollmentCentrePitch = null;
        $scope.faceEnrollmentStepStartedAt = null;
        $scope.faceEnrollmentRequestedFrameIndex = null;
        resetEnrollmentRing();
    }

    // These are presentation milestones, not client-side evidence labels. The
    // server remains the authority for every accepted candidate and template.
    function resetEnrollmentRing(){
        $scope.faceEnrollmentRingSteps = [
            {label: 'Front', state: 'is-active'},
            {label: 'Front check', state: 'is-pending'},
            {label: 'Left turn', state: 'is-pending'},
            {label: 'Right turn', state: 'is-pending'},
            {label: 'Profile check', state: 'is-pending'},
            {label: 'Final hold', state: 'is-pending'}
        ];
    }

    function setEnrollmentRingActive(index){
        var steps = $scope.faceEnrollmentRingSteps;
        if (!Array.isArray(steps) || steps.length !== 6) { resetEnrollmentRing(); steps = $scope.faceEnrollmentRingSteps; }
        index = Math.max(0, Math.min(steps.length - 1, index));
        steps.forEach(function(step, stepIndex){
            step.state = stepIndex < index ? 'is-complete' : (stepIndex === index ? 'is-active' : 'is-pending');
        });
    }

    function completeEnrollmentRingStep(index){
        var steps = $scope.faceEnrollmentRingSteps;
        if (!Array.isArray(steps) || steps.length !== 6) { resetEnrollmentRing(); steps = $scope.faceEnrollmentRingSteps; }
        index = Math.max(0, Math.min(steps.length - 1, index));
        steps[index].state = 'is-complete';
        if (index + 1 < steps.length) setEnrollmentRingActive(index + 1);
    }

    function readGuidedEnrollmentCapability(body){
        var capability = body && body.guided_enrollment;
        var windows = capability && capability.windows;
        var keys = ['centre_yaw_max', 'centre_pitch_min', 'centre_pitch_max', 'side_yaw_min',
            'side_yaw_max', 'pitch_tolerance', 'pitch_delta_min', 'pitch_delta_max', 'stable_ms'];
        var selection = capability && capability.pose_protocol === 'guided-five-select-v1';
        var adaptive = capability && capability.pose_protocol === 'guided-adaptive-v2';
        if (adaptive && adaptiveTrackerFailed) return null;
        if (!capability || (!selection && !adaptive && capability.pose_protocol !== 'guided-five-v1') || capability.template_count !== 6 || !windows
            || (selection && (capability.burst_a_count !== 10 || capability.burst_b_count !== 3 || capability.samples_per_pose !== 2))
            || (adaptive && (capability.burst_a_count !== 5 || capability.burst_b_count !== 3 || capability.departure_ms !== 750))
            || !keys.every(function(key){ return typeof windows[key] === 'number' && Number.isFinite(windows[key]) && windows[key] > 0; })
            || windows.centre_pitch_min >= windows.centre_pitch_max || windows.centre_yaw_max >= windows.side_yaw_min
            || windows.side_yaw_min >= windows.side_yaw_max || windows.pitch_delta_min >= windows.pitch_delta_max) return null;
        // Freeze the negotiated targets for the attempt. No client-supplied pose
        // labels or measurements are sent as authority to the enrollment server.
        return JSON.parse(JSON.stringify(capability));
    }

    function guidedPoseMatches(index, quality, centrePitch, windows){
        var yaw = quality && quality.pose_yaw;
        var pitch = quality && quality.pose_pitch;
        if (!quality || quality.accepted !== true || typeof yaw !== 'number' || typeof pitch !== 'number'
            || !Number.isFinite(yaw) || !Number.isFinite(pitch)) return false;
        var selection = enrollmentGuidedCapability && enrollmentGuidedCapability.pose_protocol === 'guided-five-select-v1';
        var burstACount = selection ? 10 : 5;
        var poseIndex = index < burstACount ? (selection ? Math.floor(index / 2) : index) : 5;
        var delta = poseIndex === 0 ? 0 : Math.round((pitch - centrePitch) * 1000) / 1000;
        if (poseIndex === 0 || poseIndex >= 5) return Math.abs(yaw) <= windows.centre_yaw_max
            && pitch >= windows.centre_pitch_min && pitch <= windows.centre_pitch_max
            && Math.abs(delta) <= windows.pitch_tolerance;
        if (poseIndex === 1 || poseIndex === 2) {
            var signedYaw = poseIndex === 1 ? -yaw : yaw;
            return signedYaw >= windows.side_yaw_min && signedYaw <= windows.side_yaw_max
                && Math.abs(delta) <= windows.pitch_tolerance;
        }
        var displacement = poseIndex === 3 ? -delta : delta;
        return Math.abs(yaw) <= windows.centre_yaw_max
            && displacement >= windows.pitch_delta_min && displacement <= windows.pitch_delta_max;
    }

    function guidedPoseFeedback(index, quality, centrePitch, windows){
        var selection = enrollmentGuidedCapability && enrollmentGuidedCapability.pose_protocol === 'guided-five-select-v1';
        var poseIndex = index < (selection ? 10 : 5) ? (selection ? Math.floor(index / 2) : index) : 5;
        var yaw = quality && quality.pose_yaw, pitch = quality && quality.pose_pitch;
        if (typeof yaw !== 'number' || !Number.isFinite(yaw) || typeof pitch !== 'number' || !Number.isFinite(pitch))
            return 'Hold your face in the oval while we measure this pose.';
        var delta = centrePitch === null ? 0 : pitch - centrePitch;
        if (poseIndex === 1 || poseIndex === 2) {
            if (Math.abs(delta) > windows.pitch_tolerance) return 'Keep your chin level, then turn your face slightly.';
            var turn = poseIndex === 1 ? -yaw : yaw;
            if (turn < -windows.centre_yaw_max) return 'Turn the other way, toward the arrow.';
            if (turn < windows.side_yaw_min) return 'Turn a little more toward the arrow.';
            if (turn > windows.side_yaw_max) return 'Too far—turn back slightly.';
        } else if (poseIndex === 3 || poseIndex === 4) {
            if (Math.abs(yaw) > windows.centre_yaw_max) return 'Face the camera, then tip your chin slightly.';
            var tip = poseIndex === 3 ? -delta : delta;
            if (tip > windows.pitch_delta_max) return poseIndex === 3
                ? 'Too far—lower your chin slightly.' : 'Too far—lift your chin slightly.';
            if (tip < windows.pitch_delta_min) return poseIndex === 3
                ? 'Lift a little more by tipping your head back.' : 'Lower a little more toward your chest.';
        } else if (Math.abs(yaw) > windows.centre_yaw_max || pitch < windows.centre_pitch_min
            || pitch > windows.centre_pitch_max || Math.abs(delta) > windows.pitch_tolerance) {
            return 'Look straight at the camera with your head level.';
        }
        if (!quality || quality.accepted !== true) return 'Hold still while we check face quality.';
        return 'Good—hold still for the photo.';
    }

    function showGuidedPose(index, steady, feedback){
        if ($scope.faceEnrollmentRequestedFrameIndex !== index) {
            $scope.faceEnrollmentRequestedFrameIndex = index;
            $scope.faceEnrollmentStepStartedAt = Date.now();
        }
        var selection = enrollmentGuidedCapability && enrollmentGuidedCapability.pose_protocol === 'guided-five-select-v1';
        var burstACount = selection ? 10 : 5;
        var poseIndex = index < burstACount ? (selection ? Math.floor(index / 2) : index) : 5;
        var mirrored = $scope.cameraFacingMode === 'user';
        var screenLeft = poseIndex === 1 ? !mirrored : mirrored;
        var message;
        if (steady) message = 'Good—hold still for the photo.';
        else if (poseIndex === 1 || poseIndex === 2) {
            message = 'Keep your shoulders still. Turn your face slightly toward the ' + (screenLeft ? 'left' : 'right') + ' side of the preview. Keep both eyes visible.';
        } else if (poseIndex === 3) message = 'Tip your head back a little. Keep your face in the oval and eyes toward the camera.';
        else if (poseIndex === 4) message = 'Tip your chin slightly toward your chest. Keep your face in the oval and eyes toward the camera.';
        else message = 'Look straight ahead at the camera.';
        if (!steady && feedback) message += ' ' + feedback;
        $scope.faceEnrollmentPoseProgress = index < burstACount
            ? 'Pose ' + (poseIndex + 1) + ' of 5' + (selection ? ', photo ' + (index % 2 + 1) + ' of 2' : '')
            : 'Final check ' + (index - burstACount + 1) + ' of 3';
        $scope.faceEnrollmentVisualDirection = (poseIndex === 1 || poseIndex === 2)
            ? (screenLeft ? 'left' : 'right') : (poseIndex === 3 ? 'up' : (poseIndex === 4 ? 'down' : 'centre'));
        $scope.faceEnrollmentRequestedPoseIndex = poseIndex;
        $scope.faceEnrollmentPhotoMarkers = selection && index < burstACount
            ? [index % 2 > 0, false] : [false, false];
        if ($scope.faceEnrollmentPaused) return;
        $scope.faceEnrollmentDetectionStatusClass = steady ? 'is-ready' : 'is-warning';
        $scope.faceEnrollmentDetectionMessage = message;
        $scope.faceEnrollmentMessage = message;
    }

    var enrollmentFrames = [];
    var enrollmentFaceDetector = null;
    var enrollmentDetectionTimer = null;
    var enrollmentDetectionBusy = false;
    var enrollmentModelPreviewTimer = null;
    var enrollmentModelPreviewBusy = false;
    var enrollmentModelPreviewAvailable = false;
    function stopAdaptiveTracker(){
        if (enrollmentLocalTracker) enrollmentLocalTracker.stop();
        enrollmentLocalTracker = null;
        adaptiveServerCorrection = null;
    }
    function startAdaptiveTracker(){
        // Start the dynamic local outline as soon as the camera is ready. The
        // server still negotiates which evidence protocol is allowed later.
        if (enrollmentLocalTracker) return;
        var video = document.getElementById('faceEnrollmentVideo');
        var canvas = document.getElementById('faceEnrollmentTrackerOverlay');
        if (!video || !canvas || typeof window.FaceEnrollmentLocalTracker !== 'function') return;
        enrollmentLocalTracker = new window.FaceEnrollmentLocalTracker(video, canvas, $scope.cameraFacingMode,
            function(observation){
                $scope.$evalAsync(function(){
                    if (!enrollmentLocalTracker) return;
                    var edgeReady = observation.faceCount === 1 && observation.edgeValid === true;
                    $scope.faceEnrollmentDetected = edgeReady;
                    $scope.faceEnrollmentDetectionReady = edgeReady;
                    $scope.faceEnrollmentDetectorEnforced = true;
                    if ($scope.faceEnrollmentState === 'READY' || $scope.faceEnrollmentState === 'CAPTURING') {
                        $scope.faceEnrollmentDetectionMessage = adaptiveServerCorrection && Date.now() < adaptiveServerCorrection.until
                            ? adaptiveServerCorrection.message : observation.correction;
                        $scope.faceEnrollmentDetectionStatusClass = observation.stable ? 'is-ready' : 'is-warning';
                    }
                });
            }, function(reason){
                $scope.$evalAsync(function(){
                    adaptiveTrackerFailed = true;
                    stopAdaptiveTracker();
                    enrollmentGuidedCapability = null;
                    $scope.faceEnrollmentPoseProtocol = null;
                    $scope.faceEnrollmentMessage = 'Local face tracking stopped (' + reason + '). Secure enrollment is still available.';
                    if ($scope.faceEnrollmentState === 'CAPTURING' || $scope.faceEnrollmentState === 'LEAVE_GUIDE')
                        $scope.faceEnrollmentState = 'READY';
                    enrollmentModelPreviewAvailable = false;
                    pollEnrollmentModelPreview();
                });
            });
        enrollmentLocalTracker.start();
    }
    var faceMachine = $scope.faceUiEnabled ? new window.FaceAttendanceStateMachine({
        onStateChange: function (state, payload) {
            $scope.$evalAsync(function () {
                $scope.faceState = state;
                updateFaceScanStatus(state);
                if (state === 'SCANNING' && faceFinishRequested) {
                    faceFinishRequested = false;
                    window.setTimeout(completeFaceScan, 0);
                }
                if (payload && payload.decision) {
                    $scope.faceMessage = payload.decision === 'ACCEPT'
                        ? 'Matched child'
                        : formatFaceAttendanceGuidance(payload);
                }
            });
        },
        onStopCamera: stopFaceCamera,
        onError: function (reason, message) {
            console.error('face_attendance_error', { reason: reason, message: message || '' });
            $scope.$evalAsync(function () {
                var detail = message ? ' ' + message : '';
                $scope.faceMessage = reason === 'SESSION_REQUIRED'
                    ? 'Your attendance session has expired. Refresh the page and sign in again before starting face attendance.'
                    : 'Face scanning needs attention.' + detail + ' Try again or leave unscanned students Absent.';
            });
        }
    }) : null;

    function updateFaceScanStatus(state){
        var statuses = {
            IDLE: ['Ready to start', 'is-idle'],
            STARTING_CAMERA: ['Starting camera', 'is-working'],
            SCANNING: ['Ready for one child', 'is-ready'],
            CAPTURING: ['Capturing face', 'is-working'],
            PROCESSING: ['Checking face', 'is-working'],
            MATCHED: ['Match found', 'is-success'],
            CHALLENGE: ['Liveness check', 'is-warning'],
            REVIEW: ['Try once more', 'is-warning'],
            REJECTED: ['Try once more', 'is-warning'],
            ERROR: ['Needs attention', 'is-danger'],
            RECONCILING: ['Preparing attendance summary', 'is-working'],
            COMPLETED: ['Scan complete', 'is-success'],
            CANCELLED: ['Scan stopped', 'is-stopped']
        };
        var status = statuses[state] || [String(state || 'Ready').replace(/_/g, ' '), 'is-idle'];
        $scope.faceScanStatusLabel = status[0];
        $scope.faceScanStatusClass = status[1];
    }

    function faceEnrollmentStatusLabel(){
        if ($scope.faceEnrollmentReferenceMismatch) return 'Reference mismatch';
        if ($scope.faceEnrollmentIssue && $scope.faceEnrollmentIssue.visible) {
            return $scope.faceEnrollmentIssue.conflictingMemberName
                ? 'Matches ' + $scope.faceEnrollmentIssue.conflictingMemberName
                : 'Face already registered';
        }
        if ($scope.faceEnrollmentIdentityWarning && $scope.faceEnrollmentState !== 'COMPLETE')
            return $scope.faceEnrollmentIdentityWarning.indexOf('unavailable') !== -1
                ? 'Identity check unavailable' : 'Possible face similarity';
        if ($scope.faceEnrollmentReferenceBusy) {
            return 'Checking reference…';
        }
        if ($scope.faceEnrollmentState === 'CAPTURING' && $scope.faceEnrollmentPaused) {
            return 'Face setup paused';
        }
        var labels = {
            STARTING_CAMERA: 'Preparing camera',
            READY: 'Ready to capture',
            CAPTURING: 'Capturing face…',
            PROCESSING: 'Saving face…',
            COMPLETE: 'Face ready',
            ERROR: 'Needs attention'
        };
        return labels[$scope.faceEnrollmentState] || 'Setup closed';
    }

    function faceEnrollmentStatusClass(){
        if ($scope.faceEnrollmentReferenceMismatch) return 'is-warning';
        if ($scope.faceEnrollmentIssue && $scope.faceEnrollmentIssue.visible) {
            return 'is-danger';
        }
        if ($scope.faceEnrollmentIdentityWarning && $scope.faceEnrollmentState !== 'COMPLETE') return 'is-warning';
        if ($scope.faceEnrollmentState === 'CAPTURING' && $scope.faceEnrollmentPaused) {
            return 'is-warning';
        }
        if ($scope.faceEnrollmentReferenceBusy) return 'is-working';
        if ($scope.faceEnrollmentState === 'READY' || $scope.faceEnrollmentState === 'COMPLETE') return 'is-success';
        if ($scope.faceEnrollmentState === 'ERROR') return 'is-danger';
        return 'is-working';
    }

    if($_GET['support'] != 0 && $_GET['support'] != null){
        $scope.support_mode = true;
        var support_fetch={"session_id":session_id,"col":["id", "support_id", "batch_id" ,"batch_date", "list_of_data", "bid", "uid"],"filter":[["park","=",0],["id","=",$_GET['support']]], table : 'support_request_attendance'};
		apiCall.callEndpoint(support_fetch,"supportRoute.php","select_request",json_header).then(function(response){
			// return false;
			if(response.data.error==0){
				$scope.support_data=response.data.data[0];
                $scope.batch.date = moment($scope.support_data.batch_date, 'YYYY-MM-DD').format("DD-MM-YYYY");
                initController($scope.batch.date, $scope.support_data.bid);
                $scope.batch.id = $scope.support_data.batch_id;
			}else{
				// Record not found
				history.go(-1);
			}
			
		});
    }else{
        initController($scope.batch.date, session.bid); // start date as a default.
    }

   
    function memberRows(){
        return ($scope.list || []).filter(function(row){ return row.type === 'M'; });
    }
    function rosterPresentCount(){
        return memberRows().filter(function(row){ return row.selected === true || String(row.negative) === '0'; }).length;
    }
    function rosterAbsentCount(){ return memberRows().length - rosterPresentCount(); }
    function rosterNotReadyCount(){
        return memberRows().filter(function(row){ return !row.face_mapping_active; }).length;
    }
    function rosterStatusLabel(row){
        if (!row) return '';
        if (String(row.attendance_id || '0') !== '0') return 'Already recorded';
        if (row.selected === true || String(row.negative) === '0') return 'Present';
        if (!row.face_mapping_active) return 'Face not set up';
        return 'Not scanned — Absent';
    }
    function rosterStatusClass(row){
        if (!row) return 'is-absent';
        if (String(row.attendance_id || '0') !== '0') return 'is-recorded';
        if (row.selected === true || String(row.negative) === '0') return 'is-present';
        if (!row.face_mapping_active) return 'is-not-ready';
        return 'is-absent';
    }
    function rosterSessionsLabel(row){
        var sessions = parseInt(row && row.sessions, 10);
        var used = parseInt(row && row.sessions_used, 10);
        if (sessions === 0) return 'Unlimited';
        if (isFinite(sessions) && isFinite(used)) return Math.max(0, sessions - used) + ' left';
        return row && row.session_consumed ? row.session_consumed : '—';
    }
    function toggleFaceSetupMode(){
        if ($scope.attendanceStage !== 'READY') return;
        if ($scope.faceSetupMode && $scope.faceEnrollmentState !== 'IDLE') {
            cancelFaceEnrollment();
        }
        $scope.faceSetupMode = !$scope.faceSetupMode;
    }
    function snapshotRoster(){
        scanRosterSnapshot = memberRows().map(function(row){
            return {contact_id: String(row.contact_id), selected: row.selected, negative: row.negative, skip: row.skip};
        });
    }
    function restoreRosterSnapshot(){
        if (!scanRosterSnapshot) return;
        var byContact = {};
        scanRosterSnapshot.forEach(function(item){ byContact[item.contact_id] = item; });
        memberRows().forEach(function(row){
            var original = byContact[String(row.contact_id)];
            if (!original) return;
            row.selected = original.selected;
            row.negative = original.negative;
            row.skip = original.skip;
        });
        scanRosterSnapshot = null;
    }
    function finishWithoutScanning(){
        if ($scope.attendanceStage !== 'READY') return;
        $scope.reviewWithoutScan = true;
        $scope.commitError = '';
        $scope.faceSetupMode = false;
        $scope.attendanceStage = 'REVIEW';
    }
    function continueFaceScanning(){
        if ($scope.attendanceStage !== 'REVIEW') return;
        $scope.reviewWithoutScan = false;
        startFaceScan(activeFaceEventType, activeFaceEventLabel);
    }
    function resetForSelectionChange(){
        rosterLoadGeneration++;
        if ($scope.faceEnrollmentState !== 'IDLE') cancelFaceEnrollment();
        stopFaceAttemptLoop();
        clearFaceStartRetry();
        stopFaceLeaseHeartbeat();
        stopFaceCamera();
        if (faceScan) apiCall.callEndpoint({session_id: session_id, scan_id: faceScan.scan_id, scan_token: faceScan.scan_token}, 'v1/face-attendance.php', 'cancelFaceScan', json_header);
        if (faceMachine && ['IDLE','COMPLETED','CANCELLED'].indexOf(faceMachine.state) === -1) faceMachine.cancel();
        faceScan = null;
        advanceCandidateEpoch();
        $scope.faceLeaseExpired = false;
        $scope.faceManualFallbackAvailable = false;
        faceFinishRequested = false;
        restoreRosterSnapshot();
        scanRosterSnapshot = null;
        $scope.faceMatched = [];
        $scope.faceLiveWriteMode = false;
        $scope.faceSetupMode = false;
        $scope.reviewWithoutScan = false;
        $scope.commitError = '';
        $scope.refreshFailed = false;
        $scope.rosterErrorKind = '';
        setRosterState('INITIAL');
    }
    function finishAttendanceFlow(){
        setRosterState('INITIAL');
        rosterLoadedKey = null;
        $scope.list = [];
        $scope.postLoadDivShow = false;
        $scope.faceSetupMode = false;
        $scope.faceLiveWriteMode = false;
        $scope.reviewWithoutScan = false;
        scanRosterSnapshot = null;
    }
	function resetFaceAttendanceTestData(){
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}

		if ($scope.faceResetBusy) return;
		if ($scope.attendanceStage === 'SCANNING' || $scope.attendanceStage === 'COMMITTING') {
			Swal.fire('Finish the current action first', 'Finish or cancel the active attendance action before resetting test data.', 'warning');
			return;
		}
		var requiredConfirmation = 'RESET FACE ATTENDANCE TEST DATA';
		var typedConfirmation = window.prompt('This permanently clears face-recorded attendance, face scans, and recognition attempts. Enrolment templates and manual attendance are kept.\n\nType ' + requiredConfirmation + ' to continue.', '');
		if (typedConfirmation !== requiredConfirmation) {
			if (typedConfirmation !== null) Swal.fire('Reset not confirmed', 'Nothing was deleted because the confirmation text did not match.', 'info');
			return;
		}
		Swal.fire({
			title: 'Reset all face-attendance test data?',
			text: 'This cannot be undone. Enrolment templates and the purge audit ledger are kept.',
			icon: 'warning',
			showCancelButton: true,
			confirmButtonText: 'Yes, reset test data',
			cancelButtonText: 'Cancel'
		}).then(function(result){
			if (!result.isConfirmed) return;
			$scope.faceResetBusy = true;
			apiCall.callEndpoint({session_id: session_id, confirmation: requiredConfirmation}, 'v1/face-attendance.php', 'resetFaceAttendanceTestData', json_header).then(function(response){
				var body = response.data || response;
				if (body.error === 0 && body.state === 'RESET') {
					finishAttendanceFlow();
					clearAttendanceSelection();
					dateChange();
					var deleted = (body.data && body.data.deleted) || {};
					var total = Object.keys(deleted).reduce(function(sum, table){ return sum + (Number(deleted[table]) || 0); }, 0);
					var resetMessage = total > 0
						? total + ' face-attendance test records were cleared. Enrolment templates and manual attendance remain available.'
						: 'The reset completed. No face-attendance test records remained to remove.';
					Swal.fire('Test data reset', resetMessage, 'success');
				} else {
					Swal.fire('Reset was not completed', body.message || 'No data was deleted.', 'error');
				}
			}).catch(function(){
				Swal.fire('Reset was not completed', 'The server could not complete the reset. No confirmation of deletion was received.', 'error');
			}).finally(function(){
				$scope.faceResetBusy = false;
			});
		});
	}
    function initController(date, bid) {
        if(session.user_type == 'E' && session.user_id == '1'){ //skip permission given to kajal
            $scope.skip_attendance = true;
        }
        var loading = $('#loading');
        loading.fadeIn(250);
        $scope.list = [];
        $scope.disableDatePicker = true;
        var req = {"session_id":session.session_id,"col":['back_date_att','back_dated_days','back_dated_time','mark_absent'],"filter":[['bid','=',bid]],"table":"attendance_settings"};
        apiCall.callEndpoint(req, "settingRoute.php", "getSetting", json_header).then(function(response) {
            loading.fadeOut(250);
            var response_data = response.data;
            if (response_data.error == "0") {
                $scope.attSettings = response_data.data[0];
                if ($scope.attSettings.mark_absent == 1 && Number(session.admin) == 1) {
                    $scope.mark_absent = true;
                }else if($scope.attSettings.mark_absent == 2){
                    $scope.mark_absent = true;
                }else{
                    $scope.mark_absent = false;
                }
                // switch(response_data.data[0].back_date_att) {
                //     case '0':
                //         $scope.disableDatePicker = true;
                //         break;
                //     case '1': //Only Admin
                //         $scope.disableDatePicker = (Number(session.admin) == 1) ? false : true;
                //         break;
                //     case '2': //For every one
                //         $scope.disableDatePicker = false;
                //         break;
                // }
                // //Check for backdated time
                // if (response_data.data[0].back_dated_time !== undefined) {
                //     if (moment().isAfter(moment(response_data.data[0].back_dated_time, "HH:mm:ss"))) {
                //         $scope.disableDatePicker = true;
                //     }
                // }
            } else {
                $scope.disableDatePicker = true;
            }
        });
        getBatches(date, bid);

        // Assistant-trainer assignment is not part of the current attendance
        // workflow, so do not request employeeRoute.php for it.
        // var employee_data={"session_id":session_id};
        // apiCall.callEndpoint(employee_data,"employeeRoute.php","selectAsstTrainer",json_header).then(function(response){
        //     if(response.data.error == '0'){
        //         $scope.asst_trainer_list=response.data.data;
        //     }
        // });
    }

    function getBatches(date, bid) {
        var loadGeneration = ++batchLoadGeneration;
        var dt = moment(date,"DD-MM-YYYY");
        
        date = moment(date,"DD-MM-YYYY").format("YYYY-MM-DD");

        var req = {"session_id":session.session_id,"col":["contact_id","code","fullname","start_time","end_time","day_code","id","batch","module","offline","title","module_id","session_id"],"filter":[["start_date","<=",date],["inactive","=","0"],["end_date",">=",date],["day_code","=",dt.day()],["park","=","0"],["bid","=",bid],["training_assign","=",0],
        ["demo_class","=",0],["date","IN",["0000-00-00",date]],["contact_id","!=","0"]]};
        if (session.position_id == 18 || session.position_id == 19) {
            req.filter.push(["contact_id","=",session.contact_id]);
        }
        var loading = $('#loading');
        loading.fadeIn(250);
        $scope.batchesLoading = true;
        $scope.batchLoadError = '';
        $scope.batchLoadKind = '';

        apiCall.callEndpoint(req,"batchRoute.php","select",json_header).then(function (response) {
            if (loadGeneration !== batchLoadGeneration) return;
            $scope.showAsstTrainer = false; 
            loading.fadeOut(250);
            $scope.batchesLoading = false;
            var errorCode = Number(response && response.data ? response.data.error : NaN);
            if (errorCode === 2 || errorCode === 3) { recordRosterDiagnostic('classes_rejected', {code: errorCode}); $scope.batchLoadKind = errorCode === 2 ? 'session' : 'permission'; $scope.batchLoadError = errorCode === 2 ? 'Your session has expired. Please sign in again.' : "You don't have permission to view these classes."; }
            if(response.data.error == 0){
               $scope.batch.batches = response.data.data;
               if($scope.support_mode){
                    selectedBatch($scope.batch.id);
               } else {
                    restoreAttendanceSelection();
               }
            }
            else{
               $scope.batch.time = '';
               $scope.batch.id = '';
               $scope.list = [];
               $scope.batch.times = [];
               $scope.batch.batches = [];     
            }
            $scope.disableBatch = false;
        }).catch(function(error){
            if (loadGeneration !== batchLoadGeneration) return;
            loading.fadeOut(250);
            $scope.batchesLoading = false;
            $scope.batch.batches = [];
            var status = error && error.status;
            recordRosterDiagnostic('classes_request_failed', {status: status});
            $scope.batchLoadKind = status === 401 ? 'session' : status === 403 ? 'permission' : 'failed';
            $scope.batchLoadError = status === 401 ? 'Your session has expired. Please sign in again.'
                : status === 403 ? "You don't have permission to view these classes."
                : "Couldn't load classes.";
            $scope.disableBatch = false;
        });
    }
    $scope.retryBatchLoad = function(){
        if (!$scope.showDateError) getBatches($scope.batch.date, $scope.support_mode && $scope.support_data ? $scope.support_data.bid : session.bid);
    };

    function timeSelected() {
        if($scope.canChangeFaceTestContext && !$scope.canChangeFaceTestContext())return;
        resetForSelectionChange();
        if ($scope.timeIndex > -1 && $scope.batch.times[$scope.timeIndex]) {
            var selectedTime = $scope.batch.times[$scope.timeIndex];
            $scope.batch.time = selectedTime.start_time_string;
            $scope.batch.module = selectedTime.module;
            $scope.batch.title = selectedTime.title;
            $scope.batch.module_id = selectedTime.module_id;
            $scope.batch.session_id = selectedTime.session_id;
            $scope.isSessionBased = ($scope.batch.module.length > 0);
            saveAttendanceSelection($scope.batch.id, $scope.timeIndex);
            if (!$scope.support_mode) selectMembers();
        } else {
            $scope.batch.time = '';
            $scope.batch.module = '';
            $scope.batch.title = '';
            $scope.batch.module_id = '';
            $scope.batch.session_id = '';
            $scope.isSessionBased = true;
            $scope.list = [];
            saveAttendanceSelection($scope.batch.id, null);
        }
    }

    function dateChange(){
        if($scope.canChangeFaceTestContext && !$scope.canChangeFaceTestContext())return;
        resetForSelectionChange();
        batchLoadGeneration++;
        clearAttendanceSelection();
        if(typeof check_month !== 'undefined' && check_month){
            var dt = moment().format("YYYY-MM");
            var date = moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM");
            $scope.action_disable = (dt !== date);
        } else {
            $scope.action_disable = false;
        }
        $scope.pending_att_err = false;
        $scope.rescheduledBatch = false;
        $scope.cancelledBatch = false;
        $scope.disableBatch = true;
        $scope.batch.time = "";
        $scope.batch.id = "";
        $scope.batch.module = '';
        $scope.batch.title = '';
        $scope.batch.module_id = '';
        $scope.batch.session_id = '';
        $scope.isSessionBased = false;
        $scope.timeIndex = '-1';
        $scope.batch.batches = [];
        $scope.list = [];
        $scope.batchesLoading = false;
        $scope.batchLoadError = '';
        $scope.batchLoadKind = '';
        rosterLoadedKey = null;
        var allowed = false;
        //Check for back dated
        if ($scope.attSettings.back_date_att == 0) { //Past Date Not Allowed
            if (moment().diff(moment($scope.batch.date,"DD-MM-YYYY"),"days") > 0) {
                $scope.showDateError = true;
                $scope.dateError = "Past Date Not Allowed.";
            } else {
                allowed = true;
            }
        } else if ($scope.attSettings.back_date_att == 1 && Number(session.admin) == 1) { // Past Date only allowed for admins
            allowed = true;
            $scope.mark_absent = true;
            $scope.skip_attendance = true;
        } else if ($scope.attSettings.back_date_att == 2) { // Past Date allowed for all
            if (session.admin == 1) {
                allowed = true;
            }
            //Check timings and days
            else if ($scope.attSettings.back_dated_time != undefined && //Time is provided
                ((moment().isBefore(moment($scope.attSettings.back_dated_time, "HH:mm:ss")) && //Time is after current time
                moment().diff(moment($scope.batch.date,"DD-MM-YYYY"),"days") > $scope.attSettings.back_dated_days) ||
                (moment().isAfter(moment($scope.attSettings.back_dated_time, "HH:mm:ss")) && 
                moment().diff(moment($scope.batch.date,"DD-MM-YYYY"),"days") >  0))) { //Days are less then mentioned days
                $scope.showDateError = true;
                $scope.dateError = "Past Date Not Allowed.";
            } else {
                allowed = true;
            }
        } else if ($scope.attSettings.back_date_att == 1 && Number(session.admin) == 0) { //Past date only allowed for admins and user is not a admin
            if (moment().diff(moment($scope.batch.date,"DD-MM-YYYY"),"days") > 0) { // Check if  past date is selected
                $scope.showDateError = true;
                $scope.dateError = "Past Date Not Allowed.";
            } else {
                allowed = true;
            }
        }
        if (allowed) {
            $scope.showDateError = false;
            getBatches($scope.batch.date, session.bid);
        }
        //Check for future date and disable punch attendance button

    }

    function selectedBatch(batch_id){
        if($scope.canChangeFaceTestContext && !$scope.canChangeFaceTestContext())return;
        resetForSelectionChange();
        $scope.cancelledBatch = false;
        $scope.dupsPunchWarning = false;
        $scope.dupsMemPunchWarning = false;
        $scope.pending_att_err = false;
        $scope.batch.time = "";
        $scope.batch.module = '';
        $scope.batch.title = '';
        $scope.batch.module_id = '';
        $scope.batch.session_id = '';
        $scope.isSessionBased = false;
        $scope.timeIndex = '-1';
        $scope.list = [];
        if(batch_id != ''){

            for(var i = 0; i < $scope.batch.batches.length; i++){
                if($scope.batch.batches[i].id == batch_id){
                    // hiding the dropdown option from the UI.
                    $scope.showAsstTrainer=false;
                    // checking if the batch is offline.
                    $scope.batch.batches[i].offline == 1 ? $scope.offline = true : $scope.offline = false;
                    for(var j = 0; j < $scope.batch.batches[i].batch_time.length; j++){
                       $scope.batch.batches[i].batch_time[j].start_time_string =  moment($scope.batch.batches[i].batch_time[j].start_time,"HH:mm:ss").format("hh:mm A");
                       $scope.batch.batches[i].batch_time[j].end_time_string =  moment($scope.batch.batches[i].batch_time[j].end_time,"HH:mm:ss").format("hh:mm A"); 
                    }
                    
                    $scope.batch.times = $scope.batch.batches[i].batch_time;
                    if ($scope.batch.times.length == 1) {
                        $scope.timeIndex = '0';
                        timeSelected();
                    }
                    break;
                }
                else
                    $scope.batch.times = [];         
            }
            check_batch_cancellation_or_rescheduled(batch_id);
            
        }
        else{
          $scope.batch.times = [];
          $scope.list = [];
        }

        if (batch_id != '') {
            // Persist the deliberate batch change immediately. A later time
            // selection will replace the null time index with its exact value.
            saveAttendanceSelection(batch_id, null);
        } else {
            clearAttendanceSelection();
        }

        if($scope.support_mode){
            selectMembers(false);
        }
       
    }

    function check_batch_cancellation_or_rescheduled(batch_id){
        var loading = $('#loading');
        loading.fadeIn(250);
        var req_json = {"session_id":session.session_id,"col":['id'],"filter":[['batch_id','=',batch_id],['park','=','0'],['status','in',['2','3']],['date','=',moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD")]], "source":"4"};
        apiCall.callEndpoint(req_json,"attendanceRoute.php","select",json_header).then(function (response) {
            if (String($scope.batch.id) !== String(batch_id)) return;
            loading.fadeOut(250);
            if(response.data.error == 0 ){ 
                $scope.disableCancelBtn = true;
                $scope.disableRescheduleBtn = true;
                $scope.disablePunchBtn = true;
                $scope.cancelledBatch = true;
                rosterLoadGeneration++;
                $scope.list = [];
                $scope.postLoadDivShow = false;
                setRosterState('ERROR');
                $scope.rosterErrorKind = 'cancelled';
                $scope.rosterLoadError = 'This class was cancelled or rescheduled. Choose another class.';
            }
            else{
                // check_past_date(); //Not used as it is comparing string and a check is kept on date change
                if (moment().diff(moment($scope.batch.date,"DD-MM-YYYY"),"days") < 0) { //Future
                    $scope.disablePunchBtn = true;
                } else {
                    $scope.disablePunchBtn = false;
                }
                $scope.cancelledBatch = false;
                if(session.position_id>8 || session.position_id==7){
                    $scope.hideres=true;
                }else{
                    $scope.hideres=false;
                }
            }
        });
    }

    //Function not required as it is being checked on date change.
    // function check_past_date(){
    //     if($scope.batch.date < today && session.admin != '1'){
    //         $scope.disableCancelBtn = true;
    //         $scope.disableRescheduleBtn = true;
    //     } 
    //     else{
    //         $scope.disableCancelBtn = false;
    //         $scope.disableRescheduleBtn = false;
    //     }
    // }

    
    function cancelBatch(batch_id){
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}
        if ($scope.cancelledBatch) {
            show_snack("Batch Cancelled / Rescheduled");
            return;
        }
        var req_json = {"session_id":session.session_id,"col":['id'],"filter":[['batch_id','=',batch_id],['park','=','0'],['status','=','1'],['date','=',moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD")]], "source":"4"};
        apiCall.callEndpoint(req_json,"attendanceRoute.php","select",json_header).then(function (response) {
            if(response.data.error == 0 ){ 
                show_snack("Batch is already completed cannot be cancelled, kindly contact admin");
                $scope.disableCancelBtn = true;
                $scope.disableRescheduleBtn = true;
                $scope.disablePunchBtn = true;
                $scope.cancelledBatch = false;
            }
            else{
            var confirmation = confirm("Do you really want to cancel the batch ??");
            if (confirmation) {
                var loading = $('#loading');
                loading.fadeIn(250);
                var req_json = {"session_id":session.session_id,"bid":session.bid,"status":"2","date":moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD"),"batch_id":$scope.batch.id, "source":"4"};
                apiCall.callEndpoint(req_json,"attendanceRoute.php","batchLog",json_header).then(function (response) { 
                    loading.fadeOut(250);
                    if(response.data.error == 0){ 
                        show_snack(response.data.message);
                        $scope.disableCancelBtn = true;
                        $scope.disableRescheduleBtn = true;
                        $scope.disablePunchBtn = true;
                        $scope.cancelledBatch = true;

                    }
                    else{
                        show_snack(response.data.message);
                        $scope.disableCancelBtn = false;
                        $scope.disableRescheduleBtn = false;
                        $scope.disablePunchBtn = false;
                    }
                });
            }
        }   
    });
}


    function selectMembers(after_punch = true){
        $scope.pending_att_err = false;
        $scope.showAsstTrainer = false;
        if (!validate()) return;

        var contextKey = rosterContextKey();
        // The same unchanged request is already on its way; do not send it twice.
        if (rosterInFlight && rosterInFlight.key === contextKey && rosterInFlight.generation === rosterLoadGeneration) return;
        // Refreshing the class already on screen keeps its rows visible.
        var previousRows = rosterLoadedKey === contextKey && !$scope.support_mode ? memberRows() : [];
        var refreshing = previousRows.length > 0;
        var loadGeneration = ++rosterLoadGeneration;
        rosterInFlight = {key: contextKey, generation: loadGeneration};
        $scope.allCheck = false;
        $scope.noResults = false;
        $scope.preLoadDivShow = true;
        $scope.disableShowAttendees = true;
        $scope.rosterLoadError = '';
        $scope.rosterErrorKind = '';
        $scope.refreshFailed = false;
        $scope.rosterSlow = false;
        $scope.rosterRefreshing = refreshing;
        $scope.faceSetupMode = false;
        $scope.reviewWithoutScan = false;
        if (!refreshing) {
            rosterLoadedKey = null;
            $scope.postLoadDivShow = false;
            setRosterState('LOADING');
            $scope.list = [];
            $scope.faceStatusLoaded = false;
            $scope.faceStatusPending = false;
            $scope.browserFaceStatusError = '';
            $scope.browserFaceOperatingMode = undefined;
        } else {
            // Rows for this exact class stay on screen while they refresh.
            setRosterState('LOADED');
        }
        armRosterTimers(loadGeneration);

        var attendanceDate = moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD");
        var req = {
            session_id: session.session_id,
            batch_id: $scope.batch.id,
            date: attendanceDate,
            attendance_session_id: $scope.batch.session_id || 0,
            col: ["code","contact_id","item_id","fullname","session_consumed","sessions","sessions_used","end_date"],
            filter: [
                ["item_park","=","0"], ["park","=","0"], ["batch_id","=",$scope.batch.id],
                ["start_date","<=",attendanceDate], ["end_date",">=",attendanceDate],
                ["module_id","IN",["0",$scope.batch.module_id]]
            ],
            order: [["fullname","asc"]]
        };
        apiCall.callEndpoint(req,"memberRoute.php","selectBatchMember",json_header).then(function(response){
            if (loadGeneration !== rosterLoadGeneration) return;
            var errorCode = Number(response && response.data ? response.data.error : NaN);
            // selectBatchMember: 1 = no members found, 2 = session invalid, 3 = no permission.
            if (errorCode === 1) {
                rosterLoadedKey = contextKey;
                $scope.noResults = true;
                $scope.postLoadDivShow = false;
                setRosterState('EMPTY');
                $scope.list = [];
                return;
            }
            if (errorCode !== 0) {
                recordRosterDiagnostic('roster_rejected', {code: errorCode});
                showRosterError(errorCode === 2 ? 'session' : errorCode === 3 ? 'permission' : 'failed', refreshing);
                return;
            }

            var rows = [];
            for (var batchIndex = 0; batchIndex < $scope.batch.batches.length; batchIndex++) {
                if ($scope.batch.batches[batchIndex].id != $scope.batch.id) continue;
                var employees = $scope.batch.batches[batchIndex].employee || [];
                employees.forEach(function(employee){
                    employee.session_consumed = '—';
                    employee.inactive = false;
                    employee.type = 'E';
                    employee.selected = true;
                    employee.skip = false;
                    rows.push(employee);
                });
                break;
            }

            var hasRemainingSessions = window.attendanceEligibility && window.attendanceEligibility.hasRemainingSessions;
            if (typeof hasRemainingSessions !== 'function') {
                hasRemainingSessions = function(item){
                    var sessions = parseInt(item && item.sessions, 10);
                    var sessionsUsed = parseInt(item && item.sessions_used, 10);
                    return sessions === 0 || sessions > sessionsUsed;
                };
            }
            var seenContacts = {};
            (response.data.data || []).filter(hasRemainingSessions).forEach(function(member){
                var contactKey = String(member.contact_id);
                if (seenContacts[contactKey]) return;
                seenContacts[contactKey] = true;
                member.type = 'M';
                member.attendance_id = member.attendance_id || '0';
                member.selected = String(member.negative) === '0';
                member.negative = member.selected ? 0 : 1;
                member.skip = false;
                rows.push(member);
            });

            if (refreshing) carryFaceStatus(previousRows, rows);
            $scope.list = ($scope.support_mode && !after_punch)
                ? JSON.parse($scope.support_data.list_of_data)
                : rows;
            rosterLoadedKey = contextKey;
            var studentCount = memberRows().length;
            $scope.postLoadDivShow = studentCount > 0;
            $scope.noResults = studentCount === 0;
            setRosterState(studentCount > 0 ? 'LOADED' : 'EMPTY');
            scanRosterSnapshot = null;
            if (studentCount > 0) {
                refreshAutomaticStatus();
            }
        }).catch(function(error){
            if (loadGeneration !== rosterLoadGeneration) return;
            var status = error && error.status;
            recordRosterDiagnostic('roster_request_failed', {status: status});
            showRosterError(status === 401 ? 'session' : status === 403 ? 'permission' : 'failed', refreshing);
        }).finally(function(){
            if (loadGeneration !== rosterLoadGeneration) return;
            clearRosterTimers();
            rosterInFlight = null;
            $scope.rosterSlow = false;
            $scope.rosterRefreshing = false;
            $scope.preLoadDivShow = false;
            $scope.disableShowAttendees = false;
        });
    }

    var ROSTER_MESSAGES = {
        failed: "Couldn't load attendance.",
        timeout: "Couldn't load attendance.",
        session: 'Your session has expired. Please sign in again.',
        permission: "You don't have permission to view this class."
    };
    // Support-only trail: bounded, no names, ids or payloads. Operators never see it.
    var rosterDiagnostics = window.attendanceDiagnostics = window.attendanceDiagnostics || [];
    function recordRosterDiagnostic(kind, detail){
        rosterDiagnostics.push({at: Date.now(), kind: kind, status: detail && detail.status || null, code: detail && detail.code != null ? detail.code : null});
        while (rosterDiagnostics.length > 20) rosterDiagnostics.shift();
    }
    // Slow is not failure: say so after a pause, and only report an error after
    // a generous limit. A late response for the same request is still applied.
    var ROSTER_SLOW_MS = 6000, ROSTER_TIMEOUT_MS = 45000;
    var rosterSlowTimer = null, rosterTimeoutTimer = null, rosterInFlight = null, rosterLoadedKey = null;
    function rosterContextKey(){
        return [$scope.batch.date, $scope.batch.id, $scope.batch.session_id || '', $scope.batch.time || ''].join('|');
    }
    function clearRosterTimers(){
        clearTimeout(rosterSlowTimer);
        clearTimeout(rosterTimeoutTimer);
        rosterSlowTimer = rosterTimeoutTimer = null;
    }
    function armRosterTimers(generation){
        clearRosterTimers();
        rosterSlowTimer = setTimeout(function(){
            $scope.$evalAsync(function(){
                if (generation !== rosterLoadGeneration || !rosterInFlight) return;
                $scope.rosterSlow = true;
                if ($scope.rosterState === 'LOADING') setRosterState('SLOW_LOADING');
            });
        }, ROSTER_SLOW_MS);
        rosterTimeoutTimer = setTimeout(function(){
            $scope.$evalAsync(function(){
                if (generation !== rosterLoadGeneration || !rosterInFlight) return;
                recordRosterDiagnostic('roster_timeout', {});
                showRosterError('timeout', $scope.rosterRefreshing);
            });
        }, ROSTER_TIMEOUT_MS);
    }
    function showRosterError(kind, keepRows){
        $scope.rosterSlow = false;
        if (keepRows) {
            // Rows for this class are still valid: keep them and say that only the refresh failed.
            $scope.refreshFailed = true;
            setRosterState('LOADED');
            return;
        }
        $scope.rosterErrorKind = kind;
        $scope.rosterLoadError = ROSTER_MESSAGES[kind] || ROSTER_MESSAGES.failed;
        $scope.list = [];
        $scope.postLoadDivShow = false;
        setRosterState('ERROR');
    }
    $scope.reloadPage = function(){ window.location.reload(); };
    function carryFaceStatus(previousRows, rows){
        var byContact = {};
        previousRows.forEach(function(row){ byContact[String(row.contact_id)] = row; });
        rows.forEach(function(row){
            var previous = row.type === 'M' && byContact[String(row.contact_id)];
            if (!previous) return;
            Object.keys(previous).forEach(function(key){
                if (key.indexOf('browser_face_') === 0 || key === 'face_mapping_active') row[key] = previous[key];
            });
        });
    }
    $scope.retryRosterLoad = function(){
        if ($scope.cancelledBatch) return;
        $scope.refreshFailed = false;
        rosterInFlight = null;
        if (validate()) selectMembers();
    };

    function open_modal(data,modal){
        openModal(data, modal, 1,2,'',0, $scope);
    }


    function punchAttendance(valid){
        if (!memberRows().length) {
            show_snack('No members found for this class.');
            return false;
        }
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}
        $scope.pending_att_err = false;
        var pending_att = 0;
        memberRows().forEach(element => {
            if(element.selected == undefined && element.skip == false){
                pending_att++;
            }
        });
        if(pending_att){
            $scope.pending_att_err = true;
            return false;
        }
        if(validate()){
                    //prepare contact array
                    var cont_arr = [];
                    for (var i = 0; i < $scope.list.length ; i++) {
                        var cont = $scope.list[i];
                        if (cont.type === 'M' && !cont.inactive && cont.selected) {
                            cont_arr.push(cont.contact_id);
                        }
                    }
                    var loading = $('#loading');
                    loading.fadeIn(250);
                    var json_req = {"session_id":session.session_id,"col":["contact_id"],"bid":session.bid,
                    "filter":[["date","=",moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD")],["batch_id","=",$scope.batch.id],["park","=","0"],["status","=","1"],["negative",'!=','1'],["session_id","=",$scope.batch.session_id]]};
                    apiCall.callEndpoint(json_req,"attendanceRoute.php","select",json_header).then(function(response) {
                        loading.fadeOut(250);
                        if (response.data.error == 0) {
                            $scope.dupsMemPunchWarning = true;
                            $scope.punchBtn = false;
                            $scope.continueBtn = true;
                            $scope.members = "";
                            for (var i = 0; i < response.data.data.length;i++) {
                                contact_data = response.data.data[i];
                                for (var j = 0; j < $scope.list.length ; j++) {
                                    var cont = $scope.list[j];
                                    if (cont.contact_id == contact_data.contact_id) {
                                        $scope.members += ($scope.members.length > 0 ? ", " : "") + cont.code + " - " + cont.fullname;
                                    }
                                }
                            }
                        }else {
                            punch();
                            
                        }
                    });
        }
    }


    function punch(){
        if (!memberRows().length) {
            show_snack('No members found for this class.');
            return;
        }
        if($scope.browserFaceOperatingMode==='TEST' || $scope.faceTestPunchRequest || ($scope.faceTestPending && $scope.faceTestPending.length))return punchAttendance();
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}
        var bid = session.bid;
        if($scope.support_mode){
            bid = $scope.support_data.bid;
        }
        Swal.fire({
            title: 'Punch attendance now?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, Punch Attendance'
        }).then((result) => {
            if (result.isConfirmed) {
                $scope.attendanceStage = 'COMMITTING';
                $scope.savingAttendance = true;
                $scope.commitError = '';
                $scope.defaultPunch = false;
                $scope.processingPunch = true;
                $scope.dupsPunchWarning = false;
                $scope.punchBtn = true;
                $scope.continueBtn = false;
                var loading = $('#loading');
                loading.fadeIn(250);
                var json_req = {
                    "session_id":session.session_id,
                    "s_id":$scope.batch.session_id,
                    "contacts":$scope.list,
                    "asst_trainer_id":$scope.asst_trainer,
                    "bid":bid,
                    "time":moment($scope.batch.time,"hh:mm A").format("HH:mm:ss"),
                    "date":moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD"),
                    "batch_id":$scope.batch.id,
                    "check_month":check_month
                };
                apiCall.callEndpoint(json_req,"attendanceRoute.php","insertBatchAttendance",json_header).then(function (response) { 
                    loading.fadeOut(250);
                    $scope.savingAttendance = false;
                    $scope.attendanceResults=(response.data.data && response.data.data.results)||[];
                    var refused=$scope.attendanceResults.filter(function(p){return Number(p.error)!==0;});
                    if(refused.length){
                        $scope.commitError=refused.map(function(p){return p.contact_id+': '+p.message;}).join('; ');
                        $scope.attendanceStage='REVIEW';
                    }
                    if(response.data.error == 0 && !refused.length){
                        show_snack(response.data.message);
                        scanRosterSnapshot = null;
                        $scope.attendanceStage = 'COMPLETE';
                        $scope.faceSetupMode = false;
                        if(moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD") < moment().format("YYYY-MM-DD")){
                            openModal({'id':$scope.batch.id,'date':moment($scope.batch.date,"DD-MM-YYYY").format("YYYY-MM-DD")},'batchClassForward',1,2,'Action',0,$scope);
                            update_support();
                            $scope.asst_trainer = "";
                        }
                    }else{
                        $scope.attendanceStage = 'REVIEW';
                        $scope.commitError = response.data.message || 'Attendance could not be saved. Nothing was lost; try again.';
                        update_support();
                        show_snack($scope.commitError);
                    }
                    $scope.defaultPunch = true;
                    $scope.processingPunch = false;
                }).catch(function(){
                    loading.fadeOut(250);
                    $scope.savingAttendance = false;
                    $scope.defaultPunch = true;
                    $scope.processingPunch = false;
                    $scope.attendanceStage = 'REVIEW';
                    $scope.commitError = 'Attendance could not be saved. Nothing was lost; check the connection and try again.';
                });
                }else{
                    $scope.attendanceStage = 'REVIEW';
                    return false;
                }
        });
    }


    function selectAll(flag){
        if ($scope.faceUiEnabled) return;
        $scope.punchBtn = true;
        $scope.continueBtn = false;
        for (var i = 0; i < $scope.list.length; i++){
            if($scope.list[i].type != 'E' && ($scope.list[i].attendance_id == undefined || $scope.list[i].attendance_id == '0')){
                $scope.list[i].selected = flag; 
            }
        }
    }

    function selectSkip(){
        $scope.list.forEach(element => {
            if(element.skip == true){
                element.selected = undefined;
            }
        });
        console.table($scope.list);
    }

    function selectDeselectItem(type){
            $scope.punchBtn = true;
            $scope.continueBtn = false;
            var cnt = 0;
            var eAtt = false;
            $scope.list.forEach(element => {
                if(element.selected != undefined){
                    element.skip = false;
                }
            });
            for (var i = 0; i < $scope.list.length; i++){
                if($scope.list[i].type === 'M' && $scope.list[i].inactive == false && $scope.list[i].selected == false){
                    cnt++; 
                }
            }
            
            if(cnt) 
                $scope.allCheck = false;
            else
                $scope.allCheck = true;
        //    console.table($scope.list);
    }

    function reschedule(){
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}
        if ($scope.cancelledBatch) {
            show_snack("Batch Cancelled / Rescheduled");
            return;
        }
        var batch_info = {};
        for(var i = 0; i < $scope.batch.batches.length; i++){
            if($scope.batch.batches[i].id == $scope.batch.id){
                batch_info = $scope.batch.batches[i];
            }
        }
        $("#modal-rebatch").modal("show");

        angular.element("#modal-rebatch").scope().initController($scope.batch.id,batch_info,$scope.batch.date);
    }
    

    function validate(){
        var flag;
        $scope.dateStyle = "#cccccc"; 
        $scope.batchStyle = "#cccccc"; 
        $scope.timeStyle = "#cccccc"; 

        if($scope.batch.date == "" || $scope.batch.date == undefined || $scope.batch.date == null){
            $scope.dateStyle = "red"; 
            flag = false;
        }
        else if($scope.batch.id == "" || $scope.batch.id == undefined || $scope.batch.id == null){
            $scope.batchStyle = "red"; 
            flag = false;
        }
        else if($scope.batch.time == "" || $scope.batch.time == undefined || $scope.batch.time == null){
            $scope.timeStyle = "red"; 
            flag = false;
        }
        else
             flag = true;

         return flag;
    }
 	
    function update_support(){
        if($scope.browserFacePilot){show_snack('Use the practice camera controls.');return;}

        let data = {"session_id": session_id, "u":[{"col": 'status', "val": '1'}], "filter":[['id','=',$scope.support_data.support_id]]};
        apiCall.callEndpoint(data,"supportRoute.php","update",json_header).then(function (response) { 
            if(response.data.error == 0){
                show_snack('Punch hogaya bhai!');
            }else{
                show_snack('Unsuccessful!');
            }
        });
    }

    function readAttendanceSelection(){
        try {
            var raw = window.sessionStorage.getItem(attendanceSelectionStorageKey);
            if (!raw) return null;
            var value = JSON.parse(raw);
            if (!value || typeof value !== 'object' || !value.date || !value.batch_id) return null;
            return value;
        } catch (error) {
            return null;
        }
    }

    function saveAttendanceSelection(batchId, timeIndex){
        if (!batchId) return;
        try {
            window.sessionStorage.setItem(attendanceSelectionStorageKey, JSON.stringify({
                date: $scope.batch.date,
                batch_id: String(batchId),
                time_index: timeIndex === null || timeIndex === undefined || timeIndex === '' || String(timeIndex) === '-1'
                    ? null
                    : Number(timeIndex)
            }));
        } catch (error) {
            // Storage is a convenience only; attendance remains usable without it.
        }
    }

    function clearAttendanceSelection(){
        savedAttendanceSelection = null;
        try { window.sessionStorage.removeItem(attendanceSelectionStorageKey); } catch (error) {}
    }

    function restoreAttendanceSelection(){
        var selection = $scope.browserFacePilot ? savedAttendanceSelection : legacyFaceQuery.has('batch_id') ? savedAttendanceSelection : (readAttendanceSelection() || savedAttendanceSelection);
        if (!selection || selection.date !== $scope.batch.date) return;
        var batchExists = $scope.batch.batches.some(function(item){
            return String(item.id) === String(selection.batch_id);
        });
        if (!batchExists) return;
        $scope.batch.id = String(selection.batch_id);
        selectedBatch($scope.batch.id);
        if ($scope.batch.times.length === 1) return;
        var selectedIndex = legacyFaceQuery.has('time') ? $scope.batch.times.findIndex(function(t){return t.start_time===legacyFaceQuery.get('time');}) : Number(selection.time_index);
        if (isFinite(selectedIndex) && Math.floor(selectedIndex) === selectedIndex && selectedIndex >= 0 && selectedIndex < $scope.batch.times.length) {
            $scope.timeIndex = String(selectedIndex);
            timeSelected();
        } else if (selection.time_index === null || selection.time_index === undefined) {
            // Preserve an intentional "Select Time" choice instead of
            // allowing selectedBatch() to visually select the first option.
            $scope.timeIndex = '-1';
            timeSelected();
        }
    }

    var enrollmentBurstA = [];
    var enrollmentBurstB = [];
    var enrollmentCaptureContexts = [];
    var enrollmentBurstAContexts = [];
    var enrollmentBurstBContexts = [];

    function startFaceEnrollment(row, mode){
        if (!$scope.faceEnrollmentUiEnabled || !row || row.type !== 'M' || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            $scope.faceEnrollmentMessage = 'Face setup is unavailable on this device.';
            return;
        }
        var activeFaceScanStates = ['STARTING_CAMERA', 'SCANNING', 'CAPTURING', 'PROCESSING', 'MATCHED', 'REVIEW', 'REJECTED', 'MANUAL', 'ERROR', 'RECONCILING'];
        if (activeFaceScanStates.indexOf($scope.faceState) !== -1) {
            $scope.faceMessage = 'Finish or cancel attendance scanning before setting up a face.';
            return;
        }
        if ($scope.faceEnrollmentState !== 'IDLE') return;
        if (!row.contact_id) {
            $scope.faceEnrollmentMessage = 'The selected child has no member contact identifier.';
            return;
        }
        resetReferenceMismatch();
        $scope.faceEnrollmentIdentityWarning = '';
        resetGuidedEnrollment();
        enrollmentMember = row;
        enrollmentRunId = 'setup_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
        enrollmentRunLogged = false;
        if (!enrollmentRetrying) {
            enrollmentSetupStartedAt = Date.now();
            enrollmentReferencePresentAtStart = false;
            enrollmentRetryCount = 0;
        } else {
            enrollmentRetryCount++;
            enrollmentRetrying = false;
        }
        var requestSequence = ++captureRequestSequence;
        var cameraGeneration = ++enrollmentCameraGeneration;
        captureSessionToken = null;
        enrollmentFrames = [];
        enrollmentBurstA = [];
        enrollmentBurstB = [];
        enrollmentCaptureContexts = [];
        enrollmentBurstAContexts = [];
        enrollmentBurstBContexts = [];
        adaptiveCandidateTokens = [];
        $scope.faceEnrollmentDiagnosticSamples = [];
        window.faceEnrollmentDiagnostics = $scope.faceEnrollmentDiagnosticSamples;
        clearFaceEnrollmentIssue();
        $scope.faceEnrollmentMemberName = row.fullname || 'selected child';
        $scope.faceEnrollmentCalibrationPending = false;
        $scope.faceEnrollmentVerificationSummary = '';
        $scope.faceEnrollmentCaptureState = 'UNAVAILABLE';
        $scope.faceEnrollmentHasReference = false;
        $scope.faceEnrollmentReferenceId = null;
        $scope.faceEnrollmentReferencePortraitUrl = null;
        $scope.faceEnrollmentReferenceBusy = false;
        $scope.faceEnrollmentState = 'STARTING_CAMERA';
        $scope.faceEnrollmentMessage = 'Starting camera for ' + row.fullname + '.';

        // Begin a server-bound capture session before accepting any inference result.
        $http({
            method: 'POST',
            url: window.location.origin + '/routes/faceEnrollmentRoute.php',
            params: {action: 'beginEnrollment'},
            data: {
                session_id: session_id || session.session_id,
                contact_id: row.contact_id,
                member_id: row.member_id || 0,
                branch_id: session.bid,
                mode: mode || 'ENROLLMENT'
            },
            headers: {
                'uid': String(session.user_id),
                'bid': String(session.bid),
                'token': String(session.token),
                'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
            }
        }).then(function(res){
            if (requestSequence !== captureRequestSequence) return;
            var body = res.data || res;
            if (body.state !== 'READY') {
                $scope.faceEnrollmentState = 'ERROR';
                $scope.faceEnrollmentMessage = body.message || 'Could not start face setup.';
                stopEnrollmentCamera();
                return;
            }
            captureSessionToken = body.capture_session_token;
            if (enrollmentRetryCount === 0) enrollmentReferencePresentAtStart = !!body.has_pending_reference;
            if (enrollmentStream && enrollmentStream.getVideoTracks().some(function(track){ return track.readyState === 'live'; }) && $scope.faceEnrollmentState === 'STARTING_CAMERA') {
                $scope.faceEnrollmentState = 'READY';
                $scope.faceEnrollmentMessage = 'Ready to capture ' + row.fullname + '. Position face in guide and capture.';
                resetEnrollmentDetectionUi();
                startEnrollmentDetection();
            }
            if (body.has_pending_reference && body.pending_reference) {
                $scope.faceEnrollmentHasReference = true;
                $scope.faceEnrollmentReferenceId = body.pending_reference.id;
                $scope.faceEnrollmentReferencePortraitUrl = body.pending_reference.portrait_data_url;
                $scope.faceEnrollmentCaptureState = 'CLEAR';
            } else {
                $scope.faceEnrollmentHasReference = false;
                $scope.faceEnrollmentReferenceId = null;
                $scope.faceEnrollmentReferencePortraitUrl = null;
                $scope.faceEnrollmentCaptureState = 'UNAVAILABLE';
            }
        }).catch(function(){
            if (requestSequence !== captureRequestSequence) return;
            $scope.faceEnrollmentState = 'ERROR';
            $scope.faceEnrollmentMessage = 'Could not start face setup. Please try again.';
            stopEnrollmentCamera();
        });

        navigator.mediaDevices.getUserMedia(getCameraConstraints($scope.cameraFacingMode)).then(function(stream){
            if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration || $scope.faceEnrollmentState !== 'STARTING_CAMERA') { stream.getTracks().forEach(function(track){ track.stop(); }); return; }
            enrollmentStream = stream;
            stream.getVideoTracks().forEach(function(track){
                track.addEventListener('ended', function(){
                    if (enrollmentStream !== stream || ['READY','CAPTURING','LEAVE_GUIDE'].indexOf($scope.faceEnrollmentState) === -1) return;
                    $scope.$evalAsync(function(){
                        if (enrollmentStream !== stream) return;
                        $scope.faceEnrollmentState = 'ERROR';
                        $scope.faceEnrollmentMessage = 'Camera disconnected. Tap Try Again to resume face setup.';
                        stopEnrollmentCamera();
                    });
                });
            });
            var video = document.getElementById('faceEnrollmentVideo');
            if (video) {
                video.srcObject = stream;
                video.onloadedmetadata = function(){ syncEnrollmentPreviewFrame(video); };
                video.play();
                syncEnrollmentPreviewFrame(video);
            }
            $scope.$evalAsync(function(){
                if (!captureSessionToken || $scope.faceEnrollmentState !== 'STARTING_CAMERA' || !enrollmentStream || !enrollmentStream.getVideoTracks().some(function(track){ return track.readyState === 'live'; })) return;
                $scope.faceEnrollmentState = 'READY';
                $scope.faceEnrollmentMessage = 'Ready to capture ' + row.fullname + '. Position face in guide and capture.';
                resetEnrollmentDetectionUi();
                startEnrollmentDetection();
            });
        }).catch(function(){
            if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
            $scope.$evalAsync(function(){
                $scope.faceEnrollmentState = 'ERROR';
                $scope.faceEnrollmentMessage = 'Camera permission was denied. Allow camera access and try again.';
            });
        });
    }

    function formatFaceQualityError(message, reason) {
        if (!message || typeof message !== 'string') return 'Face setup needs attention. Position face inside guide and try again.';
        var diagnostic = String(reason || '') + ': ' + message;
        if (diagnostic.indexOf('PAD_CALIBRATION_REQUIRED') !== -1) return 'Face setup is temporarily unavailable while the liveness system is being validated. No face was saved; this is not caused by positioning.';
        if (diagnostic.indexOf('NO_FACE_IN_GUIDE') !== -1 || diagnostic.indexOf('NO_FACE:') !== -1) return 'No target face is inside the oval. Move the selected child into the guide and tap Capture.';
        if (diagnostic.indexOf('MULTIPLE_FACES_IN_GUIDE') !== -1) return 'Two faces are competing inside the oval. Keep only the selected child in the guide and tap Capture.';
        if (diagnostic.indexOf('REFERENCE_PAD_SPOOF') !== -1 || diagnostic.indexOf('PAD_REJECTED') !== -1) return 'Liveness check needs a clearer live-face view. Ensure good front lighting, avoid screen reflections, and hold still.';
        if (diagnostic.indexOf('FACE_TOO_LOW') !== -1) return 'Face is slightly too low. Move face up into the guide and tap Capture.';
        if (diagnostic.indexOf('FACE_TOO_HIGH') !== -1) return 'Face is slightly too high. Move face down into the guide and tap Capture.';
        if (diagnostic.indexOf('FACE_TOO_LEFT') !== -1) return 'Face is too far left. Move face right into the guide and tap Capture.';
        if (diagnostic.indexOf('FACE_TOO_RIGHT') !== -1) return 'Face is too far right. Move face left into the guide and tap Capture.';
        if (diagnostic.indexOf('FACE_OUTSIDE_GUIDE') !== -1) return 'Keep face centered inside the oval guide and tap Capture.';
        if (diagnostic.indexOf('FACE_OFF_CENTRE') !== -1) return 'Center face inside the oval guide and tap Capture.';
        if (diagnostic.indexOf('FACE_TOO_CLOSE') !== -1 || diagnostic.indexOf('FACE_TOO_LARGE') !== -1) return 'Face is too close. Move back slightly and tap Capture.';
        if (diagnostic.indexOf('FACE_TOO_SMALL') !== -1 || diagnostic.indexOf('FACE_TOO_FAR') !== -1) return 'Face is too far away. Move closer to the camera and tap Capture.';
        if (diagnostic.indexOf('BLUR') !== -1) return 'Camera image was blurred. Hold still and tap Capture.';
        if (diagnostic.indexOf('POOR_LIGHTING') !== -1) return 'Lighting is dim or uneven. Ensure good lighting on face and tap Capture.';
        return message.replace(/^(REFERENCE_QUALITY_FAILED:\s*|QUALITY_FAILED:\s*)/i, '');
    }

    function formatFaceAttendanceGuidance(result) {
        result = result || {};
        var liveness = String(result.liveness || '').toUpperCase();
        var quality = result.quality || {};
        var reason = String(result.reason_code || '').toUpperCase();
        if (reason === 'NO_FACE_IN_GUIDE' || reason === 'NO_FACE') {
            return 'No target face is inside the oval. Move the child into the guide and tap Check Again.';
        }
        if (reason === 'MULTIPLE_FACES_IN_GUIDE') {
            return 'Two faces are competing inside the oval. Keep only the child being scanned in the guide and tap Check Again.';
        }
        if (result.stage === 'QUALITY') {
            return 'Re-center the face in even light, hold still, and tap Check Again.';
        }
        if (liveness === 'SPOOF') {
            return 'Liveness could not be verified. Continue with the attendance list.';
        }
        if (liveness === 'UNKNOWN' || liveness === 'NOT_RUN') {
            return 'Face scanning is temporarily unavailable. Continue with the attendance list.';
        }
        if (quality.face_count === 0) {
            return 'No face was captured clearly. Center one full face inside the oval and tap Try Again.';
        }
        if (quality.pose_ok === false || quality.centred_ok === false) {
            return 'Keep the face straight and centered inside the oval, then tap Try Again.';
        }
        if (quality.sharpness_ok === false) {
            return 'The captured image was blurred. Hold the device and face still, then tap Try Again.';
        }
        if (quality.exposure_ok === false) {
            return 'The face needs more even front lighting. Avoid a bright window behind the person, then tap Try Again.';
        }
        if (result.stage === 'MATCHING' || reason.indexOf('IDENTITY_') === 0) {
            return 'The face could not be identified safely. Continue with the attendance list.';
        }
        return 'This face could not be confirmed. Continue with the attendance list.';
    }

    function fitFaceCaptureDimensions(width, height) {
        var sourceWidth = Math.max(1, width || 640);
        var sourceHeight = Math.max(1, height || 480);
        var scale = Math.min(1, 1280 / sourceWidth, 720 / sourceHeight);
        return {
            width: Math.max(1, Math.round(sourceWidth * scale)),
            height: Math.max(1, Math.round(sourceHeight * scale))
        };
    }

    function videoContentRect(video) {
        var displayWidth = video.clientWidth || video.offsetWidth || 1;
        var displayHeight = video.clientHeight || video.offsetHeight || 1;
        var rawWidth = video.videoWidth || 1;
        var rawHeight = video.videoHeight || 1;
        var scale = Math.min(displayWidth / rawWidth, displayHeight / rawHeight);
        var width = rawWidth * scale;
        var height = rawHeight * scale;
        return {x: (displayWidth - width) / 2, y: (displayHeight - height) / 2, width: width, height: height};
    }

    function buildCaptureContext(video, canvas) {
        var frame = video.closest ? video.closest('.face-camera-frame') : video.parentNode;
        var guide = frame ? frame.querySelector('.face-camera-guide') : null;
        var content = videoContentRect(video);
        var guideLeft = guide ? guide.offsetLeft : content.x + content.width * 0.20;
        var guideTop = guide ? guide.offsetTop : content.y + content.height * 0.10;
        var guideWidth = guide ? guide.offsetWidth : content.width * 0.60;
        var guideHeight = guide ? guide.offsetHeight : content.height * 0.72;
        var displayedCx = (guideLeft + guideWidth / 2 - content.x) / Math.max(1, content.width);
        var rawCx = $scope.cameraFacingMode === 'user' ? 1 - displayedCx : displayedCx;
        function clamp(value, low, high) { return Math.max(low, Math.min(high, value)); }
        var rx = clamp((guideWidth / 2) / Math.max(1, content.width), 0.01, 0.5);
        var ry = clamp((guideHeight / 2) / Math.max(1, content.height), 0.01, 0.5);
        var cx = clamp(rawCx, rx, 1 - rx);
        var cy = clamp((guideTop + guideHeight / 2 - content.y) / Math.max(1, content.height), ry, 1 - ry);
        return {
            version: 'face-capture-v1',
            capture_id: 'web-' + Date.now() + '-' + Math.random().toString(36).slice(2, 12),
            frame: {width: canvas.width, height: canvas.height},
            display: {
                width: video.clientWidth || video.offsetWidth || content.width,
                height: video.clientHeight || video.offsetHeight || content.height,
                content_rect: content,
                object_fit: 'contain'
            },
            guide: {cx: cx, cy: cy, rx: rx, ry: ry},
            mirrored_preview: $scope.cameraFacingMode === 'user',
            rotation: 0,
            source: 'canvas-video'
        };
    }

    function freezeSubmittedFrame(video, dataUrl) {
        var frame = video.closest ? video.closest('.face-camera-frame') : video.parentNode;
        if (!frame) return;
        var previous = frame.querySelector ? frame.querySelector('.face-capture-freeze') : null;
        if (previous && previous.parentNode) previous.parentNode.removeChild(previous);
        var still = document.createElement('img');
        still.className = 'face-capture-freeze' + ($scope.cameraFacingMode === 'user' ? ' is-mirrored' : '');
        still.alt = '';
        still.setAttribute('aria-hidden', 'true');
        still.src = dataUrl;
        frame.appendChild(still);
        window.setTimeout(function(){
            if (still.parentNode) still.parentNode.removeChild(still);
        }, 240);
    }

    function captureDisplayedVideoFrame(video, canvas, quality, callback, freezeFrame, needBlob) {
        var fitted = fitFaceCaptureDimensions(video.videoWidth, video.videoHeight);
        canvas.width = fitted.width;
        canvas.height = fitted.height;
        // CSS mirrors only the presentation. Canvas receives unmirrored sensor
        // pixels so backend geometry, landmarks, and embeddings stay canonical.
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        var dataUrl = canvas.toDataURL('image/jpeg', quality || 0.88);
        var context = buildCaptureContext(video, canvas);
        if (freezeFrame) freezeSubmittedFrame(video, dataUrl);
        callback(needBlob === false ? null : dataUrlToBlob(dataUrl), dataUrl, context);
    }

    function grabAttendanceSnapshotDataUrl(video, canvas, callback, freezeFrame) {
        captureDisplayedVideoFrame(video, canvas, 0.90, function(blob, dataUrl, context){
            callback(dataUrl, context);
        }, !!freezeFrame, false);
    }

    function stopFacePlanarWorker(){
        if (faceWalkUpTimer) window.clearTimeout(faceWalkUpTimer);
        faceWalkUpTimer = null;
        faceWalkUpBusy = false;
        Object.keys(facePlanarCallbacks).forEach(function(id){
            var pending = facePlanarCallbacks[id];
            window.clearTimeout(pending.timer);
            pending.callback(null, 'PLANAR_CHECK_CANCELLED');
        });
        facePlanarCallbacks = {};
        facePlanarWorkerReady = false;
        if (facePlanarWorker) facePlanarWorker.terminate();
        facePlanarWorker = null;
    }

    function advanceCandidateEpoch(){
        candidateEpoch++;
        faceReadyAt = 0;
        faceAttendanceSingleFacePresent = false;
        faceAttendanceFaceCount = 0;
        $scope.faceAttendanceBoxStyle = {display:'none'};
    }

    function startFacePlanarWorker(){
        stopFacePlanarWorker();
        if (!window.Worker || !window.createImageBitmap) return;
        try {
            facePlanarWorker = new window.Worker(window.faceLandmarkerWorkerUrl || 'js/faceEnrollmentLandmarker.worker.js', {type: 'module'});
            facePlanarWorker.onmessage = function(event){
                var data = event.data || {};
                if (data.type === 'error') { console.error('[face-attendance] worker error:', data.reason); stopFacePlanarWorker(); return; }
                if (data.type === 'ready') { facePlanarWorkerReady = true; scheduleWalkUpObservation(0); return; }
                if (data.type === 'throttled') {
                    if (data.epoch !== candidateEpoch) return;
                    if (!data.requestId) { faceWalkUpBusy = false; scheduleWalkUpObservation(100); return; }
                    var throttled = facePlanarCallbacks[data.requestId];
                    if (throttled) { delete facePlanarCallbacks[data.requestId]; window.clearTimeout(throttled.timer); throttled.callback({throttled:true}); }
                    return;
                }
                if (data.type !== 'observation' || data.epoch !== candidateEpoch) return;
                if (!data.requestId) {
                    faceWalkUpBusy = false;
                    applyWalkUpObservation(data);
                    scheduleWalkUpObservation(75);
                    return;
                }
                var pending = facePlanarCallbacks[data.requestId];
                if (!pending) return;
                delete facePlanarCallbacks[data.requestId];
                window.clearTimeout(pending.timer);
                pending.callback(data);
            };
            facePlanarWorker.onerror = function(){ console.error('[face-attendance] edge quality worker failed'); stopFacePlanarWorker(); };
            facePlanarWorker.postMessage({type: 'init'});
        } catch (error) { stopFacePlanarWorker(); }
    }

    function checkAttendancePlanarZ(canvas, aspectRatio, callback){
        if (!facePlanarWorker || !facePlanarWorkerReady || !window.createImageBitmap) {
            callback(null);
            return;
        }
        var requestId = String(++facePlanarRequestId);
        var timer = window.setTimeout(function(){
            var pending = facePlanarCallbacks[requestId];
            if (!pending) return;
            delete facePlanarCallbacks[requestId];
            pending.callback(null);
        }, 1500);
        facePlanarCallbacks[requestId] = {callback: callback, timer: timer, epoch: candidateEpoch};
        window.createImageBitmap(canvas).then(function(bitmap){
            if (!facePlanarWorker || !facePlanarCallbacks[requestId]) { bitmap.close(); return; }
            facePlanarWorker.postMessage({type: 'frame', requestId: requestId,
                observedAt: Date.now(), aspectRatio: aspectRatio, epoch: candidateEpoch, bitmap: bitmap}, [bitmap]);
        }).catch(function(){
            var pending = facePlanarCallbacks[requestId];
            if (!pending) return;
            delete facePlanarCallbacks[requestId];
            window.clearTimeout(pending.timer);
            pending.callback(null);
        });
    }

    function scheduleWalkUpObservation(delay){
        if (faceWalkUpTimer || !facePlanarWorker || !facePlanarWorkerReady || !faceScan || faceWalkUpBusy) return;
        faceWalkUpTimer = window.setTimeout(function(){
            faceWalkUpTimer = null;
            if (!facePlanarWorker || !facePlanarWorkerReady || !faceScan || faceWalkUpBusy) return;
            var video = document.getElementById('faceAttendanceVideo');
            if (!video || video.readyState < 2 || !window.createImageBitmap) { scheduleWalkUpObservation(100); return; }
            faceWalkUpBusy = true;
            var aspectRatio = video.videoWidth > 0 && video.videoHeight > 0 ? video.videoHeight / video.videoWidth : null;
            window.createImageBitmap(video).then(function(bitmap){
                if (!facePlanarWorker || !faceScan) { bitmap.close(); faceWalkUpBusy = false; return; }
                facePlanarWorker.postMessage({type:'frame', observedAt:Date.now(), aspectRatio:aspectRatio,
                    epoch:candidateEpoch, bitmap:bitmap}, [bitmap]);
            }).catch(function(){ faceWalkUpBusy = false; scheduleWalkUpObservation(150); });
        }, typeof delay === 'number' ? delay : 75);
    }

    function applyWalkUpObservation(observation){
        if (!faceScan || !observation) return;
        var video = document.getElementById('faceAttendanceVideo');
        if (!video) return;
        var metrics = observation.metrics || {};
        var box = observation.box;
        var yaw = Number(metrics.yaw), pitch = Number(metrics.pitch), roll = Number(metrics.roll);
        var area = box ? Number(box.width) * Number(box.height) : 0;
        var frontal = Number.isFinite(yaw) && Number.isFinite(pitch) && Number.isFinite(roll)
            && Math.abs(yaw) <= 5 && Math.abs(pitch) <= 5 && Math.abs(roll) <= 5;
        var ready = observation.isValid === true && box && area > 0.25 && frontal;
        facePreviewAvailable = true;
        facePreviewObservedAt = Number(observation.observedAt) || Date.now();
        faceAttendanceFaceCount = Array.isArray(observation.faces) ? observation.faces.length : 0;
        if (faceAwaitingNextChild) {
            if (faceAttendanceFaceCount === 0) {
                if (!faceWaitStartTime) faceWaitStartTime = facePreviewObservedAt;
            } else if (!faceWaitSawNoFace) faceWaitStartTime = 0;
        }
        $scope.$evalAsync(function(){
            $scope.faceAttendanceDetectionReady = true;
            $scope.faceAttendanceBoxStyle = box ? mapFaceBox({normalized_box:box}, video) : {display:'none'};
            if (ready) {
                faceAttendanceSingleFacePresent = true;
                if (!faceReadyAt) faceReadyAt = facePreviewObservedAt;
                $scope.faceAttendanceDetectionMessage = Array.isArray(observation.errors) && observation.errors.indexOf('SPOOF') !== -1
                    ? 'Depth signal is low — hold face straight at eye level.' : 'Face ready — capturing automatically.';
                $scope.faceAttendanceDetectionStatusClass = Array.isArray(observation.errors) && observation.errors.indexOf('SPOOF') !== -1
                    ? 'is-warning' : 'is-ready';
                return;
            }
            faceAttendanceSingleFacePresent = false;
            faceReadyAt = 0;
            var errors = Array.isArray(observation.errors) ? observation.errors : [];
            if (errors.indexOf('BLUR') !== -1) $scope.faceAttendanceDetectionMessage = 'Hold still so the camera can focus.';
            else if (errors.indexOf('LIGHTING') !== -1) $scope.faceAttendanceDetectionMessage = 'Move into even lighting.';
            else if (errors.indexOf('SPOOF') !== -1) $scope.faceAttendanceDetectionMessage = 'Show a real face, not a photo or screen.';
            else if (faceAttendanceFaceCount > 1) $scope.faceAttendanceDetectionMessage = 'Keep only one face in view.';
            else if (!box) $scope.faceAttendanceDetectionMessage = 'Move one face into camera view.';
            else if (area <= 0.25) $scope.faceAttendanceDetectionMessage = 'Move closer to the camera.';
            else if (!frontal) $scope.faceAttendanceDetectionMessage = 'Face the camera and keep your head level.';
            else $scope.faceAttendanceDetectionMessage = 'Checking camera quality…';
            $scope.faceAttendanceDetectionStatusClass = 'is-warning';
        });
    }

    function grabSnapshotBlob(video, canvas, callback, freezeFrame) {
        captureDisplayedVideoFrame(video, canvas, 0.88, callback, !!freezeFrame);
    }

    function onboardReferencePortrait(onSuccess){
        if (!enrollmentMember || !enrollmentStream || !captureSessionToken) {
            if (typeof onSuccess === "function") onSuccess(false, "Face setup is not ready");
            return;
        }
        var video = document.getElementById('faceEnrollmentVideo');
        if (!video || video.readyState < 2) {
            if (typeof onSuccess === 'function') onSuccess(false, 'Waiting for camera frame');
            return;
        }
        $scope.faceEnrollmentReferenceBusy = true;
        var requestSequence = captureRequestSequence;
        var cameraGeneration = enrollmentCameraGeneration;
        $scope.faceEnrollmentMessage = "Preparing face enrollment for " + ($scope.faceEnrollmentMemberName || "child") + "…";

        var burstBlobs = [];
        var referencePreviews = [];
        var burstContexts = [];
        var previewUrl = null;
        var burstCanvas = document.createElement('canvas');

        function collectReferenceFrame(idx) {
            grabSnapshotBlob(video, burstCanvas, function(blob, dataUrl, captureContext) {
                if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                if (blob) {
                    burstBlobs.push(blob);
                    referencePreviews.push(dataUrl);
                    burstContexts.push(captureContext);
                    if (!previewUrl && dataUrl) previewUrl = dataUrl;
                }
                if (idx < 2) {
                    referenceCaptureTimer = window.setTimeout(function() {
                        if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                        collectReferenceFrame(idx + 1);
                    }, 120);
                } else {
                    sendReferenceRequest(burstBlobs, previewUrl);
                }
            }, idx === 2);
        }

        function sendReferenceRequest(blobs, portraitDataUrl) {
            if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration || !captureSessionToken || !enrollmentMember) return;
            var form = new FormData();
            form.append('session_id', session_id || session.session_id);
            form.append('capture_session_token', captureSessionToken);
            form.append('branch_id', session.bid);
            form.append('contact_id', enrollmentMember.contact_id);
            form.append('member_id', enrollmentMember.id);
            if (blobs.length > 1) {
                for (var i = 0; i < blobs.length; i++) {
                    form.append('portraits[]', blobs[i], 'reference_' + i + '.jpg');
                }
            } else if (blobs.length === 1) {
                form.append('portrait', blobs[0], 'reference.jpg');
            } else {
                burstCanvas.width = video.videoWidth || 640;
                burstCanvas.height = video.videoHeight || 480;
                burstCanvas.getContext('2d').drawImage(video, 0, 0, burstCanvas.width, burstCanvas.height);
                portraitDataUrl = burstCanvas.toDataURL('image/jpeg', 0.88);
                referencePreviews = [portraitDataUrl];
                form.append('portrait', dataUrlToBlob(portraitDataUrl), 'reference.jpg');
            }
            if (burstContexts.length > 1) {
                form.append('capture_contexts', JSON.stringify(burstContexts));
            } else if (burstContexts.length === 1) {
                form.append('capture_context', JSON.stringify(burstContexts[0]));
            }

            $http({
                method: 'POST',
                url: window.location.origin + '/routes/faceEnrollmentRoute.php',
                params: {action: 'onboardReference'},
                data: form,
                headers: {
                    'Content-Type': undefined,
                    'uid': String(session.user_id),
                    'bid': String(session.bid),
                    'token': String(session.token),
                    'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
                },
                transformRequest: angular.identity
            }).then(function(res){
                if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                var body = res.data || res;
                $scope.faceEnrollmentReferenceBusy = false;
                if (body.state === 'ACTIVE') {
                    var selectedFrameIndex = body.selected_frame_index;
                    if (!Number.isInteger(selectedFrameIndex) || selectedFrameIndex < 0 || selectedFrameIndex >= referencePreviews.length || !referencePreviews[selectedFrameIndex]) {
                        $scope.faceEnrollmentState = 'ERROR';
                        $scope.faceEnrollmentMessage = 'The reference photo could not be verified. Please try again.';
                        if (typeof onSuccess === 'function') onSuccess(false, $scope.faceEnrollmentMessage);
                        return;
                    }
                    resetReferenceMismatch();
                    $scope.faceEnrollmentHasReference = true;
                    $scope.faceEnrollmentReferenceId = body.reference_id || null;
                    $scope.faceEnrollmentReferencePortraitUrl = referencePreviews[selectedFrameIndex];
                    $scope.faceEnrollmentCaptureState = 'CLEAR';
                    $scope.faceEnrollmentMessage = 'Face verified. Position face inside guide and tap Capture.';
                    if (body.possible_duplicate) $scope.faceEnrollmentIdentityWarning = 'Possible similarity with '
                        + (body.possible_duplicate.conflicting_member_name || 'another student')
                        + '. Hold still while the first frontal frames verify it.';
                    if (body.early_drift_warning) $scope.faceEnrollmentMessage = body.identity_drift_unavailable
                        ? 'The previous reference could not be compared. Check the child in person; administrator review will be required.'
                        : 'Reference differs from the previous enrollment. Continue only after checking the child in person; administrator review may be required.';
                    if (typeof onSuccess === 'function') onSuccess(true, null);
                } else {
                    var cleanMsg = formatFaceQualityError(body.message, body.reason_code || body.reason);
                    $scope.faceEnrollmentMessage = cleanMsg;
                    if (typeof onSuccess === 'function') onSuccess(false, cleanMsg);
                }
            }).catch(function(err){
                if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                $scope.faceEnrollmentReferenceBusy = false;
                var rawMsg = (err && err.data && err.data.message) ? err.data.message : 'Reference portrait onboarding failed. Please try again.';
                var cleanMsg = formatFaceQualityError(rawMsg, err && err.data ? (err.data.reason_code || err.data.reason) : '');
                $scope.faceEnrollmentMessage = cleanMsg;
                if (typeof onSuccess === 'function') onSuccess(false, cleanMsg);
            });
        }

        collectReferenceFrame(0);
    }

    function checkCurrentCaptureFrame(video, callback, freezeFrame){
        var requestSequence = captureRequestSequence;
        var cameraGeneration = enrollmentCameraGeneration;
        var capturedAt = Date.now();
        function report(state, message, category){
            if (state === 'UNAVAILABLE') observeReferenceMismatch(null, capturedAt);
            callback(state, message, {capturedAt: capturedAt}, category || state);
        }
        if (!video || video.readyState < 2) {
            report('UNAVAILABLE', 'Waiting for camera frame');
            return;
        }
        syncEnrollmentPreviewFrame(video);
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);

        if (enrollmentModelPreviewAvailable && typeof canvas.toBlob === 'function') {
            captureDisplayedVideoFrame(video, canvas, 0.82, function(blob, dataUrl, captureContext){
                if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                if (!blob) {
                    report('UNAVAILABLE', 'Waiting for frame');
                    return;
                }
                var form = new FormData();
                form.append('session_id', session_id);
                form.append('branch_id', session.bid);
                form.append('capture_session_token', captureSessionToken);
                if (enrollmentMember && enrollmentMember.contact_id) {
                    form.append('contact_id', enrollmentMember.contact_id);
                    form.append('member_id', enrollmentMember.id);
                }
                form.append('frame', blob, 'preview.jpg');
                form.append('capture_context', JSON.stringify(captureContext));
                $http({
                    method: 'POST',
                    url: window.location.origin + '/routes/faceEnrollmentRoute.php',
                    params: {action: 'previewEnrollment'},
                    timeout: 10000,
                    data: form,
                    headers: {
                        'Content-Type': undefined,
                        'uid': String(session.user_id),
                        'bid': String(session.bid),
                        'token': String(session.token),
                        'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
                    },
                    transformRequest: angular.identity
                }).then(function(response){
                    if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                    var body = response.data || response;
                    if (body.state !== 'PREVIEW' || !Array.isArray(body.faces)) {
                        report('UNAVAILABLE', 'Face detection is unavailable — hold still');
                        return;
                    }
                    if ($scope.faceEnrollmentState === 'CAPTURING') {
                        var selected = Number.isInteger(body.selected_face_index) ? body.faces[body.selected_face_index] : null;
                        var quality = selected && selected.quality;
                        $scope.faceEnrollmentDiagnosticSamples.push({
                            pose: $scope.faceEnrollmentPoseProgress,
                            yaw: quality && typeof quality.pose_yaw === 'number' ? quality.pose_yaw : null,
                            pitch: quality && typeof quality.pose_pitch === 'number' ? quality.pose_pitch : null,
                            reference_score: typeof body.reference_score === 'number' ? body.reference_score : null,
                            reference_match_state: String(body.reference_match_state || 'UNCHECKED'),
                            reason_code: String(body.reason_code || ''),
                            step_elapsed_ms: $scope.faceEnrollmentStepStartedAt === null ? null
                                : Math.max(0, capturedAt - $scope.faceEnrollmentStepStartedAt)
                        });
                        if ($scope.faceEnrollmentDiagnosticSamples.length > 100) $scope.faceEnrollmentDiagnosticSamples.shift();
                    }
                    observeReferenceMismatch(body, capturedAt);
                    if (body.capture_state) {
                        $scope.faceEnrollmentCaptureState = body.capture_state;
                    }
                    if (body.possible_duplicate && body.possible_duplicate.conflicting_member_name) {
                        $scope.faceEnrollmentIdentityWarning = 'Possible similarity with ' + body.possible_duplicate.conflicting_member_name + '. Hold still while the first frontal frames verify it.';
                    }
                    if (body.collision) {
                        $scope.faceEnrollmentIssue.visible = true;
                        $scope.faceEnrollmentIssue.reason = 'FACE_ALREADY_ASSIGNED';
                        $scope.faceEnrollmentIssue.fromPreview = true;
                        $scope.faceEnrollmentIssue.conflictingMemberName = body.collision.conflicting_member_name;
                        $scope.faceEnrollmentIssue.conflictingMemberId = body.collision.conflicting_member_id;
                        report('INVALID', 'Face matches ' + body.collision.conflicting_member_name, 'IDENTITY_CONFLICT');
                        return;
                    }
                    if (body.reference_match_state === 'MISMATCH' && $scope.faceEnrollmentHasReference) {
                        if (enrollmentGuidedCapability && $scope.faceEnrollmentState === 'CAPTURING'
                            && $scope.faceEnrollmentRequestedPoseIndex >= 1 && $scope.faceEnrollmentRequestedPoseIndex <= 4) {
                            $scope.faceEnrollmentReferenceConfirmationPending = true;
                            report('INVALID', 'Return straight-on to confirm the reference', 'REFERENCE_CONFIRMATION');
                        } else report('INVALID', 'Look straight ahead like the reference photo.', 'REFERENCE_MISMATCH');
                        return;
                    }
                    if ($scope.faceEnrollmentReferenceConfirmationPending) {
                        if (!isMeasuredCentre(body)) {
                            report('INVALID', 'Return straight-on to confirm the reference', 'REFERENCE_CONFIRMATION');
                            return;
                        }
                        $scope.faceEnrollmentReferenceConfirmationPending = false;
                        resetReferenceMismatch();
                    }
                    var faces = Array.isArray(body.faces) ? body.faces : [];
                    var selectedIndex = Number.isInteger(body.selected_face_index) ? body.selected_face_index : -1;
                    if (body.reason_code === 'MULTIPLE_FACES_IN_GUIDE') {
                        report('INVALID', 'Two faces are competing inside the guide — keep only ' + ($scope.faceEnrollmentMemberName || 'the selected child') + ' in the oval', 'MULTIPLE_FACES');
                        return;
                    }
                    if (faces.length === 0 && ['NO_FACE', 'NO_FACE_IN_GUIDE'].indexOf(body.reason_code) !== -1) {
                        callback('ABSENT', 'Step back into the guide when prompted', {capturedAt: capturedAt});
                        return;
                    }
                    if (selectedIndex < 0 || !faces[selectedIndex]) {
                        report('INVALID', body.reason_code === 'NO_FACE_IN_GUIDE' ? 'Move the selected face into the guide' : 'Face not detected — position face inside guide', body.reason_code === 'NO_FACE_IN_GUIDE' ? 'GUIDE_DRIFT' : 'INVALID');
                        return;
                    }
                    var check = evaluateFacePoseAndBoundary(faces[selectedIndex], video);
                    if (!check.insideGuide) {
                        // Only guide drift receives grace; other quality failures
                        // keep their existing immediate capture block.
                        var guideOnly = check.yawOk && check.tiltOk && check.reasons.every(function(reason){
                            return /^FACE_(TOO_(LOW|HIGH|LEFT|RIGHT|FAR|SMALL|CLOSE|LARGE)|OFF_CENTRE|OUTSIDE_GUIDE|CLIPPED_BY_FRAME)$/.test(reason)
                                || /^LANDMARK_[A-Z_]+_OUTSIDE$/.test(reason);
                        });
                        var category = guideOnly ? 'GUIDE_DRIFT' : 'INVALID';
                        if (check.reasons.indexOf('FACE_TOO_LOW') !== -1) {
                            report('INVALID', 'Face too low — move face up into the guide', category);
                        } else if (check.reasons.indexOf('FACE_TOO_HIGH') !== -1) {
                            report('INVALID', 'Face too high — move face down into the guide', category);
                        } else if (check.reasons.indexOf('FACE_TOO_LEFT') !== -1) {
                            report('INVALID', 'Face too far left — move face right into the guide', category);
                        } else if (check.reasons.indexOf('FACE_TOO_RIGHT') !== -1) {
                            report('INVALID', 'Face too far right — move face left into the guide', category);
                        } else if (check.reasons.indexOf('FACE_TOO_FAR') !== -1 || check.reasons.indexOf('FACE_TOO_SMALL') !== -1) {
                            report('INVALID', 'Face too far — move closer to camera', category);
                        } else if (check.reasons.indexOf('FACE_TOO_CLOSE') !== -1 || check.reasons.indexOf('FACE_TOO_LARGE') !== -1) {
                            report('INVALID', 'Face too close — move back slightly', category);
                        } else {
                            report('INVALID', 'Face moved outside guide — re-center face', category);
                        }
                        return;
                    }
                    if (!check.yawOk) {
                        report('INVALID', enrollmentGuidedCapability &&
                            ($scope.faceEnrollmentRequestedPoseIndex === 1 || $scope.faceEnrollmentRequestedPoseIndex === 2)
                            ? 'Too far—turn back slightly and keep both eyes visible' : 'Turn face directly toward camera');
                        return;
                    }
                    if (!check.tiltOk) {
                        report('INVALID', enrollmentGuidedCapability
                            ? 'Keep your head level; do not tilt it sideways' : 'Keep head level and upright');
                        return;
                    }
                    var accepted = callback('VALID', '', {dataUrl: dataUrl, context: captureContext, capturedAt: capturedAt, quality: faces[selectedIndex].quality});
                    // A recovery observation is checked but not necessarily kept.
                    if (freezeFrame && accepted === true) freezeSubmittedFrame(video, dataUrl);
                }).catch(function(){
                    if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
                    report('UNAVAILABLE', 'Secure detector connection interrupted — pausing capture');
                });
            }, false);
        } else {
            report('UNAVAILABLE', 'Secure detector waiting — hold face inside guide');
        }
    }

    function captureFaceEnrollment(){
        if ($scope.faceEnrollmentState !== 'READY' || $scope.faceEnrollmentCalibrationPending || !enrollmentMember || !enrollmentStream || !captureSessionToken) return;
        if ($scope.faceEnrollmentIssue && $scope.faceEnrollmentIssue.visible) return;
        if (!enrollmentModelPreviewAvailable) {
            $scope.faceEnrollmentMessage = 'Waiting for secure face detection. Keep the selected child inside the guide.';
            return;
        }
        if ($scope.faceEnrollmentCaptureState !== 'CLEAR' && $scope.faceEnrollmentHasReference) return;
        var requestSequence = captureRequestSequence;
        var cameraGeneration = enrollmentCameraGeneration;
        function captureIsCurrent() {
            return requestSequence === captureRequestSequence && cameraGeneration === enrollmentCameraGeneration;
        }
        if (!$scope.faceEnrollmentHasReference) {
            onboardReferencePortrait(function(success, errorMsg){
                if (success) {
                    window.setTimeout(function(){
                        if (captureIsCurrent()) captureFaceEnrollment();
                    }, 400);
                }
            });
            return;
        }
        var video = document.getElementById('faceEnrollmentVideo');
        if (!video) return;
        if (!$scope.faceEnrollmentDetectionReady) {
            $scope.faceEnrollmentMessage = 'Waiting for the face detector. Keep one face inside the guide.';
            return;
        }
        if ((enrollmentFaceDetector || enrollmentModelPreviewAvailable) && !$scope.faceEnrollmentDetected) {
            $scope.faceEnrollmentMessage = 'No face detected. Center one face inside the guide before capturing.';
            return;
        }
        enrollmentFrames = [];
        enrollmentBurstA = [];
        enrollmentBurstB = [];
        enrollmentCaptureContexts = [];
        enrollmentBurstAContexts = [];
        enrollmentBurstBContexts = [];
        adaptiveCandidateTokens = [];
        clearFaceEnrollmentIssue();
        $scope.faceEnrollmentFrameCount = 0;
        $scope.faceEnrollmentCaptured = 0;
        $scope.faceEnrollmentProgressStyle = {width: '0%'};
        $scope.faceEnrollmentState = 'CAPTURING';
        $scope.faceEnrollmentPaused = false;
        $scope.faceEnrollmentPauseReason = '';
        $scope.faceEnrollmentDetectionMessage = 'Capturing face, please hold still…';
        $scope.faceEnrollmentMessage = 'Capturing face, please hold still…';

        if (enrollmentGuidedCapability && enrollmentGuidedCapability.pose_protocol === 'guided-adaptive-v2') {
            if (!enrollmentLocalTracker || !enrollmentLocalTracker.active) {
                $scope.faceEnrollmentState = 'ERROR';
                $scope.faceEnrollmentMessage = 'Local tracking is unavailable. Tap Try Again for secure enrollment.';
                return;
            }
            $scope.faceEnrollmentPoseProtocol = 'guided-adaptive-v2';
            captureAdaptiveEnrollment(requestSequence, cameraGeneration, video);
            return;
        }

        // Recovery belongs to this capture invocation. Existing camera/request
        // generations invalidate it on cancel, switching, or a new student.
        var guided = enrollmentGuidedCapability;
        var burstACount = guided && guided.pose_protocol === 'guided-five-select-v1' ? 10 : 5;
        var totalCount = burstACount + 3;
        $scope.faceEnrollmentPoseProtocol = guided ? guided.pose_protocol : null;
        var centrePitch = null;
        var centreSharpness = -Infinity;
        var poseSince = null;
        var poseLastObservedAt = null;
        var poseNoProgressSince = null;
        if (guided) showGuidedPose(0, false);
        var recovering = false;
        var driftSince = null;
        var validSince = null;
        function checkLegacySecondFrontal(firstSnapshot){
            var startedAt = Date.now();
            function observe(){
                if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
                checkCurrentCaptureFrame(video, function(state, reason, snapshot){
                    if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
                    var fresh = snapshot && snapshot.capturedAt > firstSnapshot.capturedAt
                        && snapshot.dataUrl !== firstSnapshot.dataUrl;
                    if (state === 'VALID' && fresh
                        && guidedPoseMatches(0, snapshot.quality, centrePitch, guided.windows)) {
                        checkEarlyEnrollmentIdentity(requestSequence, cameraGeneration, function(continueCapture){
                            if (continueCapture && captureIsCurrent()) {
                                showGuidedPose(1, false);
                                capture(1);
                            }
                        }, [firstSnapshot.dataUrl, snapshot.dataUrl],
                        [firstSnapshot.context, snapshot.context]);
                        return;
                    }
                    if (Date.now() - startedAt >= 10000) {
                        $scope.faceEnrollmentIdentityWarning = $scope.faceEnrollmentIdentityWarning
                            || 'Early identity check unavailable. Capture will continue; the final check will decide.';
                        showGuidedPose(1, false);
                        capture(1);
                        return;
                    }
                    window.setTimeout(observe, 300);
                });
            }
            window.setTimeout(observe, 300);
        }
        function capture(index){
            if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
            var onCaptureFrame = function(detectionState, pauseReason, snapshot, category){
                if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
                var observedAt = Date.now();
                if (detectionState !== 'VALID') {
                    poseSince = null;
                    poseLastObservedAt = null;
                    recovering = true;
                    validSince = null;
                    if (category === 'GUIDE_DRIFT') {
                        if (driftSince === null) driftSince = observedAt;
                    } else {
                        driftSince = null;
                    }
                    var paused = $scope.faceEnrollmentPaused || category !== 'GUIDE_DRIFT' || observedAt - driftSince >= 1000;
                    var reason = (index >= 5 && detectionState === 'ABSENT') ? 'Return to the oval and look straight ahead.' : (pauseReason || 'Center the face inside the guide');
                    $scope.$evalAsync(function(){
                        if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
                        $scope.faceEnrollmentPaused = paused;
                        $scope.faceEnrollmentPauseReason = paused ? reason : '';
                        $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                        $scope.faceEnrollmentDetectionMessage = reason + (paused ? '. Hold still to resume automatically.' : '. Keeping captured progress.');
                        $scope.faceEnrollmentMessage = paused ? 'Face setup paused. ' + reason + '. Captured progress is kept.' : reason + '. Captured progress is kept.';
                        if (category === 'REFERENCE_MISMATCH' || category === 'REFERENCE_CONFIRMATION') {
                            $scope.faceEnrollmentDetectionMessage = category === 'REFERENCE_CONFIRMATION'
                                ? 'Return straight-on to confirm the reference.' : 'Look straight ahead like the reference photo.';
                            $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                            $scope.faceEnrollmentPauseReason = $scope.faceEnrollmentDetectionMessage;
                        }
                    });
                    window.setTimeout(function(){ capture(index); }, 300);
                    return;
                }
                if (recovering) {
                    // A valid observation breaks continuous drift, but one good
                    // frame alone does not rearm capture. Require a fresh valid
                    // observation at least 300ms later, never a timer-only resume.
                    driftSince = null;
                    if (validSince === null) validSince = observedAt;
                    if (observedAt - validSince < 300) {
                        $scope.$evalAsync(function(){
                            if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'CAPTURING') return;
                            $scope.faceEnrollmentDetectionMessage = 'Face back in the guide. Hold still to resume automatically.';
                            $scope.faceEnrollmentMessage = 'Keeping captured progress. Checking that the face is steady…';
                            if ($scope.faceEnrollmentPaused) $scope.faceEnrollmentPauseReason = 'Hold still to resume automatically';
                        });
                        window.setTimeout(function(){ capture(index); }, 300);
                        return;
                    }
                    recovering = false;
                    validSince = null;
                    // Recovery has finished even if the current guided pose
                    // still needs adjustment. Waiting for a pose is not a pause.
                    $scope.faceEnrollmentPaused = false;
                    $scope.faceEnrollmentPauseReason = '';
                    $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                }

                if (guided) {
                    var poseAt = snapshot.capturedAt;
                    var matches = guidedPoseMatches(index, snapshot.quality, centrePitch, guided.windows);
                    var feedback = guidedPoseFeedback(index, snapshot.quality, centrePitch, guided.windows);
                    var fresh = typeof poseAt === 'number' && Number.isFinite(poseAt)
                        && (poseLastObservedAt === null || poseAt > poseLastObservedAt);
                    if (!matches || !fresh) poseSince = null;
                    else if (poseSince === null) poseSince = poseAt;
                    if (!matches) {
                        if (poseNoProgressSince === null) poseNoProgressSince = poseAt;
                    } else poseNoProgressSince = null;
                    poseLastObservedAt = poseAt;
                    if (!matches || !fresh || poseAt - poseSince < guided.windows.stable_ms) {
                        $scope.$evalAsync(function(){
                            if (captureIsCurrent()) {
                                $scope.faceEnrollmentDetectionStatusClass = matches && fresh ? 'is-ready' : 'is-warning';
                                $scope.faceEnrollmentShowPoseTip = poseNoProgressSince !== null
                                    && poseAt - poseNoProgressSince >= 3000;
                                showGuidedPose(index, matches && fresh,
                                    fresh ? feedback : 'Wait for a fresh camera measurement.');
                            }
                        });
                        window.setTimeout(function(){ capture(index); }, guided.windows.stable_ms);
                        return;
                    }
                    if (index < 2) {
                        var sharpness = Number(snapshot.quality.sharpness);
                        if (index === 0 || Number.isFinite(sharpness) && sharpness > centreSharpness) {
                            centrePitch = snapshot.quality.pose_pitch;
                            centreSharpness = Number.isFinite(sharpness) ? sharpness : -Infinity;
                            $scope.faceEnrollmentCentrePitch = centrePitch;
                        }
                    }
                    poseSince = null;
                    poseLastObservedAt = null;
                    poseNoProgressSince = null;
                }

                // Submit the bytes and geometry that the server just checked.
                var frameData = snapshot.dataUrl;
                var captureContext = snapshot.context;
                enrollmentFrames.push(frameData);
                enrollmentCaptureContexts.push(captureContext);
                if (index < burstACount) {
                    enrollmentBurstA.push(frameData);
                    enrollmentBurstAContexts.push(captureContext);
                } else {
                    enrollmentBurstB.push(frameData);
                    enrollmentBurstBContexts.push(captureContext);
                }
                $scope.$evalAsync(function(){
                    if (!captureIsCurrent()) return;
                    $scope.faceEnrollmentPaused = false;
                    $scope.faceEnrollmentPauseReason = '';
                    $scope.faceEnrollmentDetectionStatusClass = 'is-ready';
                    $scope.faceEnrollmentFrameCount = enrollmentFrames.length;
                    $scope.faceEnrollmentCaptured = enrollmentFrames.length;
                    if (guided && guided.pose_protocol === 'guided-five-select-v1' && index < burstACount)
                        $scope.faceEnrollmentPosePhotoCounts[Math.floor(index / 2)] = index % 2 + 1;
                    $scope.faceEnrollmentProgressStyle = {width: ((enrollmentFrames.length / totalCount) * 100) + '%'};
                    if (index < burstACount - 1) {
                        $scope.faceEnrollmentDetectionMessage = 'Hold still—capturing your face (' + enrollmentFrames.length + '/' + burstACount + ').';
                        $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                    } else if (index === burstACount - 1) {
                        $scope.faceEnrollmentDetectionMessage = 'First set complete. Keep your face in the guide for the final check.';
                        $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                    } else {
                        $scope.faceEnrollmentDetectionMessage = 'Hold still—final check (' + (enrollmentFrames.length - burstACount) + '/3).';
                        $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                    }
                    if (guided && index !== 1 && !(guided.pose_protocol === 'guided-five-v1' && index === 0)
                        && index !== burstACount - 1 && index < totalCount - 1) {
                        $scope.faceEnrollmentShowPoseTip = false;
                        showGuidedPose(index + 1, false);
                    }
                });

                if (index === burstACount - 1) {
                    // Separate the two bursts while the face stays in view.
                    var firstBurstEndedAt = snapshot.capturedAt;
                    $scope.$evalAsync(function(){
                        if (!captureIsCurrent()) return;
                        $scope.faceEnrollmentState = 'LEAVE_GUIDE';
                        $scope.faceEnrollmentDetectionStatusClass = 'is-warning is-leave-guide';
                        $scope.faceEnrollmentDetectionMessage = 'First set complete. Hold still for the final check.';
                        $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                        $scope.faceEnrollmentLeaveHoldPct = 0;
                        $scope.faceEnrollmentLeaveHoldStyle = {width: '0%'};
                    });
                    var steadySince = null;
                    var lastObservedAt = null;
                    var finalPauseStartedAt = Date.now();
                    function checkLeaveGuide(){
                        if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'LEAVE_GUIDE') return;
                        checkCurrentCaptureFrame(video, function(detectionState, message, snapshot){
                            if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'LEAVE_GUIDE') return;
                            var observedAt = snapshot && snapshot.capturedAt;
                            var fresh = typeof observedAt === 'number' && Number.isFinite(observedAt)
                                && (lastObservedAt === null || observedAt > lastObservedAt);
                            var frontal = detectionState === 'VALID' && fresh
                                && (!guided || guidedPoseMatches(burstACount, snapshot.quality, centrePitch, guided.windows));
                            if (!frontal && Date.now() - finalPauseStartedAt >= 10000) {
                                $scope.faceEnrollmentState = 'ERROR';
                                $scope.faceEnrollmentDetectionMessage = detectionState === 'UNAVAILABLE'
                                    ? 'The secure camera check is unavailable.'
                                    : detectionState === 'ABSENT' ? 'No face is visible for the final check.'
                                    : message || 'A straight-on face could not be verified for the final check.';
                                $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage + ' Tap Try Again.';
                                $scope.$evalAsync(function(){});
                                return;
                            }
                            if (fresh) lastObservedAt = observedAt;
                            if (!frontal) steadySince = null;
                            else if (steadySince === null) steadySince = observedAt;
                            var elapsed = frontal ? Math.max(0, observedAt - firstBurstEndedAt) : 0;
                            var holdPct = Math.min(100, Math.round(elapsed / 750 * 100));
                            if (frontal && elapsed >= 750 && observedAt - steadySince >= 300) {
                                // Assign synchronously so a deferred Angular digest cannot abort capture.
                                $scope.faceEnrollmentState = 'CAPTURING';
                                $scope.faceEnrollmentLeaveHoldPct = 100;
                                $scope.faceEnrollmentLeaveHoldStyle = {width: '100%'};
                                $scope.faceEnrollmentDetectionStatusClass = 'is-ready';
                                $scope.faceEnrollmentDetectionMessage = 'Final check starting. Keep looking straight ahead.';
                                $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                                if (guided) showGuidedPose(burstACount, false);
                                $scope.$evalAsync(function(){});
                                capture(burstACount);
                                return;
                            }
                            $scope.$evalAsync(function(){
                                if (!captureIsCurrent() || $scope.faceEnrollmentState !== 'LEAVE_GUIDE') return;
                                $scope.faceEnrollmentLeaveHoldPct = holdPct;
                                $scope.faceEnrollmentLeaveHoldStyle = {width: holdPct + '%'};
                                $scope.faceEnrollmentDetectionStatusClass = 'is-warning is-leave-guide';
                                $scope.faceEnrollmentDetectionMessage = frontal
                                    ? 'First set complete. Hold still for the final check.'
                                    : detectionState === 'UNAVAILABLE' ? 'Waiting for a fresh camera check.'
                                    : detectionState === 'ABSENT' ? 'Keep your face in the guide for the final check.'
                                    : message || 'Look straight ahead and hold still for the final check.';
                                $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                            });
                            window.setTimeout(checkLeaveGuide, 100);
                        });
                    }
                    window.setTimeout(checkLeaveGuide, 100);
                    return;
                }

                if (index < totalCount - 1) {
                    if (guided && guided.pose_protocol === 'guided-five-v1' && index === 0) {
                        checkLegacySecondFrontal(snapshot);
                        return;
                    }
                    if (index === 1 && (!guided || guided.pose_protocol !== 'guided-five-v1')) {
                        checkEarlyEnrollmentIdentity(requestSequence, cameraGeneration, function(continueCapture){
                            if (continueCapture && captureIsCurrent()) {
                                if (guided) showGuidedPose(2, false);
                                window.setTimeout(function(){ capture(index + 1); }, 250);
                            }
                        });
                        return;
                    }
                    window.setTimeout(function(){ capture(index + 1); }, 250);
                    return;
                }
                $scope.$evalAsync(function(){
                    if (!captureIsCurrent()) return;
                    $scope.faceEnrollmentPaused = false;
                    $scope.faceEnrollmentDetectionMessage = "Face captured. Saving automatically…";
                    $scope.faceEnrollmentMessage = "Face captured. Saving automatically…";
                    submitFaceEnrollment();
                });
                return true;
            };
            if (totalCount === 8) {
                checkCurrentCaptureFrame(video, function(state, reason, snapshot, category){
                    return onCaptureFrame(state, reason, snapshot, category);
                }, index === 7);
            } else {
                checkCurrentCaptureFrame(video, onCaptureFrame, index === totalCount - 1);
            }
        }
        capture(0);
    }

    function checkEarlyEnrollmentIdentity(requestSequence, cameraGeneration, done, frames, contexts){
        frames = frames || enrollmentFrames.slice(0, 2);
        contexts = contexts || enrollmentCaptureContexts.slice(0, 2);
        if (frames.length < 2 || contexts.length < 2) { done(true); return; }
        var form = new FormData();
        form.append('session_id', session_id);
        form.append('branch_id', session.bid);
        form.append('contact_id', enrollmentMember.contact_id);
        form.append('capture_session_token', captureSessionToken);
        form.append('capture_contexts', JSON.stringify(contexts));
        frames.forEach(function(frame, index){
            form.append('frames[]', dataUrlToBlob(frame), 'early_centre_' + index + '.jpg');
        });
        $http({method:'POST', url:window.location.origin + '/routes/faceEnrollmentRoute.php',
            params:{action:'checkEarlyEnrollmentIdentity'}, timeout:15000, data:form,
            headers:{'Content-Type':undefined,'uid':String(session.user_id),'bid':String(session.bid),
                'token':String(session.token),'device':typeof get_device === 'function' ? get_device() : navigator.userAgent},
            transformRequest:angular.identity}).then(function(response){
            if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
            var body = response.data || response;
            if (body.state === 'CONFIRMED_DUPLICATE') {
                $scope.faceEnrollmentState = 'READY';
                $scope.faceEnrollmentIssue.visible = true;
                $scope.faceEnrollmentIssue.reason = 'FACE_ALREADY_ASSIGNED';
                $scope.faceEnrollmentIssue.fromPreview = false;
                $scope.faceEnrollmentIssue.conflictingMemberName = body.conflicting_member_name || 'another student';
                $scope.faceEnrollmentIssue.conflictingMemberId = body.conflicting_member_id || null;
                $scope.faceEnrollmentMessage = 'This face is already assigned to ' + $scope.faceEnrollmentIssue.conflictingMemberName + '. Nothing was saved.';
                $scope.faceEnrollmentDetectionMessage = $scope.faceEnrollmentMessage;
                done(false);
                return;
            }
            if (body.state === 'POSSIBLE_SIMILARITY') {
                $scope.faceEnrollmentIdentityWarning = 'Possible similarity with ' + (body.conflicting_member_name || 'another student') + '. Capture will continue; the final check will decide.';
            } else if (body.state !== 'CLEAR') {
                $scope.faceEnrollmentIdentityWarning = $scope.faceEnrollmentIdentityWarning || 'Early identity check unavailable. Capture will continue; the final check will decide.';
            }
            done(true);
        }).catch(function(){
            if (requestSequence !== captureRequestSequence || cameraGeneration !== enrollmentCameraGeneration) return;
            $scope.faceEnrollmentIdentityWarning = $scope.faceEnrollmentIdentityWarning || 'Early identity check unavailable. Capture will continue; the final check will decide.';
            done(true);
        });
    }

    function captureAdaptiveEnrollment(requestSequence, cameraGeneration, video){
        var tracker = enrollmentLocalTracker;
        var canvas = document.createElement('canvas');
        var firstStepAt = Date.now();
        var stepStartedAt = Date.now();
        var inFlight = false;
        var lastReason = '';
        var serverFailures = 0;
        var leftScore = null;
        var rightScore = null;
        function current(){
            return requestSequence === captureRequestSequence && cameraGeneration === enrollmentCameraGeneration
                && enrollmentLocalTracker === tracker && tracker.active;
        }
        function fail(reason){
            if (!current()) return;
            $scope.$evalAsync(function(){
                if (!current()) return;
                $scope.faceEnrollmentState = 'ERROR';
                $scope.faceEnrollmentPaused = false;
                $scope.faceEnrollmentMessage = reason + ' Tap Try Again to restart this capture.';
                $scope.faceEnrollmentDetectionMessage = reason;
            });
        }
        function messageForReason(reason, slot, body){
            if (reason === 'POSE_TARGET_MISMATCH') {
                var yaw = body && body.yaw;
                if (slot === 2 || slot === 3 || slot === 4) {
                    var direction = slot === 2 ? -1 : slot === 3 ? 1 : tracker.extraDirection;
                    if (Number.isFinite(yaw)) {
                        if (direction * yaw < -0.02) return 'Turn the other way, then hold still.';
                        if (direction * yaw < 0.08) return 'Turn a little more, then hold still.';
                        if (direction * yaw > 0.16) return 'Turn back slightly, then hold still.';
                    }
                    return 'Keep your chin level and hold the slight turn.';
                }
                return 'Look straight at the camera and hold still.';
            }
            if (reason === 'REFERENCE_MISMATCH') return slot < 2 || slot >= 5
                ? 'Face does not match the reference. Check the selected child or retake the reference.'
                : 'Turn slightly less so both eyes remain visible.';
            if (reason === 'POSSIBLE_DUPLICATE') return 'This face may already be assigned to another child.';
            if (reason === 'FACE_TOO_FAR' || reason === 'FACE_TOO_SMALL') return 'Move a little closer.';
            if (reason === 'FACE_TOO_CLOSE' || reason === 'FACE_TOO_LARGE') return 'Move a little farther away.';
            if (reason === 'EXPOSURE_LOW' || reason === 'EXPOSURE_HIGH') return 'Adjust the lighting around the face.';
            if (reason === 'POSE_MEASUREMENTS_MISSING') return 'Face direction could not be measured. Hold still in the light.';
            return 'Server check: ' + String(reason || 'QUALITY_REJECTED').replace(/_/g, ' ').toLowerCase() + '.';
        }
        function step(slot){
            if (!current() || $scope.faceEnrollmentState !== 'CAPTURING') return;
            tracker.setTarget(slot);
            var label = slot < 5 ? ['Centre 1 of 2','Centre 2 of 2','Turn left','Turn right','One extra turn'][slot]
                : 'Final check ' + (slot - 4) + ' of 3';
            $scope.$evalAsync(function(){
                // Slots 0–4 are the first evidence burst; slots 5–7 share the
                // final-hold segment. A segment only completes after the server
                // accepts the corresponding candidate below.
                setEnrollmentRingActive(slot >= 5 ? 5 : slot);
                $scope.faceEnrollmentPoseProgress = label;
                $scope.faceEnrollmentDetectionMessage = adaptiveServerCorrection && Date.now() < adaptiveServerCorrection.until
                    ? adaptiveServerCorrection.message : (tracker.latest ? tracker.latest.correction : 'Position one face in the preview.');
            });
            if (Date.now() - stepStartedAt > 10000) { fail(messageForReason(lastReason || 'POSE_TARGET_MISMATCH', slot)); return; }
            if (inFlight || !tracker.readyForCandidate()) {
                window.setTimeout(function(){ step(slot); }, 75);
                return;
            }
            inFlight = true;
            grabSnapshotBlob(video, canvas, function(blob, dataUrl, context){
                if (!current()) return;
                if (!blob) { inFlight = false; lastReason = 'INVALID_FRAME'; window.setTimeout(function(){ step(slot); }, 150); return; }
                var form = new FormData();
                form.append('session_id', session_id);
                form.append('branch_id', session.bid);
                form.append('contact_id', enrollmentMember.contact_id);
                form.append('member_id', enrollmentMember.id);
                form.append('capture_session_token', captureSessionToken);
                form.append('pose_protocol', 'guided-adaptive-v2');
                form.append('slot', String(slot));
                form.append('frame', blob, 'candidate_' + slot + '.jpg');
                form.append('capture_context', JSON.stringify(context));
                var deviceMetadata = enrollmentDeviceMetadata();
                form.append('camera_label', deviceMetadata.camera_label);
                form.append('browser', deviceMetadata.browser);
                form.append('user_agent', deviceMetadata.userAgent);
                form.append('run_id', deviceMetadata.run_id);
                var browserObservation = tracker.latest;
                if (browserObservation) {
                    if (Number.isFinite(browserObservation.yaw)) form.append('browser_yaw', String(browserObservation.yaw));
                    if (Number.isFinite(browserObservation.matrixYaw)) form.append('browser_matrix_yaw', String(browserObservation.matrixYaw));
                    form.append('browser_observation_age_ms', String(Math.max(0, Date.now() - browserObservation.observedAt)));
                    form.append('browser_facing_mode', $scope.cameraFacingMode);
                }
                $http({method:'POST', url:window.location.origin + '/routes/faceEnrollmentRoute.php',
                    params:{action:'checkEnrollmentCandidate'}, timeout:10000, data:form,
                    headers:{'Content-Type':undefined,'uid':String(session.user_id),'bid':String(session.bid),
                        'token':String(session.token),'device':typeof get_device === 'function' ? get_device() : navigator.userAgent},
                    transformRequest:angular.identity}).then(function(response){
                    if (!current()) return;
                    var body = response.data || response;
                    var reason = body.reason_code || body.reason || 'CANDIDATE_REJECTED';
                    if (body.duplicate_band === 'BLOCKED' || body.duplicate_band === 'REVIEW') {
                        $scope.faceEnrollmentIdentityWarning = $scope.faceEnrollmentIdentityWarning
                            || 'Possible similarity with another student. Hold still while the first frontal frames verify it.';
                    }
                    $scope.faceEnrollmentDiagnosticSamples.push({slot:slot, yaw:body.yaw, pitch:body.pitch,
                        run_id:enrollmentRunId,
                        reference_score:body.reference_score, reason_code:reason,
                        total_ms:body.timings_ms && body.timings_ms.total,
                        inference_ms:body.timings_ms && body.timings_ms.inference,
                        index_ms:body.timings_ms && body.timings_ms.index,
                        index_cache_hit:body.index_cache_hit,
                        elapsed_ms:Date.now()-firstStepAt});
                    if ($scope.faceEnrollmentDiagnosticSamples.length > 100) $scope.faceEnrollmentDiagnosticSamples.shift();
                    if (body.state !== 'ACCEPTED' || !body.candidate_token) {
                        lastReason = reason;
                        tracker.resetHold();
                        adaptiveServerCorrection = {message:messageForReason(reason, slot, body), until:Date.now() + 1500};
                        $scope.faceEnrollmentDetectionMessage = adaptiveServerCorrection.message;
                        if (reason === 'POSSIBLE_DUPLICATE' || reason === 'STALE_CAPTURE_SESSION'
                            || reason === 'REFERENCE_MISMATCH' && (slot < 2 || slot >= 5)) {
                            fail(messageForReason(reason, slot)); return;
                        }
                        window.setTimeout(function(){ step(slot); }, 200);
                        return;
                    }
                    lastReason = '';
                    adaptiveServerCorrection = null;
                    adaptiveCandidateTokens[slot] = body.candidate_token;
                    enrollmentFrames.push(dataUrl);
                    enrollmentCaptureContexts.push(context);
                    if (slot < 5) { enrollmentBurstA.push(dataUrl); enrollmentBurstAContexts.push(context); }
                    else { enrollmentBurstB.push(dataUrl); enrollmentBurstBContexts.push(context); }
                    if (slot === 2) leftScore = tracker.steadinessScore();
                    if (slot === 3) rightScore = tracker.steadinessScore();
                    if (slot === 2 && leftScore === null) leftScore = Math.abs(Math.abs(body.yaw || 0) - 0.12);
                    if (slot === 3 && rightScore === null) rightScore = Math.abs(Math.abs(body.yaw || 0) - 0.12);
                    if (slot === 3) tracker.extraDirection = leftScore <= rightScore ? -1 : 1;
                    $scope.faceEnrollmentFrameCount = enrollmentFrames.length;
                    $scope.faceEnrollmentCaptured = enrollmentFrames.length;
                    $scope.faceEnrollmentProgressStyle = {width: ((enrollmentFrames.length / 8) * 100) + '%'};
                    if (slot === 7) completeEnrollmentRingStep(5);
                    stepStartedAt = Date.now();
                    if (slot === 4) { prepareFinalCheck(); return; }
                    if (slot === 7) { submitFaceEnrollment(); return; }
                    if (slot === 1) {
                        checkEarlyEnrollmentIdentity(requestSequence, cameraGeneration, function(continueCapture){
                            if (continueCapture && current()) window.setTimeout(function(){ step(2); }, 60);
                        });
                        return;
                    }
                    window.setTimeout(function(){ step(slot + 1); }, slot === 0 || slot >= 5 ? 110 : 60);
                }).catch(function(){
                    if (!current()) return;
                    serverFailures++;
                    lastReason = 'INFERENCE_UNAVAILABLE';
                    if (serverFailures >= 2) fail('The server candidate check is unavailable.');
                    else window.setTimeout(function(){ step(slot); }, 250);
                }).finally(function(){ inFlight = false; });
            }, slot === 7);
        }
        function prepareFinalCheck(){
            if (!current()) return;
            var firstBurstEndedAt = Date.now();
            $scope.$evalAsync(function(){
                $scope.faceEnrollmentState = 'LEAVE_GUIDE';
                $scope.faceEnrollmentDetectionMessage = 'First set complete. Keep looking straight ahead.';
                $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
                $scope.faceEnrollmentLeaveHoldPct = 0;
                $scope.faceEnrollmentLeaveHoldStyle = {width:'0%'};
            });
            function observe(){
                if (!current() || $scope.faceEnrollmentState !== 'LEAVE_GUIDE') return;
                var elapsed = Date.now() - firstBurstEndedAt;
                var holdPct = Math.min(100, Math.round(elapsed / 750 * 100));
                $scope.$evalAsync(function(){
                    if (!current() || $scope.faceEnrollmentState !== 'LEAVE_GUIDE') return;
                    $scope.faceEnrollmentLeaveHoldPct = holdPct;
                    $scope.faceEnrollmentLeaveHoldStyle = {width:holdPct + '%'};
                });
                if (elapsed >= 750) {
                    // Candidate 5 still needs a fresh 300 ms local hold and a
                    // server check of its exact pixels. The server verifies the
                    // signed time gap from candidate 4 before final encode.
                    $scope.faceEnrollmentState = 'CAPTURING';
                    $scope.faceEnrollmentMessage = 'Final check starting. Keep looking straight ahead.';
                    tracker.resetHold();
                    stepStartedAt = Date.now();
                    step(5);
                    return;
                }
                window.setTimeout(observe, 75);
            }
            window.setTimeout(observe, 75);
        }
        step(0);
    }

    function submitFaceEnrollment(){
        var selection = $scope.faceEnrollmentPoseProtocol === 'guided-five-select-v1';
        var adaptive = $scope.faceEnrollmentPoseProtocol === 'guided-adaptive-v2';
        var burstACount = selection ? 10 : 5;
        if ($scope.faceEnrollmentState !== "CAPTURING" || !enrollmentMember || enrollmentFrames.length < burstACount + 3 || !captureSessionToken) return;
        var requestSequence = captureRequestSequence;
        $scope.faceEnrollmentState = "PROCESSING";
        $scope.faceEnrollmentMessage = "Checking and securely saving the face.";
        var clientAttemptId = "att_" + Date.now() + "_" + Math.random().toString(36).substring(2, 10);
        var form = new FormData();
        form.append("branch_id", session.bid);
        form.append("session_id", session_id);
        form.append("capture_session_token", captureSessionToken);
        form.append("contact_id", enrollmentMember.contact_id);
        if (parseInt(enrollmentMember.id, 10) > 0) form.append("member_id", enrollmentMember.id);
        form.append("client_attempt_id", clientAttemptId);
        form.append("teacher_confirmed_member", "1");
        form.append("notice_version", "program-face-testing-v1");
        form.append("purpose", "testing-branch-face-attendance");
        if ($scope.faceEnrollmentPoseProtocol) form.append('pose_protocol', $scope.faceEnrollmentPoseProtocol);
        if (adaptive) form.append('candidate_tokens', JSON.stringify(adaptiveCandidateTokens));
        if ($scope.faceEnrollmentReferenceId) {
            form.append("reference_id", $scope.faceEnrollmentReferenceId);
        }
        var deviceMeta = enrollmentDeviceMetadata();
        deviceMeta.protocol = $scope.faceEnrollmentPoseProtocol || 'legacy';
        form.append("device_meta", JSON.stringify(deviceMeta));

        var burstA = enrollmentBurstA.length === burstACount ? enrollmentBurstA : enrollmentFrames.slice(0, burstACount);
        var burstB = enrollmentBurstB.length === 3 ? enrollmentBurstB : enrollmentFrames.slice(burstACount, burstACount + 3);

        burstA.forEach(function(frame, index){
            form.append('burst_a[]', dataUrlToBlob(frame), 'burst_a_' + index + '.jpg');
        });
        burstB.forEach(function(frame, index){
            form.append('burst_b[]', dataUrlToBlob(frame), 'burst_b_' + index + '.jpg');
        });
        if (!selection && !adaptive) enrollmentFrames.forEach(function(frame, index){
            form.append('frames[]', dataUrlToBlob(frame), 'mapping-' + index + '.jpg');
        });
        form.append('capture_contexts_a', JSON.stringify(enrollmentBurstAContexts));
        form.append('capture_contexts_b', JSON.stringify(enrollmentBurstBContexts));
        if (!selection && !adaptive) form.append('capture_contexts', JSON.stringify(enrollmentCaptureContexts));
        $http({
            method: 'POST',
            url: window.location.origin + '/routes/faceEnrollmentRoute.php',
            params: {action: 'enrollFace'},
            data: form,
            headers: {
                'Content-Type': undefined,
                'uid': String(session.user_id),
                'bid': String(session.bid),
                'token': String(session.token),
                'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
            },
            transformRequest: angular.identity
            }).then(function(response){
            if (requestSequence !== captureRequestSequence) return;
            var body = response.data || response;
            if (body.state === 'ACTIVE' || body.state === 'ALREADY_ENROLLED') {
                $scope.faceEnrollmentIdentityWarning = '';
                if ($scope.faceEnrollmentPoseProtocol === 'guided-adaptive-v2' && enrollmentSetupStartedAt !== null) {
                    window.faceEnrollmentLastSetupTiming = {elapsed_ms:Date.now() - enrollmentSetupStartedAt,
                        reference_was_present:enrollmentReferencePresentAtStart,
                        first_pass:enrollmentRetryCount === 0,
                        protocol:'guided-adaptive-v2'};
                }
                if (enrollmentMember) enrollmentMember.face_mapping_active = true;
                var quality = body.quality_summary || {};
                var score = typeof quality.verification_score === "number" ? quality.verification_score.toFixed(4) : "not returned";
                $scope.faceEnrollmentVerificationSummary = (quality.frames_accepted || 0) + "/" + (quality.frames_received || 0) + " frames accepted; face count " + (quality.face_count || 0) + "; verification score " + score + "; model version " + (body.model_version_id || "unknown");
                $scope.faceEnrollmentState = "COMPLETE";
                $scope.faceEnrollmentMessage = "Face ready for " + enrollmentMember.fullname + ". Choose what to do next.";
                stopEnrollmentCamera();
            } else if (body.state === 'PENDING_ADMIN_REVIEW') {
                $scope.faceEnrollmentState = 'COMPLETE';
                $scope.faceEnrollmentMessage = 'New face capture awaits administrator review. The previous enrollment remains active.';
                stopEnrollmentCamera();
            } else {
                if (body.reason === 'FACE_ALREADY_ASSIGNED' || body.reason === 'DUPLICATE_BLOCKED') {
                    $scope.faceEnrollmentState = 'READY';
                    $scope.faceEnrollmentFrameCount = 0;
                    $scope.faceEnrollmentCaptured = 0;
                    $scope.faceEnrollmentProgressStyle = {width: '0%'};
                    $scope.faceEnrollmentVerificationSummary = '';
                    $scope.faceEnrollmentIssue.visible = true;
                    $scope.faceEnrollmentIssue.reason = 'FACE_ALREADY_ASSIGNED';
                    $scope.faceEnrollmentIssue.fromPreview = false;
                    $scope.faceEnrollmentIssue.conflictingMemberName = body.conflicting_member_name || '';
                    $scope.faceEnrollmentIssue.conflictingMemberId = body.conflicting_member_id || null;
                    var conflictText = body.conflicting_member_name ? (' (' + body.conflicting_member_name + ')') : '';
                    $scope.faceEnrollmentMessage = 'This face is already assigned to another child' + conflictText + '. Nothing was saved. Bring the selected child into view, or cancel and choose the correct student.';
                } else if (body.reason === 'VERIFICATION_FAILED' && body.message === 'VERIFY_THRESHOLD_NOT_MET') {
                    $scope.faceEnrollmentCalibrationPending = true;
                    $scope.faceEnrollmentState = 'READY';
                    $scope.faceEnrollmentFrameCount = 0;
                    $scope.faceEnrollmentCaptured = 0;
                    $scope.faceEnrollmentProgressStyle = {width: '0%'};
                    $scope.faceEnrollmentMessage = 'Face setup is not available yet. No face was saved; ask an administrator to enable it, then try again.';
                } else if (body.reason === 'ENROLLMENT_QUALITY_REJECTED' || (body.message && body.message.indexOf('BURST_B_QUALITY_FAILED') !== -1)) {
                    $scope.faceEnrollmentState = 'READY';
                    $scope.faceEnrollmentFrameCount = 0;
                    $scope.faceEnrollmentCaptured = 0;
                    $scope.faceEnrollmentProgressStyle = {width: '0%'};
                    $scope.faceEnrollmentMessage = (body.message && body.message.indexOf('BURST_B_QUALITY_FAILED') !== -1)
                        ? 'The final check was unclear. Return to the oval, hold steady, and try again.'
                        : 'Could not acquire clear frontal face frames. Please keep face directly inside the guide, look at the camera, and try again.';
                } else {
                    $scope.faceEnrollmentState = 'ERROR';
                    $scope.faceEnrollmentMessage = body.message || 'Face setup failed. Nothing was saved; try again.';
                }
            }
        }).catch(function(){
            if (requestSequence !== captureRequestSequence) return;
            $scope.faceEnrollmentState = 'ERROR';
            $scope.faceEnrollmentMessage = 'Face setup failed. Nothing was saved; try again.';
        }).finally(function(){
            if (requestSequence !== captureRequestSequence) return;
            enrollmentFrames = [];
            enrollmentBurstA = [];
            enrollmentBurstB = [];
            enrollmentCaptureContexts = [];
            enrollmentBurstAContexts = [];
            enrollmentBurstBContexts = [];
        });
    }

    function dataUrlToBlob(dataUrl){
        var parts = dataUrl.split(",");
        var binary = window.atob(parts[1] || "");
        var bytes = new Uint8Array(binary.length);
        for (var index = 0; index < binary.length; index++) bytes[index] = binary.charCodeAt(index);
        return new Blob([bytes], {type: "image/jpeg"});
    }
    function continueFaceSetup(){
        if ($scope.faceEnrollmentState !== "COMPLETE") return;
        cancelFaceEnrollment();
        $scope.faceSetupMode = true;
    }

    function startAttendanceAfterEnrollment(){
        if ($scope.faceEnrollmentState !== "COMPLETE") return;
        cancelFaceEnrollment();
        $scope.faceSetupMode = false;
        startFacePunchIn();
    }

    function cancelFaceEnrollment(){
        resetReferenceMismatch();
        captureRequestSequence++;
        captureSessionToken = null;
        if (referenceCaptureTimer) { window.clearTimeout(referenceCaptureTimer); referenceCaptureTimer = null; }
        stopEnrollmentCamera();
        enrollmentFrames = [];
        enrollmentBurstA = [];
        enrollmentBurstB = [];
        enrollmentCaptureContexts = [];
        enrollmentBurstAContexts = [];
        enrollmentBurstBContexts = [];
        enrollmentMember = null;
        clearFaceEnrollmentIssue();
        $scope.faceEnrollmentMemberName = '';
        $scope.faceEnrollmentCalibrationPending = false;
        $scope.faceEnrollmentPaused = false;
        $scope.faceEnrollmentPauseReason = '';
        $scope.faceEnrollmentCaptureState = 'UNAVAILABLE';
        $scope.faceEnrollmentHasReference = false;
        $scope.faceEnrollmentReferenceId = null;
        $scope.faceEnrollmentReferencePortraitUrl = null;
        $scope.faceEnrollmentReferenceBusy = false;
        resetEnrollmentDetectionUi();
        $scope.faceEnrollmentState = 'IDLE';
        $scope.faceEnrollmentMessage = '';
    }

    function retryFaceEnrollment(){
        if (enrollmentMember) {
            enrollmentRetrying = true;
            $scope.faceEnrollmentState = 'IDLE';
            $scope.faceEnrollmentMessage = '';
            $scope.faceEnrollmentIssue = {visible: false, reason: ''};
            $scope.faceEnrollmentReferenceBusy = false;
            $scope.faceEnrollmentPaused = false;
            $scope.faceEnrollmentFrameCount = 0;
            $scope.faceEnrollmentCaptured = 0;
            $scope.faceEnrollmentProgressStyle = {width: '0%'};
            startFaceEnrollment(enrollmentMember, enrollmentMember.face_mapping_active ? 'REENROLLMENT' : 'ENROLLMENT');
        }
    }

    function recalibrateFace(row){
        if (!$scope.faceEnrollmentUiEnabled || !row || row.type !== 'M') return;
        startFaceEnrollment(row, 'REENROLLMENT');
    }

    function loadFaceAdminReviews(){
        if (parseInt(session.position_id, 10) !== 1) return;
        $http({
            method: 'POST', url: window.location.origin + '/routes/faceEnrollmentRoute.php',
            params: {action: 'listReenrollmentReviews'},
            data: {session_id: session_id || session.session_id, branch_id: session.bid},
            headers: {'uid': String(session.user_id), 'bid': String(session.bid), 'token': String(session.token)}
        }).then(function(res){
            var body = res.data || res;
            $scope.faceAdminReviews = body.state === 'READY' ? body.reviews : [];
            $scope.faceAdminReviewMessage = body.state === 'READY' ? '' : (body.message || 'Could not load pending reviews.');
        }).catch(function(){ $scope.faceAdminReviewMessage = 'Could not load pending reviews.'; });
    }

    function submitFaceAdminReview(review, decision){
        if (parseInt(session.position_id, 10) !== 1 || !review || !review.in_person_verified || !(review.reason || '').trim()) return;
        review.busy = true;
        $http({
            method: 'POST', url: window.location.origin + '/routes/faceEnrollmentRoute.php',
            params: {action: 'reviewReenrollmentAttempt'},
            data: {session_id: session_id || session.session_id, branch_id: session.bid,
                attempt_id: review.attempt_id, decision: decision, in_person_verified: true, reason: review.reason.trim()},
            headers: {'uid': String(session.user_id), 'bid': String(session.bid), 'token': String(session.token)}
        }).then(function(res){
            var body = res.data || res;
            $scope.faceAdminReviewMessage = body.state === decision ? 'Review saved.' : (body.message || 'Could not save review.');
            if (body.state === decision) loadFaceAdminReviews();
        }).catch(function(){ $scope.faceAdminReviewMessage = 'Could not save review.'; })
          .finally(function(){ review.busy = false; });
    }

    function clearFaceReference(){
        if (!enrollmentMember || !captureSessionToken) return;
        var member = enrollmentMember;
        var token = captureSessionToken;
        var referenceId = $scope.faceEnrollmentReferenceId;
        captureRequestSequence++;
        var requestSequence = captureRequestSequence;
        captureSessionToken = null;
        if (referenceCaptureTimer) { window.clearTimeout(referenceCaptureTimer); referenceCaptureTimer = null; }
        stopEnrollmentCamera();
        enrollmentFrames = [];
        enrollmentBurstA = [];
        enrollmentBurstB = [];
        enrollmentCaptureContexts = [];
        enrollmentBurstAContexts = [];
        enrollmentBurstBContexts = [];
        resetReferenceMismatch();
        $scope.faceEnrollmentIdentityWarning = '';
        $scope.faceEnrollmentFrameCount = 0;
        $scope.faceEnrollmentCaptured = 0;
        $scope.faceEnrollmentProgressStyle = {width: '0%'};
        $scope.faceEnrollmentHasReference = false;
        $scope.faceEnrollmentReferenceId = null;
        $scope.faceEnrollmentReferencePortraitUrl = null;
        clearFaceEnrollmentIssue();
        $scope.faceEnrollmentMessage = 'Clearing the pending reference. Captured progress has been reset.';
        $scope.faceEnrollmentState = 'REFERENCE_REQUIRED';
        $scope.faceEnrollmentReferenceBusy = true;
        $http({
            method: 'POST',
            url: window.location.origin + '/routes/faceEnrollmentRoute.php',
            params: {action: 'clearReference'},
            data: {
                session_id: session_id || session.session_id,
                branch_id: session.bid,
                contact_id: member.contact_id,
                member_id: member.id || member.member_id || 0,
                reference_id: referenceId,
                capture_session_token: token
            },
            headers: {
                'Content-Type': 'application/json',
                'uid': String(session.user_id),
                'bid': String(session.bid),
                'token': String(session.token),
                'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
            }
        }).then(function(res){
            if (requestSequence !== captureRequestSequence) return;
            var body = res.data || res;
            if (body.state !== 'CLEARED') throw new Error(body.message || 'Could not clear reference');
            cancelFaceEnrollment();
            startFaceEnrollment(member, member.face_mapping_active ? 'REENROLLMENT' : 'ENROLLMENT');
        }).catch(function(err){
            if (requestSequence !== captureRequestSequence) return;
            $scope.faceEnrollmentReferenceBusy = false;
            $scope.faceEnrollmentState = 'ERROR';
            $scope.faceEnrollmentMessage = err.message || 'Could not clear reference. Please try again.';
        });
    }

    function dismissFaceEnrollmentIssue(){
        $scope.faceEnrollmentIssue.visible = false;
    }

    function clearFaceEnrollmentIssue(){
        $scope.faceEnrollmentIssue.visible = false;
        $scope.faceEnrollmentIssue.reason = '';
        $scope.faceEnrollmentIssue.fromPreview = false;
        $scope.faceEnrollmentIssue.conflictingMemberName = '';
        $scope.faceEnrollmentIssue.conflictingMemberId = null;
    }

    function resetEnrollmentDetectionUi(){
        $scope.faceEnrollmentPaused = false;
        $scope.faceEnrollmentPauseReason = '';
        $scope.faceEnrollmentDetected = false;
        $scope.faceEnrollmentDetectionReady = false;
        $scope.faceEnrollmentDetectorEnforced = false;
        $scope.faceEnrollmentDetectionMessage = 'Position only the selected child inside the face guide.';
        $scope.faceEnrollmentDetectionStatusClass = 'is-waiting';
        $scope.faceEnrollmentBoxStyle = {display: 'none'};
        $scope.faceEnrollmentPosePrompt = '';
        $scope.faceEnrollmentFrameCount = 0;
        $scope.faceEnrollmentCaptured = 0;
        $scope.faceEnrollmentProgressStyle = {width: '0%'};
        $scope.faceEnrollmentLeaveHoldPct = 0;
        $scope.faceEnrollmentLeaveHoldStyle = {width: '0%'};
    }

    function startEnrollmentDetection(){
        stopEnrollmentDetection();
        adaptiveTrackerFailed = false;
        enrollmentModelPreviewAvailable = false;
        $scope.faceEnrollmentDetectorEnforced = false;
        $scope.faceEnrollmentDetectionMessage = 'Starting camera quality checks...';
        $scope.faceEnrollmentDetectionStatusClass = 'is-waiting';
        startAdaptiveTracker();
        pollEnrollmentModelPreview();
    }

    function pollEnrollmentModelPreview(){
        if (!enrollmentStream || $scope.faceEnrollmentState === 'IDLE' || $scope.faceEnrollmentState === 'COMPLETE' || $scope.faceEnrollmentState === 'ERROR') return;
        // A local tracker supplies immediate UI feedback, but the first preview
        // remains necessary to negotiate the server-authorized capture protocol.
        if (enrollmentLocalTracker && enrollmentLocalTracker.active && enrollmentGuidedCapability) return;
        if ($scope.faceEnrollmentReferenceBusy || $scope.faceEnrollmentState === 'CAPTURING' || $scope.faceEnrollmentState === 'LEAVE_GUIDE' || $scope.faceEnrollmentState === 'PROCESSING') {
            enrollmentModelPreviewTimer = window.setTimeout(pollEnrollmentModelPreview, 500);
            return;
        }
        var video = document.getElementById('faceEnrollmentVideo');
        if (!video || video.readyState < 2 || enrollmentModelPreviewBusy) {
            enrollmentModelPreviewTimer = window.setTimeout(pollEnrollmentModelPreview, 300);
            return;
        }
        syncEnrollmentPreviewFrame(video);
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        if (typeof canvas.toBlob !== 'function') {
            useBrowserEnrollmentDetection();
            return;
        }
        enrollmentModelPreviewBusy = true;
        var capturedAt = Date.now();
        var requestSequence = captureRequestSequence;
        var generation = enrollmentPreviewGeneration;
        captureDisplayedVideoFrame(video, canvas, 0.72, function(blob, dataUrl, captureContext){
            if (requestSequence !== captureRequestSequence || generation !== enrollmentPreviewGeneration) return;
            if (!blob) {
                enrollmentModelPreviewBusy = false;
                useBrowserEnrollmentDetection();
                return;
            }
            var form = new FormData();
            form.append('session_id', session_id);
            form.append('branch_id', session.bid);
            form.append('capture_session_token', captureSessionToken);
            if (enrollmentMember && enrollmentMember.contact_id) {
                form.append('contact_id', enrollmentMember.contact_id);
                form.append('member_id', enrollmentMember.id);
            }
            form.append('frame', blob, 'preview.jpg');
            form.append('capture_context', JSON.stringify(captureContext));
            if (!enrollmentRunLogged) {
                var runDevice = enrollmentDeviceMetadata();
                form.append('run_started', '1');
                form.append('run_id', runDevice.run_id);
                form.append('camera_label', runDevice.camera_label);
                form.append('browser', runDevice.browser);
                form.append('user_agent', runDevice.userAgent);
                enrollmentRunLogged = true;
            }
            $http({
                method: 'POST',
                url: window.location.origin + '/routes/faceEnrollmentRoute.php',
                params: {action: 'previewEnrollment'},
                data: form,
                headers: {
                    'Content-Type': undefined,
                    'uid': String(session.user_id),
                    'bid': String(session.bid),
                    'token': String(session.token),
                    'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
                },
                transformRequest: angular.identity
            }).then(function(response){
                if (requestSequence !== captureRequestSequence || generation !== enrollmentPreviewGeneration) return;
                var body = response.data || response;
                if (body.state !== 'PREVIEW') throw new Error('MODEL_PREVIEW_UNAVAILABLE');
                enrollmentModelPreviewAvailable = true;
                $scope.faceEnrollmentDiagnosticSamples.push({phase:'live_preview',
                    run_id:enrollmentRunId,
                    detection_ms:body.timings_ms && body.timings_ms.detection,
                    identity_ms:body.timings_ms && body.timings_ms.identity,
                    total_ms:body.timings_ms && body.timings_ms.total,
                    step_elapsed_ms:null,
                    reason_code:body.reason_code || ''});
                if ($scope.faceEnrollmentDiagnosticSamples.length > 100) $scope.faceEnrollmentDiagnosticSamples.shift();
                $scope.faceEnrollmentDetectorEnforced = true;
                $scope.$evalAsync(function(){
                    if (generation === enrollmentPreviewGeneration && $scope.faceEnrollmentState === 'READY') {
                        enrollmentGuidedCapability = readGuidedEnrollmentCapability(body);
                        if (enrollmentGuidedCapability && enrollmentGuidedCapability.pose_protocol === 'guided-adaptive-v2') {
                            $scope.faceEnrollmentPoseProtocol = 'guided-adaptive-v2';
                            startAdaptiveTracker();
                        }
                        observeReferenceMismatch(body, capturedAt);
                        applyEnrollmentModelDetection(body, video);
                    }
                });
            }).catch(function(){
                if (requestSequence !== captureRequestSequence || generation !== enrollmentPreviewGeneration) return;
                if ($scope.faceEnrollmentState !== 'READY') return;
                observeReferenceMismatch(null, capturedAt);
                if (!enrollmentModelPreviewAvailable) useBrowserEnrollmentDetection();
                else {
                    $scope.faceEnrollmentDetectionMessage = 'Model detection is temporarily unavailable; keep one face inside the guide.';
                    $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                }
            }).finally(function(){
                if (requestSequence !== captureRequestSequence || generation !== enrollmentPreviewGeneration) return;
                enrollmentModelPreviewBusy = false;
                if (!enrollmentLocalTracker || !enrollmentLocalTracker.active || !enrollmentGuidedCapability)
                    enrollmentModelPreviewTimer = window.setTimeout(pollEnrollmentModelPreview, 500);
            });
        }, false);
    }

    function evaluateFacePoseAndBoundary(face, video){
        var result = {
            insideGuide: false,
            facingCamera: false,
            yawOk: false,
            tiltOk: false,
            allOk: false,
            reasons: []
        };
        if (!face || !video) return result;

        if (face.quality && typeof face.quality === 'object') {
            result.insideGuide = !!face.quality.inside_guide;
            result.yawOk = face.quality.yaw_ok !== undefined ? !!face.quality.yaw_ok : true;
            result.tiltOk = face.quality.tilt_ok !== undefined ? !!face.quality.tilt_ok : true;
            result.facingCamera = !!face.quality.facing_camera || (result.yawOk && result.tiltOk);
            result.allOk = result.insideGuide && result.yawOk && result.tiltOk;
            if (Array.isArray(face.quality.reasons)) {
                result.reasons = face.quality.reasons.slice();
            }
            return result;
        }

        var intrinsicWidth = Number(video.videoWidth) || 640;
        var intrinsicHeight = Number(video.videoHeight) || 480;
        var rawBox = face.boundingBox || face.box || face;
        var x = Number(rawBox.x) || 0;
        var y = Number(rawBox.y) || 0;
        var w = Number(rawBox.width) || 0;
        var h = Number(rawBox.height) || 0;

        var u = x / intrinsicWidth;
        var v = y / intrinsicHeight;
        var uw = w / intrinsicWidth;
        var vh = h / intrinsicHeight;

        var tooSmall = uw < 0.20 || vh < 0.25;
        var tooLarge = uw > 0.55 || vh > 0.65;
        var sizeOk = !tooSmall && !tooLarge;
        if (tooSmall) result.reasons.push('FACE_TOO_SMALL');
        else if (tooLarge) result.reasons.push('FACE_TOO_CLOSE');

        var clipped = u < 0.03 || (u + uw) > 0.97 || v < 0.03 || (v + vh) > 0.97;
        if (clipped) result.reasons.push('FACE_CLIPPED_BY_FRAME');

        var normCx = (x + (w / 2.0)) / intrinsicWidth;
        var normCy = (y + (h / 2.0)) / intrinsicHeight;
        var offsetX = normCx - 0.50;
        var offsetY = normCy - 0.46;
        var centredOk = Math.abs(offsetX) <= 0.11 && Math.abs(offsetY) <= 0.13;
        if (!centredOk) {
            result.reasons.push('FACE_OFF_CENTRE');
            if (offsetY > 0.13) result.reasons.push('FACE_TOO_LOW');
            else if (offsetY < -0.13) result.reasons.push('FACE_TOO_HIGH');
            if (offsetX < -0.11) result.reasons.push('FACE_TOO_LEFT');
            else if (offsetX > 0.11) result.reasons.push('FACE_TOO_RIGHT');
        }

        function ellipseDist(pu, pv) {
            var du = (pu - 0.50) / 0.30;
            var dv = (pv - 0.46) / 0.36;
            return Math.sqrt(du * du + dv * dv);
        }

        var perimeterOk = (
            ellipseDist(normCx, v) <= 1.05 &&
            ellipseDist(normCx, v + vh) <= 1.05 &&
            ellipseDist(u, normCy) <= 1.05 &&
            ellipseDist(u + uw, normCy) <= 1.05
        );

        var lm = face.landmarks || null;
        var leftEye = null;
        var rightEye = null;
        var nose = null;

        if (lm && lm.right_eye && lm.left_eye && lm.nose) {
            rightEye = lm.right_eye;
            leftEye = lm.left_eye;
            nose = lm.nose;
        } else if (Array.isArray(face.landmarks)) {
            face.landmarks.forEach(function(item){
                if (item.type === 'eye') {
                    if (!leftEye) leftEye = [item.locations[0].x, item.locations[0].y];
                    else rightEye = [item.locations[0].x, item.locations[0].y];
                } else if (item.type === 'nose') {
                    nose = [item.locations[0].x, item.locations[0].y];
                }
            });
        }

        var landmarksInside = true;
        var eyeLevelOk = true;
        if (leftEye && rightEye) {
            if (ellipseDist(leftEye[0] / intrinsicWidth, leftEye[1] / intrinsicHeight) > 0.88) landmarksInside = false;
            if (ellipseDist(rightEye[0] / intrinsicWidth, rightEye[1] / intrinsicHeight) > 0.88) landmarksInside = false;
            var eyeV = ((rightEye[1] + leftEye[1]) / 2.0) / intrinsicHeight;
            eyeLevelOk = eyeV >= 0.24 && eyeV <= 0.52;
            if (!eyeLevelOk && result.reasons.indexOf('FACE_OFF_CENTRE') === -1) {
                result.reasons.push('FACE_OFF_CENTRE');
                if (eyeV > 0.52 && result.reasons.indexOf('FACE_TOO_LOW') === -1) result.reasons.push('FACE_TOO_LOW');
                else if (eyeV < 0.24 && result.reasons.indexOf('FACE_TOO_HIGH') === -1) result.reasons.push('FACE_TOO_HIGH');
            }
        }
        if (nose) {
            if (ellipseDist(nose[0] / intrinsicWidth, nose[1] / intrinsicHeight) > 0.88) landmarksInside = false;
        }

        result.insideGuide = centredOk && perimeterOk && landmarksInside && sizeOk && !clipped && eyeLevelOk;
        if (!result.insideGuide && result.reasons.indexOf('FACE_OUTSIDE_GUIDE') === -1) {
            result.reasons.push('FACE_OUTSIDE_GUIDE');
        }

        if (leftEye && rightEye && nose) {
            var eyeDx = leftEye[0] - rightEye[0];
            var eyeDy = leftEye[1] - rightEye[1];
            var eyeDistance = Math.hypot(eyeDx, eyeDy);
            var tiltDegrees = eyeDistance > 0 ? Math.abs(Math.atan2(eyeDy, eyeDx) * (180 / Math.PI)) : 90.0;
            var eyeMidX = (rightEye[0] + leftEye[0]) / 2.0;
            var poseYaw = (nose[0] - eyeMidX) / Math.max(1.0, eyeDistance);

            result.yawOk = Math.abs(poseYaw) <= 0.22;
            result.tiltOk = tiltDegrees <= 20.0;
            if (!result.yawOk) result.reasons.push('TURN_FACE_TO_CAMERA');
            if (!result.tiltOk) result.reasons.push('FACE_TILTED');
        } else {
            result.yawOk = true;
            result.tiltOk = true;
        }

        result.facingCamera = result.yawOk && result.tiltOk;
        result.allOk = result.insideGuide && result.facingCamera;
        return result;
    }

    function syncFaceGuideToFrame(frame, video){
        if (!frame) return;
        var guide = frame.querySelector('.face-camera-guide');
        var frameWidth = frame.clientWidth || frame.offsetWidth || 0;
        var frameHeight = frame.clientHeight || frame.offsetHeight || 0;
        if (!guide || frameWidth < 1 || frameHeight < 1) return;

        video = video || frame.querySelector('video');
        var content = video ? videoContentRect(video) : {x: 0, y: 0, width: frameWidth, height: frameHeight};

        // Preserve a 3:4 guide and clamp the oval plus label to the actual
        // object-fit:contain video content, never the surrounding letterbox.
        var labelSpace = Math.min(40, Math.max(16, content.height * 0.10));
        var availableHeight = Math.max(48, Math.min(content.height * 0.74, content.height - labelSpace - 8));
        var guideWidth = Math.min(content.width * 0.66, availableHeight * 0.75);
        var guideHeight = guideWidth / 0.75;
        var guideLeft = Math.max(content.x + 4, content.x + (content.width - guideWidth) / 2);
        var preferredTop = content.y + (content.height * 0.46) - (guideHeight / 2);
        var guideTop = Math.max(content.y + 4, Math.min(content.y + content.height - guideHeight - labelSpace, preferredTop));

        guide.style.left = guideLeft + 'px';
        guide.style.top = guideTop + 'px';
        guide.style.width = guideWidth + 'px';
        guide.style.height = guideHeight + 'px';
        guide.style.transform = 'none';

        if (window.ResizeObserver && !frame._faceGuideResizeObserver) {
            frame._faceGuideResizeObserver = new window.ResizeObserver(function(){
                syncFaceGuideToFrame(frame, video);
            });
            frame._faceGuideResizeObserver.observe(frame);
        }
    }

    function syncEnrollmentPreviewFrame(video){
        var frame = document.getElementById('faceEnrollmentFrame');
        if (!frame || !video || !video.videoWidth || !video.videoHeight) return;
        frame.style.aspectRatio = video.videoWidth + ' / ' + video.videoHeight;
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'contain';
        syncFaceGuideToFrame(frame, video);
    }

    function mapEnrollmentFaceBox(face, video){
        var intrinsicWidth = video.videoWidth || 1;
        var intrinsicHeight = video.videoHeight || 1;
        var renderedWidth = video.clientWidth || video.offsetWidth || intrinsicWidth;
        var renderedHeight = video.clientHeight || video.offsetHeight || intrinsicHeight;
        var normalized = face.normalized_box || null;
        var x = normalized ? Number(normalized.x) * intrinsicWidth : (Number(face.x) || 0);
        var y = normalized ? Number(normalized.y) * intrinsicHeight : (Number(face.y) || 0);
        var width = normalized ? Number(normalized.width) * intrinsicWidth : (Number(face.width) || 0);
        var height = normalized ? Number(normalized.height) * intrinsicHeight : (Number(face.height) || 0);
        x = Math.max(0, Math.min(intrinsicWidth, x));
        y = Math.max(0, Math.min(intrinsicHeight, y));
        var right = Math.max(x, Math.min(intrinsicWidth, x + width));
        var bottom = Math.max(y, Math.min(intrinsicHeight, y + height));
        var isMirrored = ($scope.cameraFacingMode === 'user');
        var renderX = isMirrored ? (intrinsicWidth - right) : x;
        return {
            display: 'block',
            left: ((renderX / intrinsicWidth) * renderedWidth) + 'px',
            top: ((y / intrinsicHeight) * renderedHeight) + 'px',
            width: (((right - x) / intrinsicWidth) * renderedWidth) + 'px',
            height: (((bottom - y) / intrinsicHeight) * renderedHeight) + 'px'
        };
    }

    function applyEnrollmentModelDetection(body, video){
        if ($scope.faceEnrollmentState !== 'READY') return;
        var faces = Array.isArray(body.faces) ? body.faces : [];
        var selectedIndex = Number.isInteger(body.selected_face_index) ? body.selected_face_index : -1;
        var face = selectedIndex >= 0 ? faces[selectedIndex] : null;
        $scope.faceEnrollmentDetectionReady = true;
        $scope.faceEnrollmentDetectorEnforced = true;

        if (body.capture_state) {
            $scope.faceEnrollmentCaptureState = body.capture_state;
        }

        if (body.possible_duplicate && body.possible_duplicate.conflicting_member_name) {
            $scope.faceEnrollmentIdentityWarning = 'Possible similarity with ' + body.possible_duplicate.conflicting_member_name + '. Capture will verify it with two frontal frames before the pose sequence.';
        }

        if (body.reference_match_state === 'MISMATCH' && $scope.faceEnrollmentHasReference) {
            $scope.faceEnrollmentDetectionMessage = 'Look straight ahead like the reference photo.';
            $scope.faceEnrollmentMessage = $scope.faceEnrollmentDetectionMessage;
            $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
            $scope.faceEnrollmentDetected = false;
            $scope.faceEnrollmentPosePrompt = 'Reference mismatch';
            $scope.faceEnrollmentBoxStyle = {display: 'none'};
            $scope.faceEnrollmentCaptureState = 'CONFLICT';
            return;
        }

        if (body.collision) {
            $scope.faceEnrollmentIssue.visible = true;
            $scope.faceEnrollmentIssue.reason = 'FACE_ALREADY_ASSIGNED';
            $scope.faceEnrollmentIssue.fromPreview = true;
            $scope.faceEnrollmentIssue.conflictingMemberName = body.collision.conflicting_member_name;
            $scope.faceEnrollmentIssue.conflictingMemberId = body.collision.conflicting_member_id;
            $scope.faceEnrollmentDetectionMessage = 'Face matches ' + body.collision.conflicting_member_name + ' — bring ' + ($scope.faceEnrollmentMemberName || 'the child') + ' to the camera.';
            $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
            $scope.faceEnrollmentDetected = false;
            $scope.faceEnrollmentPosePrompt = 'Face already registered';
            $scope.faceEnrollmentMessage = 'This face is already registered for ' + body.collision.conflicting_member_name + '. Please bring ' + ($scope.faceEnrollmentMemberName || 'the selected child') + ' to the camera.';
            $scope.faceEnrollmentBoxStyle = {display: 'none'};
            return;
        }

        if ($scope.faceEnrollmentIssue && $scope.faceEnrollmentIssue.visible && $scope.faceEnrollmentIssue.fromPreview) {
            clearFaceEnrollmentIssue();
            $scope.faceEnrollmentMessage = 'Face is clear for ' + ($scope.faceEnrollmentMemberName || 'child') + '. Position face in guide.';
        }

        if (body.reason_code === 'MULTIPLE_FACES_IN_GUIDE') {
            $scope.faceEnrollmentDetected = false;
            $scope.faceEnrollmentDetectionMessage = 'Two faces are inside the guide — keep only the selected child in the oval.';
            $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
            $scope.faceEnrollmentPosePrompt = 'Show only one child';
            $scope.faceEnrollmentBoxStyle = {display: 'none'};
        } else if (!face) {
            $scope.faceEnrollmentDetected = false;
            $scope.faceEnrollmentDetectionMessage = body.reason_code === 'NO_FACE_IN_GUIDE'
                ? 'Face detected outside the guide — move the selected child into the oval.'
                : 'Model detected no face — move one face inside the guide.';
            $scope.faceEnrollmentDetectionStatusClass = 'is-waiting';
            $scope.faceEnrollmentPosePrompt = 'Position face inside the white frame';
            $scope.faceEnrollmentBoxStyle = {display: 'none'};
        } else {
            var check = evaluateFacePoseAndBoundary(face, video);
            if (!check.insideGuide) {
                $scope.faceEnrollmentDetected = false;
                if (check.reasons.indexOf('FACE_TOO_LOW') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too low — move face up into the guide.';
                    $scope.faceEnrollmentPosePrompt = 'Move face up';
                } else if (check.reasons.indexOf('FACE_TOO_HIGH') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too high — move face down into the guide.';
                    $scope.faceEnrollmentPosePrompt = 'Move face down';
                } else if (check.reasons.indexOf('FACE_TOO_LEFT') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too far left — move face right into the guide.';
                    $scope.faceEnrollmentPosePrompt = 'Move face right';
                } else if (check.reasons.indexOf('FACE_TOO_RIGHT') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too far right — move face left into the guide.';
                    $scope.faceEnrollmentPosePrompt = 'Move face left';
                } else if (check.reasons.indexOf('FACE_TOO_SMALL') !== -1 || check.reasons.indexOf('FACE_TOO_FAR') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too far away — move closer to the camera.';
                    $scope.faceEnrollmentPosePrompt = 'Move closer';
                } else if (check.reasons.indexOf('FACE_TOO_CLOSE') !== -1 || check.reasons.indexOf('FACE_TOO_LARGE') !== -1) {
                    $scope.faceEnrollmentDetectionMessage = 'Face is too close — move slightly back.';
                    $scope.faceEnrollmentPosePrompt = 'Move back';
                } else {
                    $scope.faceEnrollmentDetectionMessage = 'Center face inside the white frame.';
                    $scope.faceEnrollmentPosePrompt = 'Position face inside white frame';
                }
                $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                $scope.faceEnrollmentBoxStyle = {display: 'none'};
            } else if (!check.yawOk) {
                $scope.faceEnrollmentDetected = false;
                $scope.faceEnrollmentDetectionMessage = 'Turn face directly toward camera.';
                $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                $scope.faceEnrollmentPosePrompt = 'Face camera directly';
                $scope.faceEnrollmentBoxStyle = {display: 'none'};
            } else if (!check.tiltOk) {
                $scope.faceEnrollmentDetected = false;
                $scope.faceEnrollmentDetectionMessage = 'Keep head level and upright.';
                $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                $scope.faceEnrollmentPosePrompt = 'Keep head level';
                $scope.faceEnrollmentBoxStyle = {display: 'none'};
            } else {
                $scope.faceEnrollmentDetected = true;
                $scope.faceEnrollmentDetectionMessage = "Face ready — hold still and tap Capture.";
                $scope.faceEnrollmentDetectionStatusClass = 'is-ready';
                $scope.faceEnrollmentPosePrompt = '';
                if ($scope.faceEnrollmentMessage && ($scope.faceEnrollmentMessage.indexOf('REFERENCE_QUALITY_FAILED') !== -1 || $scope.faceEnrollmentMessage.indexOf('failed') !== -1 || $scope.faceEnrollmentMessage.indexOf('rejected') !== -1 || $scope.faceEnrollmentMessage.indexOf('guide') !== -1)) {
                    $scope.faceEnrollmentMessage = 'Face positioned correctly. Tap Capture to proceed.';
                }
                syncEnrollmentPreviewFrame(video);
                $scope.faceEnrollmentBoxStyle = {display: 'none'};
            }
        }
    }

    function useBrowserEnrollmentDetection(){
        if ($scope.faceEnrollmentState !== 'READY') return;
        if (typeof window.FaceDetector !== 'function') {
            $scope.faceEnrollmentDetectionReady = true;
            $scope.faceEnrollmentDetectorEnforced = false;
            $scope.faceEnrollmentDetectionMessage = 'Model preview unavailable; face guide active and final detection will be checked securely by the server.';
            $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
            return;
        }
        try {
            enrollmentFaceDetector = new window.FaceDetector({fastMode: true, maxDetectedFaces: 2});
            $scope.faceEnrollmentDetectorEnforced = true;
            detectEnrollmentFace();
        } catch (error) {
            enrollmentFaceDetector = null;
            $scope.faceEnrollmentDetectionReady = true;
            $scope.faceEnrollmentDetectorEnforced = false;
            $scope.faceEnrollmentDetectionMessage = 'Model preview unavailable; face guide active and final detection will be checked securely by the server.';
            $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
        }
    }

    function detectEnrollmentFace(){
        if (!enrollmentFaceDetector || !enrollmentStream || $scope.faceEnrollmentState !== 'READY') return;
        var video = document.getElementById('faceEnrollmentVideo');
        if (!video || video.readyState < 2 || enrollmentDetectionBusy) {
            enrollmentDetectionTimer = window.setTimeout(detectEnrollmentFace, 250);
            return;
        }
        var generation = enrollmentPreviewGeneration;
        enrollmentDetectionBusy = true;
        enrollmentFaceDetector.detect(video).then(function(faces){
            if (generation !== enrollmentPreviewGeneration || $scope.faceEnrollmentState !== 'READY') return;
            // Browser FaceDetector is guidance-only. Final target selection is
            // always made by the backend from the submitted FaceGuide context.
            var face = faces && faces.length ? faces[0] : null;
            $scope.$evalAsync(function(){
                if (generation !== enrollmentPreviewGeneration || $scope.faceEnrollmentState !== 'READY') return;
                if (!face) {
                    $scope.faceEnrollmentDetected = false;
                    $scope.faceEnrollmentDetectionMessage = 'No face detected — move one face inside the guide.';
                    $scope.faceEnrollmentDetectionStatusClass = 'is-waiting';
                    $scope.faceEnrollmentPosePrompt = 'Position face inside the white frame';
                    $scope.faceEnrollmentBoxStyle = {display: 'none'};
                } else {
                    var check = evaluateFacePoseAndBoundary(face, video);
                    if (!check.insideGuide) {
                        $scope.faceEnrollmentDetected = false;
                        if (check.reasons.indexOf('FACE_TOO_LOW') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too low — move face up into the guide.';
                            $scope.faceEnrollmentPosePrompt = 'Move face up';
                        } else if (check.reasons.indexOf('FACE_TOO_HIGH') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too high — move face down into the guide.';
                            $scope.faceEnrollmentPosePrompt = 'Move face down';
                        } else if (check.reasons.indexOf('FACE_TOO_LEFT') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too far left — move face right into the guide.';
                            $scope.faceEnrollmentPosePrompt = 'Move face right';
                        } else if (check.reasons.indexOf('FACE_TOO_RIGHT') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too far right — move face left into the guide.';
                            $scope.faceEnrollmentPosePrompt = 'Move face left';
                        } else if (check.reasons.indexOf('FACE_TOO_SMALL') !== -1 || check.reasons.indexOf('FACE_TOO_FAR') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too far away — move closer to the camera.';
                            $scope.faceEnrollmentPosePrompt = 'Move closer';
                        } else if (check.reasons.indexOf('FACE_TOO_CLOSE') !== -1 || check.reasons.indexOf('FACE_TOO_LARGE') !== -1) {
                            $scope.faceEnrollmentDetectionMessage = 'Face is too close — move slightly back.';
                            $scope.faceEnrollmentPosePrompt = 'Move back';
                        } else {
                            $scope.faceEnrollmentDetectionMessage = 'Center face inside the white frame.';
                            $scope.faceEnrollmentPosePrompt = 'Position face inside white frame';
                        }
                        $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                        $scope.faceEnrollmentBoxStyle = {display: 'none'};
                    } else if (!check.yawOk) {
                        $scope.faceEnrollmentDetected = false;
                        $scope.faceEnrollmentDetectionMessage = 'Turn face directly toward camera.';
                        $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                        $scope.faceEnrollmentPosePrompt = 'Face camera directly';
                        $scope.faceEnrollmentBoxStyle = {display: 'none'};
                    } else if (!check.tiltOk) {
                        $scope.faceEnrollmentDetected = false;
                        $scope.faceEnrollmentDetectionMessage = 'Keep head level and upright.';
                        $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                        $scope.faceEnrollmentPosePrompt = 'Keep head level';
                        $scope.faceEnrollmentBoxStyle = {display: 'none'};
                    } else {
                        $scope.faceEnrollmentDetected = true;
                        $scope.faceEnrollmentDetectionMessage = "Face ready — hold still and tap Capture.";
                        $scope.faceEnrollmentDetectionStatusClass = 'is-ready';
                        $scope.faceEnrollmentPosePrompt = '';
                        syncEnrollmentPreviewFrame(video);
                        $scope.faceEnrollmentBoxStyle = {display: 'none'};
                    }
                }
            });
        }).catch(function(){
            $scope.$evalAsync(function(){
                if (generation !== enrollmentPreviewGeneration || $scope.faceEnrollmentState !== 'READY') return;
                $scope.faceEnrollmentDetected = false;
                $scope.faceEnrollmentDetectorEnforced = false;
                $scope.faceEnrollmentDetectionMessage = 'Face detection is unavailable; keep one face inside the guide.';
                $scope.faceEnrollmentDetectionStatusClass = 'is-warning';
                $scope.faceEnrollmentBoxStyle = {display: 'none'};
            });
        }).finally(function(){
            if (generation !== enrollmentPreviewGeneration || $scope.faceEnrollmentState !== 'READY') return;
            enrollmentDetectionBusy = false;
            enrollmentDetectionTimer = window.setTimeout(detectEnrollmentFace, 250);
        });
    }

    function stopEnrollmentDetection(){
        stopAdaptiveTracker();
        enrollmentPreviewGeneration++;
        if (enrollmentDetectionTimer) {
            window.clearTimeout(enrollmentDetectionTimer);
            enrollmentDetectionTimer = null;
        }
        enrollmentFaceDetector = null;
        enrollmentDetectionBusy = false;
        if (enrollmentModelPreviewTimer) {
            window.clearTimeout(enrollmentModelPreviewTimer);
            enrollmentModelPreviewTimer = null;
        }
        enrollmentModelPreviewBusy = false;
        enrollmentModelPreviewAvailable = false;
        $scope.faceEnrollmentDetected = false;
        $scope.faceEnrollmentDetectorEnforced = false;
        $scope.faceEnrollmentDetectionReady = false;
        $scope.faceEnrollmentDetectionMessage = 'Face setup camera stopped.';
        $scope.faceEnrollmentDetectionStatusClass = 'is-stopped';
        $scope.faceEnrollmentBoxStyle = {display: 'none'};
    }

    function startFaceScan(eventType, label){
        if (!memberRows().length) {
            $scope.faceMessage = 'No members found for this class.';
            show_snack('No members found for this class.');
            return;
        }
        if (!faceMachine || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            $scope.faceMessage = 'Camera is unavailable on this device.';
            return;
        }
        if (eventType === 'PUNCH_OUT' && !$scope.facePunchOutUiEnabled) {
            $scope.faceMessage = 'Departure scanning is not enabled.';
            return;
        }
        if ($scope.faceEnrollmentState !== 'IDLE') {
            $scope.faceMessage = 'Finish or cancel face setup before starting attendance scanning.';
            return;
        }
        if (['READY','REVIEW'].indexOf($scope.attendanceStage) === -1) return;
        if (!scanRosterSnapshot) snapshotRoster();
        faceFinishRequested = false;
        activeFaceEventType = eventType;
        activeFaceEventLabel = label;
        $scope.faceRetryAvailable = false;
        faceRetryUsed = false;
        $scope.attendanceStage = 'SCANNING';
        $scope.faceSetupMode = false;
        $scope.reviewWithoutScan = false;
        clearFaceStartRetry();
        stopFaceAttemptLoop();
        $scope.faceMatched = [];
        $scope.faceLiveWriteMode = false;
        faceScannedMemberIds = {};
        faceScanSelections = {};
        faceAwaitingNextChild = false;
        faceWaitSawNoFace = false;
        faceWaitStartTime = 0;
        faceAttendanceSingleFacePresent = false;
        faceAttendanceFaceCount = 0;
        faceReadyAt = 0;
        facePreviewLatencyMs = 0;
        $scope.faceScanTimingSamples = [];
        window.faceCameraTimings = $scope.faceScanTimingSamples;
        faceScan = null;
        stopFaceLeaseHeartbeat();
        advanceCandidateEpoch();
        $scope.faceLeaseExpired = false;
        try { faceMachine.restart(); }
        catch (error) { $scope.attendanceStage = 'REVIEW'; $scope.faceMessage = 'The scan could not start. Try again.'; return; }
        startFacePlanarWorker();
        var cameraGeneration = ++faceCameraGeneration;
        var readinessRetries = 0;
        function retryReadiness(retryAfter){
            if (readinessRetries >= 3) { stopFailedFaceScan(); return; }
            var delay = Math.max([1000, 2000, 4000][readinessRetries++], Math.min(10000, Number(retryAfter) || 0));
            $scope.faceMessage = 'Face scanning is starting. Checking the connection…';
            faceStartRetryTimer = window.setTimeout(function(){
                faceStartRetryTimer = null;
                if (cameraGeneration === faceCameraGeneration && faceMachine.state === 'STARTING_CAMERA') checkReadiness();
            }, delay);
        }
        function checkReadiness(){
            apiCall.callEndpoint({session_id: session_id, branch_id: session.bid}, 'v1/face-attendance.php', 'getFaceReadiness', json_header).then(function(readinessResponse){
                var readiness = readinessResponse.data || readinessResponse;
                if (cameraGeneration !== faceCameraGeneration || !faceMachine || faceMachine.state !== 'STARTING_CAMERA') return;
                if (readiness.state !== 'READY') {
                    if (readiness.retryable === true) { retryReadiness(readiness.retry_after_ms); return; }
                    stopFailedFaceScan();
                    return;
                }
                navigator.mediaDevices.getUserMedia(getCameraConstraints($scope.cameraFacingMode)).then(function(stream){
                if (cameraGeneration !== faceCameraGeneration || !faceMachine || faceMachine.state !== 'STARTING_CAMERA') {
                    stream.getTracks().forEach(function(track){ track.stop(); });
                    return;
                }
                faceStream = stream;
                stream.getTracks().forEach(function(track){
                    track.addEventListener('ended', function(){
                        if (cameraGeneration === faceCameraGeneration && faceScan) $scope.$evalAsync(stopFailedFaceScan);
                    });
                });
                var video = document.getElementById('faceAttendanceVideo');
                if (video) { video.srcObject = stream; video.play(); }
                faceMachine.cameraReady();
                requestFaceScanStart(eventType, label);
            }).catch(function(){
                if (cameraGeneration !== faceCameraGeneration || !faceMachine || faceMachine.state !== 'STARTING_CAMERA') return;
                stopFailedFaceScan();
                $scope.$evalAsync(function(){
                    $scope.faceMessage = 'Camera access is unavailable. Continue with the attendance list.';
                });
                });
            }).catch(function(){
                if (cameraGeneration === faceCameraGeneration && faceMachine && faceMachine.state === 'STARTING_CAMERA') retryReadiness();
            });
        }
        checkReadiness();
    }
    function requestFaceScanStart(eventType, label){
        if (!faceStream || !faceMachine || faceMachine.state !== 'SCANNING') return;
        var cameraGeneration = faceCameraGeneration;
        var requestedStream = faceStream;
        var selectedTime = $scope.batch.time;
        var selectedTimeEntry = $scope.batch.times && $scope.batch.times[$scope.timeIndex] ? $scope.batch.times[$scope.timeIndex] : null;
        if (selectedTimeEntry && selectedTimeEntry.start_time) {
            selectedTime = selectedTimeEntry.start_time;
        }
        var attendanceSessionId = $scope.batch.session_id;
        if ((attendanceSessionId === undefined || attendanceSessionId === null || attendanceSessionId === '')
            && selectedTimeEntry && selectedTimeEntry.session_id !== undefined && selectedTimeEntry.session_id !== null) {
            attendanceSessionId = selectedTimeEntry.session_id;
        }
        var request = {
            session_id: session_id,
            branch_id: session.bid,
            batch_id: $scope.batch.id,
            attendance_date: moment($scope.batch.date, 'DD-MM-YYYY').format('YYYY-MM-DD'),
            attendance_session_id: attendanceSessionId,
            selected_time: selectedTime,
            event_type: eventType,
            check_month: typeof check_month !== 'undefined' ? check_month : true
        };
        apiCall.callEndpoint(request, 'v1/face-attendance.php', 'startFaceScan', json_header).then(function(response){
            if (cameraGeneration !== faceCameraGeneration || requestedStream !== faceStream) return;
            var body = response.data || response;
            if (!faceMachine || faceMachine.state !== 'SCANNING' || !faceStream) return;
            if (body.state !== 'STARTED') {
                if (body.retryable === true) { scheduleFaceStartRetry(eventType, label, body.retry_after_ms); return; }
                $scope.faceMessage = 'Face scanning is temporarily unavailable. Continue with the attendance list.';
                stopFailedFaceScan();
                return;
            }
            clearFaceStartRetry();
            faceScan = body;
            $scope.faceLiveWriteMode = body.live_write === true;
            startFaceLeaseHeartbeat(body.expires_in);
            resetFaceDetectionUi();
            startFaceDetection();
            $scope.$evalAsync(function(){ $scope.faceMessage = 'Camera ready for ' + label + '. Scanning continuously; position one child inside the guide.'; });
            scheduleFaceAttempt(700);
        }).catch(function(){
            if (cameraGeneration !== faceCameraGeneration || requestedStream !== faceStream) return;
            if (faceMachine && faceMachine.state === 'SCANNING' && faceStream) {
                stopFailedFaceScan();
            } else if (faceMachine) {
                faceMachine.error('SCAN_START_FAILED');
            }
        });
    }

    function scheduleFaceStartRetry(eventType, label, retryAfter){
        if (faceStartRetryTimer || !faceStream || !faceMachine || faceMachine.state !== 'SCANNING') return;
        if (faceStartRetryCount >= 3) { stopFailedFaceScan(true); return; }
        var delay = Math.max([1000, 2000, 4000][faceStartRetryCount++], Math.min(10000, Number(retryAfter) || 0));
        var generation = faceStartRetryGeneration;
        faceStartRetryTimer = window.setTimeout(function(){
            faceStartRetryTimer = null;
            if (generation !== faceStartRetryGeneration || !faceStream || !faceMachine || faceMachine.state !== 'SCANNING') return;
            requestFaceScanStart(eventType, label);
        }, delay);
    }

    function clearFaceStartRetry(){
        faceStartRetryCount = 0;
        if (faceStartRetryTimer) {
            window.clearTimeout(faceStartRetryTimer);
            faceStartRetryTimer = null;
        }
        faceStartRetryGeneration++;
    }

    function scheduleFaceAttempt(delay){
        if (faceRecovering || faceAttemptTimer || faceAwaitingNextChild || !faceMachine || !faceMachine.canCapture() || !faceScan) return;
        var generation = faceAttemptGeneration;
        var scheduledDelay = typeof delay === 'number' ? delay : 700;
        if (scheduledDelay === 350 && faceWaitSawNoFace && facePreviewAvailable && faceAttendanceSingleFacePresent) {
            scheduledDelay = 0;
        }
        faceAttemptTimer = window.setTimeout(function(){
            faceAttemptTimer = null;
            if (generation !== faceAttemptGeneration || !faceMachine || !faceMachine.canCapture() || !faceScan) return;
            captureFaceAttempt();
        }, scheduledDelay);
    }

    var faceWaitStartTime = 0;
    function stopFaceAttemptLoop(){
        if (faceRecoveryTimer) window.clearTimeout(faceRecoveryTimer);
        faceRecoveryTimer = null;
        faceRecovering = false;
        faceRecoveryCount = 0;
        if (faceAttemptTimer) {
            window.clearTimeout(faceAttemptTimer);
            faceAttemptTimer = null;
        }
        if (faceNextChildTimer) {
            window.clearTimeout(faceNextChildTimer);
            faceNextChildTimer = null;
        }
        faceAwaitingNextChild = false;
        faceWaitSawNoFace = false;
        faceWaitStartTime = 0;
        faceAttemptGeneration++;
    }

    function stopFaceLeaseHeartbeat(){
        if (faceLeaseHeartbeatTimer) window.clearInterval(faceLeaseHeartbeatTimer);
        faceLeaseHeartbeatTimer = null;
        faceLeaseRenewAt = 0;
        faceLeaseRenewing = false;
    }

    function useManualFaceAttendance(){
        if (!$scope.faceManualFallbackAvailable) return;
        $scope.faceManualFallbackAvailable = false;
        $scope.reviewWithoutScan = true;
        $scope.attendanceStage = 'REVIEW';
        $scope.faceMessage = 'Face scanning is unavailable. Use the attendance list to continue manually.';
    }

    function expireFaceLease(message){
        var scan = faceScan;
        stopFaceLeaseHeartbeat();
        stopFacePlanarWorker();
        stopFaceCamera();
        if (scan) apiCall.callEndpoint({session_id: session_id, scan_id: scan.scan_id, scan_token: scan.scan_token}, 'v1/face-attendance.php', 'cancelFaceScan', json_header);
        if (faceMachine && ['IDLE','COMPLETED','CANCELLED'].indexOf(faceMachine.state) === -1) faceMachine.cancel();
        faceScan = null;
        faceChallenge = null;
        $scope.faceChallengeActive = false;
        $scope.faceLeaseExpired = true;
        $scope.faceManualFallbackAvailable = true;
        // Keep the error panel visible long enough for the teacher to choose
        // the explicit manual route; the camera/worker are already stopped.
        $scope.attendanceStage = 'SCANNING';
        $scope.faceMessage = message || 'Scan lease expired — switch to manual attendance.';
        $scope.$evalAsync();
    }

    function renewFaceLease(){
        if (!faceScan || faceLeaseRenewing || $scope.attendanceStage !== 'SCANNING') return;
        var busy = faceNonceBusy || !faceMachine || ['CAPTURING','PROCESSING','RECONCILING'].indexOf(faceMachine.state) !== -1;
        if (busy) return;
        faceLeaseRenewing = true;
        var scanId = faceScan.scan_id, scanToken = faceScan.scan_token;
        apiCall.callEndpoint({session_id: session_id, scan_id: scanId, scan_token: scanToken},
            'v1/face-attendance.php', 'renewFaceScan', json_header).then(function(response){
            var body = response.data || response;
            if (!faceScan || faceScan.scan_id !== scanId || body.state !== 'RENEWED' || !body.capture_nonce) {
                expireFaceLease((body && body.message) || 'Scan lease expired — switch to manual attendance.');
                return;
            }
            faceScan.capture_nonce = body.capture_nonce;
            faceLeaseRenewAt = Date.now() + 210000;
            $scope.faceMessage = 'Camera ready. Scan lease renewed.';
        }).catch(function(){
            expireFaceLease('Scan lease expired — switch to manual attendance.');
        }).finally(function(){ faceLeaseRenewing = false; });
    }

    function startFaceLeaseHeartbeat(expiresIn){
        stopFaceLeaseHeartbeat();
        var ttlMs = Math.max(60000, Number(expiresIn || 300) * 1000);
        // Renew at 70% of the advertised lease; retry on the next tick if a
        // burst is active, while retaining the server expiry as the authority.
        faceLeaseRenewAt = Date.now() + Math.min(210000, Math.floor(ttlMs * 0.70));
        faceLeaseHeartbeatTimer = window.setInterval(function(){
            if (!faceScan || $scope.attendanceStage !== 'SCANNING') { stopFaceLeaseHeartbeat(); return; }
            if (!faceLeaseRenewing && Date.now() >= faceLeaseRenewAt) renewFaceLease();
        }, 1000);
    }

    function waitForNextFace(){
        if (faceNextChildTimer || !faceAwaitingNextChild || !faceStream || !faceMachine || faceMachine.state !== 'SCANNING') return;
        var generation = faceAttemptGeneration;
        faceNextChildTimer = window.setTimeout(function(){
            faceNextChildTimer = null;
            if (generation !== faceAttemptGeneration || !faceAwaitingNextChild || !faceStream || !faceMachine || faceMachine.state !== 'SCANNING') return;

            // The preview detector is the hand-off gate: the recognized child
            // must leave the guide before another attempt can be submitted.
            if (!facePreviewAvailable) {
                faceWaitSawNoFace = false;
                faceWaitStartTime = 0;
                // Without the detector we cannot prove that the previously
                // processed child left the guide. Do not repeatedly submit
                // their face to recognition: that creates duplicate attempts
                // and can trigger the server-side rate limit. Preview polling
                // continues independently and this gate resumes as soon as
                // the live model responds again.
                waitForNextFace();
                return;
            }
            if (!faceWaitSawNoFace) {
                // Require repeated empty observations and a recent sample;
                // one cached empty frame or a stalled detector cannot rearm.
                if (faceAttendanceFaceCount === 0 && faceWaitStartTime
                    && facePreviewObservedAt > faceWaitStartTime
                    && Date.now() - facePreviewObservedAt <= 650
                    && Date.now() - faceWaitStartTime >= 750) faceWaitSawNoFace = true;
                waitForNextFace();
                return;
            }
            if (faceAttendanceSingleFacePresent) {
                faceAwaitingNextChild = false;
                faceWaitStartTime = 0;
                faceRetryUsed = false;
                // Departure and a fresh single-face preview already established
                // readiness. Avoid an extra fixed wait before nonce acquisition.
                scheduleFaceAttempt(350);
                faceWaitSawNoFace = false;
                return;
            }
            waitForNextFace();
        }, 250);
    }

    function useManualFaceFallback(){
        if (!faceChallenge || !faceMachine || faceMachine.state !== 'CHALLENGE') return;
        faceChallenge = null;
        $scope.faceChallengeActive = false;
        $scope.faceChallengePrompt = '';
        faceMachine.manualFallback();
        faceMachine.resume();
        $scope.faceMessage = 'Child left Absent. Move them out of view and continue with the next child.';
        faceAwaitingNextChild = true;
        faceWaitSawNoFace = false;
        faceWaitStartTime = 0;
        waitForNextFace();
    }
    function startFacePunchIn(){
        startFaceScan('PUNCH_IN', 'punch-in');
    }

    function startFacePunchOut(){
        startFaceScan('PUNCH_OUT', 'punch-out');
    }

    function selectPresentRosterRow(contactId){
        if (contactId === undefined || contactId === null || String(contactId) === '') return false;
        var stableContactId = String(contactId);
        var matchedRow = null;
        for (var rowIndex = 0; rowIndex < $scope.list.length; rowIndex++) {
            var row = $scope.list[rowIndex];
            var rowContactId = (row.contact_id !== undefined && row.contact_id !== null) ? String(row.contact_id) : '';
            if (rowContactId !== stableContactId) continue;
            if (matchedRow !== null) return false;
            matchedRow = row;
        }
        if (matchedRow === null) return false;
        if (!faceScanSelections[stableContactId]) {
            faceScanSelections[stableContactId] = {
                selected: matchedRow.selected, negative: matchedRow.negative, skip: matchedRow.skip
            };
        }
        matchedRow.negative = 0;
        matchedRow.selected = true;
        matchedRow.skip = false;
        return true;
    }

    function reflectRecordedFaceAttendance(contactId, attendanceId){
        if (contactId === undefined || contactId === null || String(contactId) === '') return false;
        var stableContactId = String(contactId);
        var matchedRow = null;
        for (var rowIndex = 0; rowIndex < $scope.list.length; rowIndex++) {
            var row = $scope.list[rowIndex];
            var rowContactId = (row.contact_id !== undefined && row.contact_id !== null) ? String(row.contact_id) : '';
            if (rowContactId !== stableContactId) continue;
            if (matchedRow !== null) return false;
            matchedRow = row;
        }
        if (matchedRow === null) return false;
        matchedRow.attendance_id = String(attendanceId || matchedRow.attendance_id || '0');
        matchedRow.negative = 0;
        matchedRow.selected = false;
        matchedRow.skip = true;
        return true;
    }

    function discardFaceSelections(){
        memberRows().forEach(function(row){
            var previous = faceScanSelections[String(row.contact_id)];
            if (!previous) return;
            if (row.selected === true && Number(row.negative) === 0 && row.skip === false) {
                row.selected = previous.selected;
                row.negative = previous.negative;
                row.skip = previous.skip;
            }
        });
        faceScanSelections = {};
        $scope.faceMatched = [];
    }

    function stopFailedFaceScan(preserveSelections){
        faceNonceBusy = false;
        stopFaceAttemptLoop();
        clearFaceStartRetry();
        stopFaceLeaseHeartbeat();
        stopFacePlanarWorker();
        if (faceScan) apiCall.callEndpoint({session_id: session_id, scan_id: faceScan.scan_id, scan_token: faceScan.scan_token}, 'v1/face-attendance.php', 'cancelFaceScan', json_header);
        if (faceMachine && ['IDLE', 'COMPLETED', 'CANCELLED'].indexOf(faceMachine.state) === -1) faceMachine.cancel();
        faceScan = null;
        faceChallenge = null;
        $scope.faceChallengeActive = false;
        if (preserveSelections !== true) discardFaceSelections();
        $scope.attendanceStage = 'REVIEW';
        $scope.faceMessage = 'Face scanning is temporarily unavailable. Continue with the attendance list.';
    }

    function scheduleFaceRecovery(callback, retryAfter){
        if (faceRecoveryTimer || !faceScan) return;
        if (faceRecoveryCount >= 3) {
            stopFailedFaceScan(true);
            $scope.faceMessage = 'Scanning could not reconnect. Accepted matches are kept; finish using the attendance list.';
            return;
        }
        var generation = faceAttemptGeneration;
        var scanId = faceScan.scan_id;
        var delay = [1000, 2000, 4000][faceRecoveryCount++];
        if (Number.isFinite(retryAfter)) delay = Math.max(delay, Math.min(10000, retryAfter));
        faceRecovering = true;
        $scope.faceMessage = 'Connection interrupted. Keeping accepted matches and checking the current scan…';
        faceRecoveryTimer = window.setTimeout(function(){
            faceRecoveryTimer = null;
            if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId) return;
            callback();
        }, delay);
    }

    function recoverFaceAttempt(attempt, receive, retryAfter){
        var generation = faceAttemptGeneration;
        var scanId = attempt.scan_id;
        scheduleFaceRecovery(function(){
            apiCall.callEndpoint({session_id: session_id, scan_id: scanId, scan_token: attempt.scan_token,
                client_attempt_id: attempt.client_attempt_id}, 'v1/face-attendance.php', 'getFaceScanStatus', json_header).then(function(response){
                if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId) return;
                var body = response.data || response;
                if (body.state !== 'ACTIVE') {
                    if (body.retryable === true) recoverFaceAttempt(attempt, receive, body.retry_after_ms);
                    else stopFailedFaceScan(true);
                    return;
                }
                if (body.attempt && body.attempt.state === 'RECORDED') {
                    faceRecovering = false;
                    receive(body.attempt);
                } else if (body.attempt && body.attempt.state === 'NOT_FOUND' && body.recovery_action === 'RECAPTURE') {
                    // The old ID remains bound to its request. A new capture gets
                    // a fresh nonce and ID; never replay uncertain frame bytes.
                    faceRecovering = false;
                    faceChallenge = null;
                    $scope.faceChallengeActive = false;
                    if (faceMachine.state !== 'SCANNING') faceMachine.resume();
                    scheduleFaceAttempt(Math.max(350, Math.min(10000, Number(body.retry_after_ms) || 1000)));
                } else if (body.retryable === true && body.recovery_action === 'CHECK_STATUS') {
                    recoverFaceAttempt(attempt, receive, body.retry_after_ms);
                } else {
                    stopFailedFaceScan(true);
                }
            }).catch(function(){
                if (generation === faceAttemptGeneration && faceScan && faceScan.scan_id === scanId) recoverFaceAttempt(attempt, receive);
            });
        }, retryAfter);
    }

    function recoverCaptureNonce(retryAfter){
        scheduleFaceRecovery(function(){
            faceRecovering = false;
            captureFaceAttempt();
        }, retryAfter);
    }

    function captureFaceAttempt(){
        if (faceRecovering || !faceMachine || !faceMachine.canCapture() || !faceScan || faceNonceBusy) return;
        // The edge observer must have seen one stable, centred, quality-valid
        // face for 300 ms before a nonce or any image payload is requested.
        if (!faceAttendanceSingleFacePresent || !faceReadyAt || Date.now() - faceReadyAt < 300) {
            scheduleFaceAttempt(100);
            return;
        }
        faceNonceBusy = true;
        var activeScanId = faceScan.scan_id;
        var timing = {ready_at: faceReadyAt, nonce_at: Date.now(), preview_ms: facePreviewLatencyMs};
        apiCall.callEndpoint({session_id: session_id, scan_id: activeScanId, scan_token: faceScan.scan_token}, 'v1/face-attendance.php', 'getCaptureNonce', json_header).then(function(response){
            faceNonceBusy = false;
            var body = response.data || response;
            if (!faceScan || faceScan.scan_id !== activeScanId) return;
            if (body.state !== 'READY' || !body.capture_nonce) {
                if (body.retryable === true) { recoverCaptureNonce(body.retry_after_ms); return; }
                stopFailedFaceScan(true);
                return;
            }
            faceScan.capture_nonce = body.capture_nonce;
            timing.nonce_ms = Math.max(0, Date.now() - timing.nonce_at);
            captureFaceAttemptWithNonce(timing);
        }).catch(function(){
            faceNonceBusy = false;
            if (faceScan && faceScan.scan_id === activeScanId) recoverCaptureNonce();
        });
    }

    function captureFaceAttemptWithNonce(timing){
        if (!faceMachine || !faceMachine.canCapture() || !faceScan) return;
        $scope.faceRetryAvailable = false;
        if (faceAttemptTimer) {
            window.clearTimeout(faceAttemptTimer);
            faceAttemptTimer = null;
        }
        var generation = faceAttemptGeneration;
        var scanId = faceScan.scan_id;
        faceMachine.captureStarted();
        var video = document.getElementById('faceAttendanceVideo');
        if (!video) {
            stopFailedFaceScan();
            return;
        }
        var canvas = document.createElement('canvas');
        var frames = [];
        var captureContexts = [];
        var clientZVariances = [];
        var videoAspectRatio = video.videoWidth > 0 && video.videoHeight > 0
            ? video.videoHeight / video.videoWidth : NaN;
        timing.capture_at = Date.now();
        function capture(index){
            if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId || !faceMachine || faceMachine.state !== 'CAPTURING') return;
            grabAttendanceSnapshotDataUrl(video, canvas, function(frameDataUrl, captureContext) {
                if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId || !faceMachine || faceMachine.state !== 'CAPTURING') return;
                checkAttendancePlanarZ(canvas, videoAspectRatio, function(observation){
                if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId || !faceMachine || faceMachine.state !== 'CAPTURING') return;
                if (observation && observation.throttled) {
                    window.setTimeout(function(){ capture(index); }, 110);
                    return;
                }
                var metrics = observation && observation.metrics ? observation.metrics : {};
                var clientZVariance = Number(metrics.client_z_variance);
                var edgeErrors = observation && Array.isArray(observation.errors) ? observation.errors : ['QUALITY_UNAVAILABLE'];
                var planarFailed = !Number.isFinite(clientZVariance) || clientZVariance < 0.10;
                var nonPlanarQualityFailed = observation === null || edgeErrors.some(function(error){
                    return ['BLUR','LIGHTING','QUALITY_UNAVAILABLE','NO_FACE','MULTIPLE_FACES','PLANAR_UNAVAILABLE'].indexOf(error) !== -1;
                });
                if (nonPlanarQualityFailed) {
                    $scope.faceMessage = Number.isFinite(clientZVariance)
                        ? 'Camera quality changed. Hold still in even light and retry.'
                        : 'Camera quality or 3D depth check is unavailable. Please retry with a supported camera.';
                    if (faceMachine.state !== 'SCANNING') faceMachine.resume();
                    scheduleFaceAttempt(600);
                    return;
                }
                if (planarFailed) {
                    // Device-dependent depth telemetry is guidance only. MN3
                    // and SFace remain the authorities for this submission.
                    $scope.faceMessage = 'Depth signal is low; continuing with server liveness checks.';
                }
                frames.push(frameDataUrl);
                captureContexts.push(captureContext);
                clientZVariances.push(Number.isFinite(clientZVariance) ? clientZVariance : null);
                if (index < 2) {
                    window.setTimeout(function(){ capture(index + 1); }, 140);
                    return;
                }
                faceMachine.framesCaptured();
                timing.capture_ms = Math.max(0, Date.now() - timing.capture_at);
                timing.request_at = Date.now();
                var attemptPayload = {
                session_id: session_id,
                scan_id: faceScan.scan_id,
                scan_token: faceScan.scan_token,
                client_attempt_id: 'web-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                capture_nonce: faceScan.capture_nonce,
                frames: frames,
                capture_contexts: captureContexts,
                client_z_variance: clientZVariances.every(Number.isFinite)
                    ? Math.min.apply(null, clientZVariances) : null
            };
            if (faceChallenge) attemptPayload.challenge_token = faceChallenge.token;
            function receiveAttempt(body){
                if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId) return;
                if (body.state === 'RECORDED' && !timing.recorded) {
                    timing.recorded = true;
                    var finishedAt = Date.now();
                    var sample = {
                        total_ms: Math.max(0, finishedAt - (timing.ready_at || timing.nonce_at)),
                        preview_ms: Math.max(0, timing.preview_ms || 0),
                        nonce_ms: timing.nonce_ms || 0,
                        capture_ms: timing.capture_ms || 0,
                        request_ms: Math.max(0, finishedAt - timing.request_at),
                        inference_ms: Math.max(0, Number(body.latency_ms) || 0),
                        from_face: !!timing.ready_at,
                        decision: String(body.decision || 'UNKNOWN')
                    };
                    $scope.faceScanTimingSamples.push(sample);
                    if ($scope.faceScanTimingSamples.length > 25) $scope.faceScanTimingSamples.shift();
                    faceReadyAt = 0;
                    advanceCandidateEpoch();
                }
                if (body.state === 'REJECTED') {
                    if (body.retryable === true) {
                        recoverFaceAttempt(attemptPayload, receiveAttempt, body.retry_after_ms);
                        return;
                    }
                    if (['INFERENCE_BUSY', 'INFERENCE_OFFLINE', 'INFERENCE_TIMEOUT', 'SCAN_BUSY', 'SYSTEM_NOT_READY', 'SCAN_ACCESS', 'REJECT_REPLAY'].indexOf(body.reason) !== -1) {
                        stopFailedFaceScan(true);
                        return;
                    }
                    $scope.faceMessage = body.message || 'This face was not accepted. The child remains Absent; move to the next child.';
                    if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                    faceAwaitingNextChild = true;
                    faceWaitSawNoFace = false;
                    faceWaitStartTime = 0;
                    waitForNextFace();
                    return;
                }
                faceRecoveryCount = 0;
                faceRecovering = false;
                if (body.next_capture_nonce) faceScan.capture_nonce = body.next_capture_nonce;
                faceMachine.result(body);
                if (body.liveness === 'CHALLENGE' && body.challenge && body.challenge.token) {
                    faceChallenge = body.challenge;
                    $scope.faceChallengeActive = true;
                    $scope.faceChallengePrompt = faceChallenge.type === 'LOOK_LEFT'
                        ? 'Turn your face toward the left side of the camera frame.'
                        : 'Turn your face toward the right side of the camera frame.';
                    $scope.faceMessage = $scope.faceChallengePrompt + ' Hold that position briefly.';
                    scheduleFaceAttempt(1800);
                    return;
                }
                faceChallenge = null;
                $scope.faceChallengeActive = false;
                $scope.faceChallengePrompt = '';
                var recognizedMemberId = body.member_id ? String(body.member_id) : '';
                var recognizedContactId = body.contact_id ? String(body.contact_id) : '';
                var automaticallyRecorded = body.decision === 'ACCEPT'
                    && ['ATTENDANCE_RECORDED', 'ALREADY_PRESENT'].indexOf(body.effect) !== -1;
                if (automaticallyRecorded) {
                    $scope.faceRetryAvailable = false;
                    if (!reflectRecordedFaceAttendance(body.contact_id, body.attendance_id)) {
                        $scope.faceMessage = 'Attendance was recorded, but this row needs a refresh before the next scan.';
                        if (typeof show_snack === 'function') show_snack($scope.faceMessage);
                    } else {
                        if (recognizedMemberId !== '') faceScannedMemberIds[recognizedMemberId] = true;
                        if (recognizedContactId !== '') faceScannedMemberIds[recognizedContactId] = true;
                        if (body.member_name && $scope.faceMatched.indexOf(body.member_name) === -1) {
                            $scope.faceMatched.unshift(body.member_name);
                        }
                        $scope.faceMessage = (body.member_name || 'Child') + (body.effect === 'ALREADY_PRESENT' ? ' is already marked Present.' : ' marked Present automatically.');
                        if (typeof show_snack === 'function') show_snack($scope.faceMessage);
                    }
                    faceAwaitingNextChild = true;
                    faceWaitSawNoFace = false;
                    faceWaitStartTime = 0;
                    if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                    waitForNextFace();
                    return;
                }
                var alreadyFaceScanned = body.member_already_selected === true;
                if (alreadyFaceScanned) {
                    $scope.faceMessage = (body.member_name || 'This child') + ' already scanned. Please use the next child.';
                    faceAwaitingNextChild = true;
                    faceWaitSawNoFace = false;
                    faceWaitStartTime = 0;
                    if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                    waitForNextFace();
                    return;
                }
                if (body.decision === 'ACCEPT' && body.effect === 'SELECT_PRESENT') {
                    $scope.faceRetryAvailable = false;
                    if (!selectPresentRosterRow(body.contact_id)) {
                        $scope.faceMessage = 'This face could not be linked to one roster row. The child remains Absent; continue with the next child.';
                        if (typeof show_snack === 'function') {
                            show_snack($scope.faceMessage);
                        }
                        faceAwaitingNextChild = true;
                        faceWaitSawNoFace = false;
                        faceWaitStartTime = 0;
                        if (faceMachine && faceMachine.state !== 'SCANNING') {
                            faceMachine.resume();
                        }
                        waitForNextFace();
                        return;
                    }
                    if (recognizedMemberId !== '') faceScannedMemberIds[recognizedMemberId] = true;
                    if (recognizedContactId !== '') faceScannedMemberIds[recognizedContactId] = true;
                    if (body.member_name && $scope.faceMatched.indexOf(body.member_name) === -1) {
                        $scope.faceMatched.unshift(body.member_name);
                    }
                    if (typeof selectDeselectItem === 'function') {
                        selectDeselectItem('M');
                    }
                    $scope.faceMessage = (body.member_name || 'Child') + ' recognized! Marked Present in list.';
                    if (typeof show_snack === 'function') {
                        show_snack((body.member_name || 'Child') + ' marked Present');
                    }
                    // Auto-advance continuous scanning without a blocking modal popup
                    faceAwaitingNextChild = true;
                    faceWaitSawNoFace = false;
                    faceWaitStartTime = 0;
                    if (faceMachine && faceMachine.state !== 'SCANNING') {
                        faceMachine.resume();
                    }
                    waitForNextFace();
                } else if (body.decision === 'REVIEW') {
                    var reviewStage = String(body.stage || '').toUpperCase();
                    var reviewReason = String(body.reason_code || '').toUpperCase();
                    var acquisitionNeedsRetry = ['DETECTION', 'GUIDE_SELECTION', 'QUALITY'].indexOf(reviewStage) !== -1
                        || ['NO_FACE', 'NO_FACE_IN_GUIDE'].indexOf(reviewReason) !== -1;
                    if (acquisitionNeedsRetry && !faceRetryUsed && body.outcome !== 'AMBIGUOUS') {
                        $scope.faceMessage = formatFaceAttendanceGuidance(body);
                        $scope.faceRetryAvailable = true;
                        if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                    } else {
                        $scope.faceMessage = formatFaceAttendanceGuidance(body) + ' It remains Absent; show the next child.';
                        faceAwaitingNextChild = true;
                        faceWaitSawNoFace = false;
                        faceWaitStartTime = 0;
                        if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                        waitForNextFace();
                    }
                } else {
                    $scope.faceMessage = formatFaceAttendanceGuidance(body);
                    $scope.faceRetryAvailable = false;
                    if (faceMachine && faceMachine.state !== 'SCANNING') faceMachine.resume();
                    faceAwaitingNextChild = true;
                    faceWaitSawNoFace = false;
                    faceWaitStartTime = 0;
                    waitForNextFace();
                }
            }
            apiCall.callEndpoint(attemptPayload, 'v1/face-attendance.php', 'recognizeFaceAttempt', json_header).then(function(response){
                receiveAttempt(response.data || response);
            }).catch(function(){
                if (generation !== faceAttemptGeneration || !faceScan || faceScan.scan_id !== scanId) return;
                recoverFaceAttempt(attemptPayload, receiveAttempt);
            });
                });
            }, index === 2);
        }
        capture(0);
    }

    function completeFaceScan(){
        if ($scope.attendanceStage !== 'SCANNING') return;
        if (!faceScan) {
            $scope.faceMessage = 'The scanner is still starting. Please wait a moment.';
            return;
        }
        if (['SCANNING','MATCHED','REVIEW','MANUAL'].indexOf(faceMachine.state) === -1) {
            faceFinishRequested = true;
            $scope.faceMessage = 'Finishing the current face check…';
            window.setTimeout(function(){
                if ($scope.attendanceStage === 'SCANNING' && faceFinishRequested) {
                    if (faceMachine && faceMachine.state !== 'SCANNING' && ['RECONCILING','COMPLETED','CANCELLED'].indexOf(faceMachine.state) === -1) {
                        faceMachine.resume();
                    }
                    completeFaceScan();
                }
            }, 1200);
            return;
        }
        faceFinishRequested = false;
        stopFaceAttemptLoop();
        clearFaceStartRetry();
        $scope.faceMessage = $scope.faceLiveWriteMode ? 'Finishing face attendance…' : 'Preparing attendance review…';
        faceMachine.reconcile();
        apiCall.callEndpoint({session_id: session_id, scan_id: faceScan.scan_id, scan_token: faceScan.scan_token}, 'v1/face-attendance.php', 'completeFaceScan', json_header).then(function(response){
            var body = response.data || response;
            if (body.state === 'COMPLETED') {
                var liveWrite = body.live_write === true || (faceScan && faceScan.live_write === true);
                faceMachine.complete();
                stopFacePlanarWorker();
                stopFaceLeaseHeartbeat();
                faceScan = null;
                if (liveWrite) {
                    $scope.faceLiveWriteMode = true;
                    $scope.attendanceStage = 'COMPLETE';
                    $scope.faceMessage = 'Scanning finished. Recorded face matches are saved; unscanned children were not changed.';
                } else {
                    $scope.attendanceStage = 'REVIEW';
                    $scope.faceMessage = 'Scanning finished. Check the totals, then punch attendance.';
                }
            } else {
                $scope.attendanceStage = 'SCANNING';
                $scope.faceMessage = body.message || 'Review could not be prepared. Try Finish Scanning again.';
                if (faceMachine.state === 'RECONCILING') faceMachine.manualFallback();
            }
        }).catch(function(){
            $scope.attendanceStage = 'SCANNING';
            $scope.faceMessage = 'Review could not be prepared. Check the connection and try Finish Scanning again.';
            if (faceMachine.state === 'RECONCILING') faceMachine.manualFallback();
        });
    }
    function retryFaceAttempt(){
        if (!faceMachine || !faceScan || $scope.attendanceStage !== 'SCANNING' || faceRetryUsed) return;
        faceRetryUsed = true;
        faceAwaitingNextChild = false;
        faceWaitSawNoFace = false;
        faceWaitStartTime = 0;
        $scope.faceRetryAvailable = false;
        if (['REVIEW', 'REJECTED', 'ERROR'].indexOf(faceMachine.state) !== -1) faceMachine.resume();
        $scope.faceMessage = 'Hold still — checking the face again…';
        scheduleFaceAttempt(250);
    }

    function cancelFaceScan(){
        if ($scope.attendanceStage !== 'SCANNING') return;
        var liveWrite = $scope.faceLiveWriteMode === true || (faceScan && faceScan.live_write === true);
        Swal.fire({
            title: 'Cancel this scan?',
            text: liveWrite ? 'This stops the camera. Recorded face attendance stays saved.' : 'Matches from this scan will be removed and the roster will return to its earlier state.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Yes, cancel scan',
            cancelButtonText: 'Keep scanning'
        }).then(function(result){
            if (!result.isConfirmed) return;
            stopFaceAttemptLoop();
            clearFaceStartRetry();
            stopFacePlanarWorker();
            stopFaceLeaseHeartbeat();
            if (faceScan) apiCall.callEndpoint({session_id: session_id, scan_id: faceScan.scan_id, scan_token: faceScan.scan_token}, 'v1/face-attendance.php', 'cancelFaceScan', json_header);
            if (faceMachine && ['IDLE','COMPLETED','CANCELLED'].indexOf(faceMachine.state) === -1) faceMachine.cancel();
            faceScan = null;
            faceFinishRequested = false;
            faceChallenge = null;
            $scope.faceChallengeActive = false;
            discardFaceSelections();
            scanRosterSnapshot = null;
            $scope.faceMessage = liveWrite
                ? 'Scanning stopped. Recorded face attendance remains saved.'
                : 'Scan cancelled. No scan changes were kept.';
            $scope.attendanceStage = 'READY';
            $scope.$evalAsync();
        });
    }
    function resetFaceDetectionUi(){
        $scope.faceAttendanceDetectionReady = false;
        $scope.faceAttendanceDetectionMessage = 'Position one child inside the face guide.';
        $scope.faceAttendanceDetectionStatusClass = 'is-waiting';
        $scope.faceAttendanceBoxStyle = {display: 'none'};
    }

    function syncFacePreviewFrame(video){
        var frame = document.getElementById('faceAttendanceFrame');
        if (!frame || !video || !video.videoWidth || !video.videoHeight) return;
        frame.style.aspectRatio = video.videoWidth + ' / ' + video.videoHeight;
        video.style.width = '100%';
        video.style.height = '100%';
        video.style.objectFit = 'contain';
        syncFaceGuideToFrame(frame, video);
    }

    function mapFaceBox(face, video){
        var intrinsicWidth = video.videoWidth || 1;
        var intrinsicHeight = video.videoHeight || 1;
        var renderedWidth = video.clientWidth || video.offsetWidth || intrinsicWidth;
        var renderedHeight = video.clientHeight || video.offsetHeight || intrinsicHeight;
        var normalized = face.normalized_box || null;
        var x = normalized ? Number(normalized.x) * intrinsicWidth : (Number(face.x) || 0);
        var y = normalized ? Number(normalized.y) * intrinsicHeight : (Number(face.y) || 0);
        var width = normalized ? Number(normalized.width) * intrinsicWidth : (Number(face.width) || 0);
        var height = normalized ? Number(normalized.height) * intrinsicHeight : (Number(face.height) || 0);
        x = Math.max(0, Math.min(intrinsicWidth, x));
        y = Math.max(0, Math.min(intrinsicHeight, y));
        var right = Math.max(x, Math.min(intrinsicWidth, x + width));
        var bottom = Math.max(y, Math.min(intrinsicHeight, y + height));
        var isMirrored = ($scope.cameraFacingMode === 'user');
        var renderX = isMirrored ? (intrinsicWidth - right) : x;
        return {
            display: 'block',
            left: ((renderX / intrinsicWidth) * renderedWidth) + 'px',
            top: ((y / intrinsicHeight) * renderedHeight) + 'px',
            width: (((right - x) / intrinsicWidth) * renderedWidth) + 'px',
            height: (((bottom - y) / intrinsicHeight) * renderedHeight) + 'px'
        };
    }

    function applyFaceDetection(body, video, capturedAt){
        var faces = Array.isArray(body.faces) ? body.faces : [];
        var selectedIndex = Number.isInteger(body.selected_face_index) ? body.selected_face_index : -1;
        var face = selectedIndex >= 0 ? faces[selectedIndex] : null;
        faceAttendanceFaceCount = faces.length;
        facePreviewObservedAt = capturedAt;
        if (faceAwaitingNextChild) {
            if (faces.length === 0 && ['NO_FACE', 'NO_FACE_IN_GUIDE'].indexOf(body.reason_code) !== -1) {
                if (!faceWaitStartTime) faceWaitStartTime = capturedAt;
            } else if (!faceWaitSawNoFace) {
                faceWaitStartTime = 0;
            }
        }
        $scope.faceAttendanceDetectionReady = true;
        if (body.reason_code === 'MULTIPLE_FACES_IN_GUIDE') {
            faceAttendanceSingleFacePresent = false;
            faceReadyAt = 0;
            $scope.faceAttendanceDetectionMessage = 'Two faces are inside the guide — keep only the child being scanned in the oval.';
            $scope.faceAttendanceDetectionStatusClass = 'is-warning';
            $scope.faceAttendanceBoxStyle = {display: 'none'};
        } else if (!face) {
            faceAttendanceSingleFacePresent = false;
            faceReadyAt = 0;
            $scope.faceAttendanceDetectionMessage = body.reason_code === 'NO_FACE_IN_GUIDE'
                ? 'Face detected outside the guide — move the child into the oval.'
                : 'No face detected — move one child inside the guide.';
            $scope.faceAttendanceDetectionStatusClass = 'is-waiting';
            $scope.faceAttendanceBoxStyle = {display: 'none'};
        } else {
            var check = evaluateFacePoseAndBoundary(face, video);
            if (!check.insideGuide) {
                faceAttendanceSingleFacePresent = false;
                faceReadyAt = 0;
                $scope.faceAttendanceDetectionMessage = 'Center face inside the face guide.';
                $scope.faceAttendanceDetectionStatusClass = 'is-warning';
                $scope.faceAttendanceBoxStyle = {display: 'none'};
            } else if (!check.yawOk) {
                faceAttendanceSingleFacePresent = false;
                faceReadyAt = 0;
                $scope.faceAttendanceDetectionMessage = 'Turn face directly toward camera.';
                $scope.faceAttendanceDetectionStatusClass = 'is-warning';
                $scope.faceAttendanceBoxStyle = {display: 'none'};
            } else if (!check.tiltOk) {
                faceAttendanceSingleFacePresent = false;
                faceReadyAt = 0;
                $scope.faceAttendanceDetectionMessage = 'Keep head level and upright.';
                $scope.faceAttendanceDetectionStatusClass = 'is-warning';
                $scope.faceAttendanceBoxStyle = {display: 'none'};
            } else {
                faceAttendanceSingleFacePresent = true;
                if (!faceReadyAt) faceReadyAt = Date.now();
                facePreviewLatencyMs = Math.max(0, Date.now() - capturedAt);
                syncFacePreviewFrame(video);
                $scope.faceAttendanceDetectionMessage = 'Face positioned—hold still.';
                $scope.faceAttendanceDetectionStatusClass = 'is-ready';
                $scope.faceAttendanceBoxStyle = mapFaceBox(face, video);
            }
        }
    }

    function startFaceDetection(){
        stopFaceDetection();
        facePreviewAvailable = false;
        faceAttendanceSingleFacePresent = false;
        faceAttendanceFaceCount = 0;
        $scope.faceAttendanceDetectionReady = false;
        $scope.faceAttendanceDetectionMessage = 'Walk up and face the camera.';
        $scope.faceAttendanceDetectionStatusClass = 'is-waiting';
        // No preview pixels leave the browser before the edge gates have run.
        // A missing worker is a secure failure, not a reason to bypass Layer 1.
        if (facePlanarWorker && facePlanarWorkerReady && window.createImageBitmap) scheduleWalkUpObservation(0);
        else window.setTimeout(function(){
            if (!facePreviewAvailable && (!facePlanarWorker || !facePlanarWorkerReady)) {
                console.warn('[face-attendance] edge quality observer unavailable; capture is blocked');
                $scope.$evalAsync(function(){
                    $scope.faceAttendanceDetectionReady = true;
                    $scope.faceAttendanceDetectionMessage = 'This browser cannot run the required secure camera checks.';
                    $scope.faceAttendanceDetectionStatusClass = 'is-warning';
                });
            }
        }, 8000);
    }

    function pollFaceModelPreview(){
        if (!faceStream || !faceScan || faceMachine.state === 'IDLE' || faceMachine.state === 'COMPLETED' || faceMachine.state === 'CANCELLED') return;
        if (faceMachine && (faceMachine.state === 'CAPTURING' || faceMachine.state === 'PROCESSING')) {
            facePreviewTimer = window.setTimeout(pollFaceModelPreview, 400);
            return;
        }
        var video = document.getElementById('faceAttendanceVideo');
        if (!video || video.readyState < 2 || facePreviewBusy) {
            facePreviewTimer = window.setTimeout(pollFaceModelPreview, 300);
            return;
        }
        syncFacePreviewFrame(video);
        var canvas = document.createElement('canvas');
        canvas.width = video.videoWidth || 640;
        canvas.height = video.videoHeight || 480;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        if (typeof canvas.toBlob !== 'function') {
            $scope.faceAttendanceDetectionReady = true;
            $scope.faceAttendanceDetectionMessage = 'Live model preview is unavailable; keep one face inside the guide. Final recognition remains server-verified.';
            $scope.faceAttendanceDetectionStatusClass = 'is-warning';
            facePreviewTimer = window.setTimeout(pollFaceModelPreview, 1200);
            return;
        }
        var generation = facePreviewGeneration;
        var capturedAt = Date.now();
        facePreviewBusy = true;
        captureDisplayedVideoFrame(video, canvas, 0.72, function(blob, dataUrl, captureContext){
            if (generation !== facePreviewGeneration) return;
            if (!blob) {
                facePreviewBusy = false;
                facePreviewTimer = window.setTimeout(pollFaceModelPreview, 1200);
                return;
            }
            var form = new FormData();
            var activeSessionId = session_id || (session && session.session_id) || '';
            form.append('action', 'previewFace');
            form.append('session_id', activeSessionId);
            form.append('branch_id', session.bid);
            form.append('frame', blob, 'preview.jpg');
            form.append('capture_context', JSON.stringify(captureContext));
            $http({
                method: 'POST',
                url: window.location.origin + '/routes/faceAttendanceRoute.php',
                params: {action: 'previewFace'},
                data: form,
                headers: {
                    'Content-Type': undefined,
                    'uid': String(session.user_id),
                    'bid': String(session.bid),
                    'token': String(session.token),
                    'device': typeof get_device === 'function' ? get_device() : navigator.userAgent
                },
                transformRequest: angular.identity
            }).then(function(response){
                if (generation !== facePreviewGeneration) return;
                var body = response.data || response;
                if (body.state !== 'PREVIEW' || !Array.isArray(body.faces)) throw new Error('MODEL_PREVIEW_UNAVAILABLE');
                facePreviewAvailable = true;
                $scope.$evalAsync(function(){ if (generation === facePreviewGeneration) applyFaceDetection(body, video, capturedAt); });
            }).catch(function(){
                if (generation !== facePreviewGeneration) return;
                faceWaitStartTime = 0;
                faceWaitSawNoFace = false;
                facePreviewAvailable = false;
                faceAttendanceSingleFacePresent = false;
                faceAttendanceFaceCount = 0;
                faceReadyAt = 0;
                $scope.$evalAsync(function(){
                    $scope.faceAttendanceDetectionReady = true;
                    $scope.faceAttendanceDetectionMessage = facePreviewAvailable
                        ? 'Model preview is temporarily unavailable; keep one face inside the guide.'
                        : 'Live model preview is unavailable; keep one face inside the guide. Final recognition remains server-verified.';
                    $scope.faceAttendanceDetectionStatusClass = 'is-warning';
                    $scope.faceAttendanceBoxStyle = {display: 'none'};
                });
            }).finally(function(){
                if (generation !== facePreviewGeneration) return;
                facePreviewBusy = false;
                facePreviewTimer = window.setTimeout(pollFaceModelPreview, 650);
            });
        }, false);
    }

    function stopFaceDetection(){
        facePreviewGeneration++;
        faceWaitStartTime = 0;
        faceWaitSawNoFace = false;
        faceReadyAt = 0;
        if (facePreviewTimer) {
            window.clearTimeout(facePreviewTimer);
            facePreviewTimer = null;
        }
        if (faceWalkUpTimer) window.clearTimeout(faceWalkUpTimer);
        faceWalkUpTimer = null;
        faceWalkUpBusy = false;
        facePreviewBusy = false;
        facePreviewAvailable = false;
        faceAttendanceSingleFacePresent = false;
        faceAttendanceFaceCount = 0;
        $scope.faceAttendanceDetectionReady = false;
        $scope.faceAttendanceDetectionMessage = 'Camera stopped. Start a new face scan when the next group is ready.';
        $scope.faceAttendanceDetectionStatusClass = 'is-stopped';
        $scope.faceAttendanceBoxStyle = {display: 'none'};
    }

    function switchFaceCamera(context){
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return;
        var attendance = context === 'attendance' || (context !== 'enrollment' && faceScan);
        // Do not mix camera sources within a capture or replace an in-flight
        // startup. A switch can be retried once the current operation finishes.
        if (attendance) {
            if (!faceScan || !faceMachine || faceMachine.state !== 'SCANNING' || faceNonceBusy) return;
        } else if (!enrollmentMember || $scope.faceEnrollmentState !== 'READY' || $scope.faceEnrollmentReferenceBusy) {
            return;
        }
        var previousMode = $scope.cameraFacingMode || 'user';
        var newMode = (previousMode === 'environment') ? 'user' : 'environment';
        var generation = attendance ? ++faceCameraGeneration : ++enrollmentCameraGeneration;
        var waitingForDeparture = faceAwaitingNextChild;
        if (attendance) {
            stopFaceDetection();
            stopFaceAttemptLoop();
            faceAwaitingNextChild = waitingForDeparture;
            if (faceStream) faceStream.getTracks().forEach(function(track){ track.stop(); });
            faceStream = null;
        } else {
            stopEnrollmentDetection();
            resetEnrollmentDetectionUi();
            if (enrollmentStream) enrollmentStream.getTracks().forEach(function(track){ track.stop(); });
            enrollmentStream = null;
        }
        var video = document.getElementById(attendance ? 'faceAttendanceVideo' : 'faceEnrollmentVideo');
        if (video) video.srcObject = null;
        $scope.cameraFacingMode = newMode;
        try { window.localStorage.setItem('face_camera_facing_mode', newMode); } catch (e) {}

        function isCurrent(){
            return attendance
                ? generation === faceCameraGeneration && !!faceScan && faceMachine.state === 'SCANNING'
                : generation === enrollmentCameraGeneration && !!enrollmentMember && $scope.faceEnrollmentState === 'READY';
        }
        function openCamera(mode, fallback){
            navigator.mediaDevices.getUserMedia(getCameraConstraints(mode)).then(function(stream){
                if (!isCurrent()) { stream.getTracks().forEach(function(track){ track.stop(); }); return; }
                if (attendance) faceStream = stream;
                else enrollmentStream = stream;
                stream.getTracks().forEach(function(track){
                    track.addEventListener('ended', function(){
                        var ownsStream = attendance
                            ? generation === faceCameraGeneration && faceStream === stream
                            : generation === enrollmentCameraGeneration && enrollmentStream === stream;
                        if (!ownsStream) return;
                        $scope.$evalAsync(function(){
                            if (attendance) {
                                if (generation === faceCameraGeneration && faceStream === stream) stopFailedFaceScan();
                            } else if (generation === enrollmentCameraGeneration && enrollmentStream === stream) {
                                $scope.faceEnrollmentState = 'ERROR';
                                $scope.faceEnrollmentMessage = 'Camera disconnected. Tap Try Again to resume face setup.';
                                stopEnrollmentCamera();
                            }
                        });
                    });
                });
                if (video) {
                    video.srcObject = stream;
                    video.onloadedmetadata = function(){
                        if (attendance ? faceStream !== stream : enrollmentStream !== stream) return;
                        if (attendance) syncFacePreviewFrame(video);
                        else syncEnrollmentPreviewFrame(video);
                    };
                    video.play();
                }
                if (attendance) {
                    startFaceDetection();
                    if (faceAwaitingNextChild) waitForNextFace();
                    else scheduleFaceAttempt(700);
                } else {
                    startEnrollmentDetection();
                }
                $scope.$evalAsync(function(){
                    if (!isCurrent()) return;
                    var message = fallback ? 'Only one camera detected on this device.'
                        : (mode === 'environment' ? 'Rear' : 'Front') + ' camera active. Keep face in guide.';
                    if (attendance) $scope.faceAttendanceDetectionMessage = message;
                    else $scope.faceEnrollmentDetectionMessage = message;
                });
            }).catch(function(){
                if (!isCurrent()) return;
                if (!fallback) {
                    $scope.cameraFacingMode = previousMode;
                    try { window.localStorage.setItem('face_camera_facing_mode', previousMode); } catch (e) {}
                    openCamera(previousMode, true);
                    return;
                }
                $scope.$evalAsync(function(){
                    if (!isCurrent()) return;
                    if (attendance) stopFailedFaceScan();
                    else {
                        $scope.faceEnrollmentState = 'ERROR';
                        $scope.faceEnrollmentMessage = 'Camera unavailable. Tap Try Again to resume face setup.';
                        stopEnrollmentCamera();
                    }
                });
            });
        }
        openCamera(newMode, false);
    }
    $scope.switchFaceCamera = switchFaceCamera;

    function stopFaceCamera(){
        faceCameraGeneration++;
        stopFaceDetection();
        stopFaceAttemptLoop();
        clearFaceStartRetry();
        faceChallenge = null;
        $scope.faceChallengeActive = false;
        $scope.faceChallengePrompt = '';
        if (faceStream) { faceStream.getTracks().forEach(function(track){ track.stop(); }); faceStream = null; }
        var video = document.getElementById('faceAttendanceVideo');
        if (video) video.srcObject = null;
        stopEnrollmentCamera();
    }

    function stopEnrollmentCamera(){
        resetGuidedEnrollment();
        resetReferenceMismatch();
        enrollmentCameraGeneration++;
        $scope.faceEnrollmentPaused = false;
        $scope.faceEnrollmentPauseReason = '';
        stopEnrollmentDetection();
        if (enrollmentStream) { enrollmentStream.getTracks().forEach(function(track){ track.stop(); }); enrollmentStream = null; }
        var video = document.getElementById('faceEnrollmentVideo');
        if (video) video.srcObject = null;
    }

    function pauseCameraForHiddenPage(){
        if (faceScan) { stopFailedFaceScan(); return; }
        if (enrollmentMember && ['STARTING_CAMERA','READY','CAPTURING','LEAVE_GUIDE'].indexOf($scope.faceEnrollmentState) !== -1) {
            captureRequestSequence++;
            $scope.$evalAsync(function(){
                $scope.faceEnrollmentState = 'ERROR';
                $scope.faceEnrollmentMessage = 'Camera paused when this page was hidden. Tap Try Again to resume face setup.';
            });
        }
        stopFaceCamera();
    }
    window.addEventListener('pagehide', pauseCameraForHiddenPage);
    document.addEventListener('visibilitychange', function(){ if (document.hidden) pauseCameraForHiddenPage(); });
    // Reached only when every statement above ran: tells the page's start-up guard the app is usable.
    if (window.attendanceBoot) window.attendanceBoot.ready();
})
