'use strict';
const {assert, face, runCases} = require('./helpers/face-controller-harness');

const capability = {pose_protocol:'guided-adaptive-v2',template_count:6,burst_a_count:5,
    burst_b_count:3,departure_ms:750,windows:{centre_yaw_max:.06,centre_pitch_min:.35,
        centre_pitch_max:.75,side_yaw_min:.08,side_yaw_max:.16,pitch_tolerance:.08,
        pitch_delta_min:.05,pitch_delta_max:.10,stable_ms:300}};

async function begin(h) {
    class Tracker {
        constructor(video, canvas, facing, onUpdate, onFailure) {
            this.active = false; this.onUpdate = onUpdate; this.onFailure = onFailure;
            this.latest = {faceCount:1,correction:'Hold still.',stable:true,yaw:0,
                observedAt:h.now()};
            Tracker.instance = this;
        }
        start() { this.active = true; }
        stop() { this.active = false; }
        setTarget(slot) { this.target = slot; }
        resetHold() {}
        readyForCandidate() { return true; }
        steadinessScore() { return .01; }
    }
    h.window.FaceEnrollmentLocalTracker = Tracker;
    h.scope.startFaceEnrollment(h.scope.list[0]);
    await h.resolveRequest('beginEnrollment',{state:'READY',capture_session_token:'adaptive',
        has_pending_reference:true,pending_reference:{id:1,portrait_data_url:'data:fixture'}});
    await h.resolveCamera();
    await h.resolveRequest('previewEnrollment',{...face(),guided_enrollment:capability});
    assert.equal(h.scope.faceEnrollmentPoseProtocol,'guided-adaptive-v2');
    return Tracker;
}

runCases({
    async final_check_starts_with_face_present_and_no_departure_request(h) {
        await begin(h);
        h.scope.captureFaceEnrollment();
        for (let slot = 0; slot < 5; slot++) {
            await h.advanceClock(150);
            const request = h.pending('checkEnrollmentCandidate')[0];
            assert.ok(request, 'candidate request must be pending');
            assert.equal(Number(request.data.get('slot')), slot);
            await h.resolveRequest(request, {state:'ACCEPTED',candidate_token:'signed-' + slot,
                yaw:slot === 2 ? -.12 : slot === 3 ? .12 : 0});
        }
        assert.equal(h.scope.faceEnrollmentFrameCount, 5);
        assert.equal(h.scope.faceEnrollmentState, 'LEAVE_GUIDE');
        await h.advanceClock(600);
        assert.equal(h.pending('checkEnrollmentCandidate').length, 0,
            'final frame must wait for the separation interval');
        await h.advanceClock(300);
        const finalRequest = h.pending('checkEnrollmentCandidate')[0];
        assert.ok(finalRequest, 'final frame must start automatically with the face present');
        assert.equal(Number(finalRequest.data.get('slot')), 5);
    },
    async server_rejection_is_specific_and_bounded(h) {
        await begin(h);
        h.scope.captureFaceEnrollment(); await h.flush();
        assert.equal(h.pending('checkEnrollmentCandidate').length,1);
        await h.resolveRequest('checkEnrollmentCandidate',{state:'REJECTED',reason_code:'POSE_TARGET_MISMATCH',yaw:.1});
        assert.match(h.scope.faceEnrollmentDetectionMessage,/Look straight/);
        assert.equal(h.scope.faceEnrollmentPaused,false);
        assert.ok(h.timers.size > 0,'rejected candidate must schedule another observation');
        await h.advanceClock(10500,async()=>{
            for (const request of h.pending('checkEnrollmentCandidate'))
                await h.resolveRequest(request,{state:'REJECTED',reason_code:'POSE_TARGET_MISMATCH',yaw:.1});
        });
        assert.equal(h.scope.faceEnrollmentState,'ERROR','repeated rejection must stop with a specific reason');
        assert.match(h.scope.faceEnrollmentMessage,/Try Again/);
    },
    async worker_failure_returns_to_secure_capture(h) {
        const Tracker = await begin(h);
        Tracker.instance.onFailure('LANDMARKER_TRACK_FAILED');
        assert.equal(h.scope.faceEnrollmentState,'READY');
        assert.equal(h.scope.faceEnrollmentPoseProtocol,null);
        assert.match(h.scope.faceEnrollmentMessage,/Secure enrollment is still available/);
        for (const request of h.pending('previewEnrollment')) await h.resolveRequest(request,face());
        h.scope.captureFaceEnrollment(); await h.flush();
        assert.equal(h.pending('checkEnrollmentCandidate').length,0);
    }
});
