'use strict';
const {assert, face, startEnrollment, runCases} = require('./helpers/face-controller-harness');

async function fallbackAtEarlyCheck(h) {
    await startEnrollment(h);
    h.scope.captureFaceEnrollment();
    await h.advanceClock(1100, async () => {
        for (const request of h.pending('previewEnrollment')) await h.resolveRequest(request, face());
    });
    const request = h.pending('checkEarlyEnrollmentIdentity')[0];
    assert.ok(request, 'two frontal captures must trigger the identity check');
    assert.equal(request.data.getAll('frames[]').length, 2);
    assert.equal(JSON.parse(request.data.get('capture_contexts')).length, 2);
    assert.equal(h.scope.faceEnrollmentFrameCount, 2);
    return request;
}

runCases({
    async first_reference_warning_survives_capture_start(h) {
        h.scope.startFaceEnrollment(h.scope.list[0]);
        await h.resolveRequest('beginEnrollment', {state:'READY', capture_session_token:'first-time',
            has_pending_reference:false});
        await h.resolveCamera();
        await h.resolveRequest('previewEnrollment', {...face(), reference_match_state:'NO_REFERENCE'});
        h.scope.captureFaceEnrollment();
        await h.advanceClock(250);
        const reference = h.pending('onboardReference')[0];
        assert.ok(reference);
        await h.resolveRequest(reference, {state:'ACTIVE', reference_id:7, selected_frame_index:0,
            possible_duplicate:{conflicting_member_name:'Similar child'}});
        assert.match(h.scope.faceEnrollmentIdentityWarning, /Similar child/);
        await h.advanceClock(500, async () => {
            for (const preview of h.pending('previewEnrollment')) await h.resolveRequest(preview, face());
        });
        assert.match(h.scope.faceEnrollmentIdentityWarning, /Similar child/,
            'capture guidance must not replace the reference warning');
    },
    async fallback_confirmed_duplicate_stops_before_turn(h) {
        const request = await fallbackAtEarlyCheck(h);
        await h.resolveRequest(request, {state:'CONFIRMED_DUPLICATE', conflicting_member_name:'Other child'});
        await h.advanceClock(1000);
        assert.equal(h.scope.faceEnrollmentState, 'READY');
        assert.equal(h.scope.faceEnrollmentFrameCount, 2);
        assert.equal(h.scope.faceEnrollmentIssue.visible, true);
        assert.match(h.scope.faceEnrollmentMessage, /Other child/);
        assert.equal(h.pending('enrollFace').length, 0);
    },
    async fallback_possible_similarity_stays_visible(h) {
        const request = await fallbackAtEarlyCheck(h);
        await h.resolveRequest(request, {state:'POSSIBLE_SIMILARITY', conflicting_member_name:'Similar child'});
        assert.match(h.scope.faceEnrollmentIdentityWarning, /Similar child/);
        await h.advanceClock(500, async () => {
            for (const preview of h.pending('previewEnrollment')) await h.resolveRequest(preview, face());
        });
        assert.ok(h.scope.faceEnrollmentFrameCount > 2, 'possible similarity must allow capture');
        assert.match(h.scope.faceEnrollmentIdentityWarning, /Similar child/);
    },
    async fallback_unavailable_continues_with_warning(h) {
        const request = await fallbackAtEarlyCheck(h);
        await h.resolveRequest(request, {state:'CHECK_UNAVAILABLE'});
        assert.match(h.scope.faceEnrollmentIdentityWarning, /unavailable/i);
        await h.advanceClock(500, async () => {
            for (const preview of h.pending('previewEnrollment')) await h.resolveRequest(preview, face());
        });
        assert.ok(h.scope.faceEnrollmentFrameCount > 2);
    },
    async retake_discards_early_warning_and_progress(h) {
        const request = await fallbackAtEarlyCheck(h);
        await h.resolveRequest(request, {state:'POSSIBLE_SIMILARITY', conflicting_member_name:'Similar child'});
        h.scope.clearFaceReference();
        assert.equal(h.scope.faceEnrollmentFrameCount, 0);
        assert.equal(h.scope.faceEnrollmentIdentityWarning, '');
        assert.equal(h.scope.faceEnrollmentIssue.visible, false);
    },
    async adaptive_confirmed_duplicate_stops_before_turn(h) {
        class Tracker {
            constructor() { this.active = false; this.latest = {faceCount:1, correction:'Hold still.', stable:true,
                yaw:0, observedAt:h.now()}; }
            start() { this.active = true; }
            stop() { this.active = false; }
            setTarget(slot) { this.target = slot; }
            resetHold() {}
            readyForCandidate() { return true; }
            steadinessScore() { return .01; }
        }
        h.window.FaceEnrollmentLocalTracker = Tracker;
        h.scope.startFaceEnrollment(h.scope.list[0]);
        await h.resolveRequest('beginEnrollment', {state:'READY', capture_session_token:'adaptive',
            has_pending_reference:true, pending_reference:{id:1, portrait_data_url:'data:fixture'}});
        await h.resolveCamera();
        const capability = {pose_protocol:'guided-adaptive-v2',template_count:6,burst_a_count:5,
            burst_b_count:3,departure_ms:750,windows:{centre_yaw_max:.06,centre_pitch_min:.35,
                centre_pitch_max:.75,side_yaw_min:.08,side_yaw_max:.16,pitch_tolerance:.08,
                pitch_delta_min:.05,pitch_delta_max:.10,stable_ms:300}};
        await h.resolveRequest('previewEnrollment', {...face(), guided_enrollment:capability});
        h.scope.captureFaceEnrollment();
        for (let slot = 0; slot < 2; slot++) {
            await h.advanceClock(150);
            const request = h.pending('checkEnrollmentCandidate')[0];
            assert.ok(request);
            await h.resolveRequest(request, {state:'ACCEPTED', candidate_token:'signed-' + slot, yaw:0});
        }
        const early = h.pending('checkEarlyEnrollmentIdentity')[0];
        assert.ok(early);
        await h.resolveRequest(early, {state:'CONFIRMED_DUPLICATE', conflicting_member_name:'Other child'});
        await h.advanceClock(500);
        assert.equal(h.scope.faceEnrollmentState, 'READY');
        assert.equal(h.scope.faceEnrollmentIssue.visible, true);
        assert.equal(h.pending('checkEnrollmentCandidate').length, 0, 'turn candidate must not be requested');
    },
    async older_guided_fallback_checks_extra_frontal_before_turn(h) {
        const capability = {pose_protocol:'guided-five-v1',template_count:6,windows:{
            centre_yaw_max:.06,centre_pitch_min:.35,centre_pitch_max:.75,side_yaw_min:.08,side_yaw_max:.16,
            pitch_tolerance:.08,pitch_delta_min:.05,pitch_delta_max:.10,stable_ms:300}};
        const frontal = () => {
            const body = face(); body.guided_enrollment = capability;
            Object.assign(body.faces[0].quality, {accepted:true, pose_yaw:0, pose_pitch:.55});
            return body;
        };
        h.scope.startFaceEnrollment(h.scope.list[0]);
        await h.resolveRequest('beginEnrollment', {state:'READY', capture_session_token:'older-guided',
            has_pending_reference:true, pending_reference:{id:1, portrait_data_url:'data:fixture'}});
        await h.resolveCamera(); await h.resolveRequest('previewEnrollment', frontal());
        h.scope.captureFaceEnrollment();
        await h.advanceClock(1000, async () => {
            for (const preview of h.pending('previewEnrollment')) await h.resolveRequest(preview, frontal());
        });
        const early = h.pending('checkEarlyEnrollmentIdentity')[0];
        assert.ok(early, 'an extra frontal observation must precede the left turn');
        assert.equal(h.scope.faceEnrollmentFrameCount, 1, 'extra observation is not a saved template');
        assert.equal(h.scope.faceEnrollmentRequestedPoseIndex, 0);
        await h.resolveRequest(early, {state:'CONFIRMED_DUPLICATE', conflicting_member_name:'Other child'});
        assert.equal(h.scope.faceEnrollmentState, 'READY');
        assert.equal(h.scope.faceEnrollmentRequestedPoseIndex, 0, 'turn instruction must not appear');
    }
}, false);
