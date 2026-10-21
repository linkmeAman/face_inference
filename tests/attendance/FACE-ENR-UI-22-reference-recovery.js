'use strict';
const fs = require('node:fs');
const {assert, setup, face, empty, startEnrollment, runCases} = require('./helpers/face-controller-harness');
const instruction = 'Look straight ahead like the reference photo.';
const mismatch = () => ({...face(), capture_state: 'CONFLICT', reference_match_state: 'MISMATCH'});
async function captureReply(h, body, reject = false) {
    const request = h.pending('previewEnrollment').find(r => r.enrollmentState === 'CAPTURING');
    setup(request, 'capture observation pending');
    await h.resolveRequest(request, body, reject);
}
async function pump(h, response) {
    for (const r of h.pending('previewEnrollment')) await h.resolveRequest(r, response());
}
async function beginCapture(h) {
    await startEnrollment(h);
    h.scope.captureFaceEnrollment();
    await captureReply(h, face());
    await h.advanceClock(250);
}
runCases({
    async reference_thumbnail_uses_selected_frame(h) {
        h.scope.startFaceEnrollment(h.scope.list[0]);
        await h.resolveRequest('beginEnrollment', {state: 'READY', capture_session_token: 'new-ref', has_pending_reference: false});
        await h.resolveCamera();
        await h.resolveRequest('previewEnrollment', face());
        h.scope.captureFaceEnrollment();
        await h.advanceClock(250);
        const request = h.pending('onboardReference')[0];
        setup(request, 'reference burst submitted');
        const frames = request.data.getAll('portraits[]');
        setup(frames.length === 3, 'three reference snapshots');
        await h.resolveRequest(request, {state: 'ACTIVE', reference_id: 22, selected_frame_index: 2});
        const displayed = Buffer.from(h.scope.faceEnrollmentReferencePortraitUrl.split(',')[1], 'base64').toString();
        assert.equal(displayed, await frames[2].text(), 'thumbnail must show the frame selected by inference');
    },
    async mismatch_blocks_immediately_with_one_instruction(h) {
        await beginCapture(h);
        await captureReply(h, mismatch());
        assert.equal(h.scope.faceEnrollmentFrameCount, 1);
        assert.equal(h.scope.faceEnrollmentPaused, true);
        assert.equal(h.scope.faceEnrollmentDetectionMessage, instruction);
        assert.equal(h.scope.faceEnrollmentMessage, instruction);
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, false);
        assert.equal(h.pending('enrollFace').length, 0);
    },
    async three_mismatches_need_one_second_and_fresh_observations(h) {
        await startEnrollment(h);
        await h.advanceClock(500, () => pump(h, mismatch));
        assert.notEqual(h.scope.faceEnrollmentStatusLabel(), 'Ready to capture', 'reference mismatch must not advertise readiness');
        await h.advanceClock(500, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, false, 'two observations cannot enable retake');
        await h.advanceClock(500, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, true, 'three fresh mismatches spanning one second enable retake');
    },
    async three_fast_mismatches_do_not_enable_retake(h) {
        await beginCapture(h);
        await captureReply(h, mismatch());
        await h.advanceClock(600, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, false, 'three mismatches before one second are insufficient');
        await h.advanceClock(600, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, true);
    },
    async detector_failure_and_match_reset_mismatch_run(h) {
        await beginCapture(h);
        await captureReply(h, mismatch());
        await h.advanceClock(300, () => pump(h, mismatch));
        await h.advanceClock(300);
        await captureReply(h, null, true);
        await h.advanceClock(600, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, false, 'outage breaks consecutive mismatch evidence');
        await h.advanceClock(900, () => pump(h, mismatch));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, true);
        await h.advanceClock(300, () => pump(h, face));
        assert.equal(h.scope.faceEnrollmentCanRetakeReference, false, 'matching face clears retake suggestion');
    },
    async retake_discards_progress_and_ignores_old_capture(h) {
        await beginCapture(h);
        await captureReply(h, mismatch());
        await h.advanceClock(1200, () => pump(h, mismatch));
        await h.advanceClock(300);
        const old = h.pending('previewEnrollment')[0];
        setup(old, 'old capture pending');
        h.scope.clearFaceReference();
        assert.equal(h.scope.faceEnrollmentFrameCount, 0, 'retake immediately clears progress');
        assert.equal(h.scope.faceEnrollmentHasReference, false);
        assert.equal(h.scope.faceEnrollmentReferencePortraitUrl, null);
        await h.resolveRequest(old, face());
        assert.equal(h.scope.faceEnrollmentFrameCount, 0);
        assert.equal(h.pending('enrollFace').length, 0);
        const clear = h.pending('clearReference')[0];
        assert.equal(clear.data.reference_id, 1);
        await h.resolveRequest(clear, {state: 'CLEARED'});
        assert.equal(h.pending('beginEnrollment').length, 1, 'new capture session required after retake');
    },
    async late_clear_response_does_not_replace_new_student(h) {
        await startEnrollment(h);
        h.scope.clearFaceReference();
        const old = h.pending('clearReference')[0];
        h.scope.cancelFaceEnrollment();
        await startEnrollment(h, h.scope.list[1]);
        await h.resolveRequest(old, {state: 'CLEARED'});
        assert.equal(h.scope.faceEnrollmentMemberName, 'Fixture 102', 'old clear must not reopen the prior student');
        assert.equal(h.scope.faceEnrollmentState, 'READY');
        assert.equal(h.pending('beginEnrollment').length, 0);
    },
    async enrollment_card_has_one_primary_instruction_and_real_actions(h) {
        const page = fs.readFileSync('pf/attendance.php', 'utf8');
        const card = page.slice(page.indexOf('<div class="row face-scan-card face-enrollment-card"'), page.indexOf('<div class="row" ng-if="faceSetupMode && faceAdminReviewer'));
        assert.doesNotMatch(card, /<button\b[^>]*\sdisabled(?:\s|>)/, 'status must not be a disabled button');
        assert.equal((card.match(/\{\{faceEnrollmentPrimaryInstruction\(\)\}\}/g) || []).length, 1, 'render the primary instruction once');
        assert.match(card, /faceEnrollmentCanRetakeReference[\s\S]*?>Retake reference<\/button>[\s\S]*?>Cancel<\/button>/);
        assert.equal(typeof h.scope.faceEnrollmentPrimaryInstruction, 'function');
        await beginCapture(h);
        await captureReply(h, mismatch());
        assert.equal(h.scope.faceEnrollmentPrimaryInstruction(), instruction);
    }
});
