'use strict';

// Exercise the complete controller with the existing fake clock and transport.
// These are lifecycle tests using synthetic pixels, not recognition accuracy tests.
const {assert, setup, face, empty, startEnrollment, runCases} = require('./helpers/face-controller-harness');

function drift() {
    const body = face();
    body.faces[0].quality.inside_guide = false;
    body.faces[0].quality.reasons = ['FACE_TOO_LEFT'];
    body.reason_code = 'FACE_TOO_LEFT';
    return body;
}
function captureRequests(h) {
    return h.pending('previewEnrollment').filter(r => r.enrollmentState === 'CAPTURING');
}
async function replyCapture(h, body = face(), reject = false) {
    const request = captureRequests(h)[0];
    setup(request, 'capture observation must be pending');
    await h.resolveRequest(request, body, reject);
    return request;
}
async function pumpCapture(h, response = drift) {
    for (const request of captureRequests(h)) await h.resolveRequest(request, response());
}
async function startWithTwoFrames(h) {
    await startEnrollment(h);
    h.scope.captureFaceEnrollment();
    const first = await replyCapture(h);
    await h.advanceClock(250);
    const second = await replyCapture(h);
    setup(h.scope.faceEnrollmentFrameCount === 2, 'two frames must already be captured');
    await h.advanceClock(250);
    return [first, second];
}
async function sustainedDrift(h) {
    await replyCapture(h, drift());
    await h.advanceClock(1200, () => pumpCapture(h));
}
async function until(h, predicate, response) {
    for (let i = 0; i < 120 && !predicate(); i++) {
        await h.advanceClock(50, async () => {
            for (const request of h.pending('previewEnrollment')) await h.resolveRequest(request, response());
        });
    }
    assert.ok(predicate(), 'capture must advance automatically without another Capture click');
}

const cases = {
    async guide_drift_has_one_second_grace_without_accepting_bad_frames(h) {
        await startWithTwoFrames(h);
        await replyCapture(h, drift());
        await h.advanceClock(999, () => pumpCapture(h));
        assert.equal(h.scope.faceEnrollmentPaused, false, 'guide drift must not show paused before one continuous second');
        assert.doesNotMatch(h.scope.faceEnrollmentMessage, /paused/i);
        assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'grace must never admit invalid frames');
        await h.advanceClock(201, () => pumpCapture(h));
        assert.equal(h.scope.faceEnrollmentPaused, true, 'sustained drift must show paused');
        assert.match(h.scope.faceEnrollmentPauseReason, /guide|face/i, 'pause must explain how to recover');
        assert.equal(h.scope.faceEnrollmentState, 'CAPTURING');
        assert.equal(h.scope.faceEnrollmentFrameCount, 2);
    },
    async resume_requires_300ms_of_valid_observations(h) {
        await startWithTwoFrames(h);
        await sustainedDrift(h);
        await h.advanceClock(300);
        await replyCapture(h);
        assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'first valid observation must not resume capture immediately');
        await h.advanceClock(299, () => pumpCapture(h, face));
        assert.equal(h.scope.faceEnrollmentPaused, true);
        assert.equal(h.scope.faceEnrollmentFrameCount, 2);
        await h.advanceClock(1, () => pumpCapture(h, face));
        assert.equal(h.scope.faceEnrollmentPaused, false, 'stable valid observations must auto resume');
        assert.equal(h.scope.faceEnrollmentPauseReason, '');
        assert.equal(h.scope.faceEnrollmentFrameCount, 3);
    },
    async invalid_observation_restarts_the_valid_recovery_window(h) {
        await startWithTwoFrames(h);
        await sustainedDrift(h);
        await h.advanceClock(300);
        await replyCapture(h);
        await h.advanceClock(300);
        await replyCapture(h, drift());
        await h.advanceClock(300);
        await replyCapture(h);
        assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'interrupted recovery must keep captured progress unchanged');
        await h.advanceClock(299, () => pumpCapture(h, face));
        assert.equal(h.scope.faceEnrollmentPaused, true);
        await h.advanceClock(1, () => pumpCapture(h, face));
        assert.equal(h.scope.faceEnrollmentPaused, false);
        assert.equal(h.scope.faceEnrollmentFrameCount, 3);
    },
    async valid_observation_breaks_continuous_drift(h) {
        await startWithTwoFrames(h);
        await replyCapture(h, drift());
        await h.advanceClock(600, () => pumpCapture(h));
        await h.advanceClock(300);
        await replyCapture(h);
        await h.advanceClock(300);
        await replyCapture(h, drift());
        await h.advanceClock(999, () => pumpCapture(h));
        assert.equal(h.scope.faceEnrollmentPaused, false, 'separate drift intervals must not accumulate into a pause');
        assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'one valid observation must not be captured during recovery');
    },
    async progress_and_validated_snapshots_survive_drift_in_both_bursts(h) {
        const firstTwo = await startWithTwoFrames(h);
        const progress = h.scope.faceEnrollmentProgressStyle.width;
        await sustainedDrift(h);
        assert.equal(h.scope.faceEnrollmentProgressStyle.width, progress);
        await until(h, () => h.scope.faceEnrollmentState === 'LEAVE_GUIDE', face);
        assert.equal(h.scope.faceEnrollmentFrameCount, 5, 'drift must not change the Burst A boundary');
        await until(h, () => h.scope.faceEnrollmentState === 'CAPTURING', face);
        await until(h, () => h.scope.faceEnrollmentFrameCount === 6, face);
        const firstB = h.requests.filter(r => r.action === 'previewEnrollment' && r.enrollmentState === 'CAPTURING').at(-1);
        await h.advanceClock(250);
        await sustainedDrift(h);
        assert.equal(h.scope.faceEnrollmentFrameCount, 6, 'Burst B progress must survive drift');
        await until(h, () => h.pending('enrollFace').length === 1, face);
        const submission = h.pending('enrollFace')[0].data;
        assert.equal(submission.getAll('burst_a[]').length, 5);
        assert.equal(submission.getAll('burst_b[]').length, 3);
        const frames = submission.getAll('frames[]');
        assert.equal(frames.length, 8);
        for (let index = 0; index < firstTwo.length; index++) {
            assert.equal(await frames[index].text(), await firstTwo[index].data.get('frame').text(), 'previously captured bytes must be retained');
            assert.deepEqual(JSON.parse(submission.get('capture_contexts'))[index], JSON.parse(firstTwo[index].data.get('capture_context')));
        }
        assert.equal(await frames[5].text(), await firstB.data.get('frame').text(), 'the first Burst B snapshot must be retained');
        assert.equal(new Set(await Promise.all(frames.map(frame => frame.text()))).size, 8, 'recovery must not duplicate captured snapshots');
        assert.equal(h.pending('enrollFace').length, 1, 'capture must submit once');
    },
    async delayed_background_failure_cannot_overwrite_capture_guidance(h) {
        await startEnrollment(h);
        await h.advanceClock(500);
        const background = h.pending('previewEnrollment')[0];
        setup(background && background.enrollmentState === 'READY', 'background preview must be in flight');
        h.scope.captureFaceEnrollment();
        await sustainedDrift(h);
        const message = h.scope.faceEnrollmentDetectionMessage;
        await h.resolveRequest(background, null, true);
        assert.equal(h.scope.faceEnrollmentDetectionMessage, message, 'background error must not overwrite capture recovery guidance');
        assert.equal(h.scope.faceEnrollmentPaused, true);
    },
    async cancel_invalidates_recovery_and_late_frames(h) {
        await startWithTwoFrames(h);
        await sustainedDrift(h);
        await h.advanceClock(300);
        const old = captureRequests(h)[0];
        setup(old, 'old recovery observation must be pending');
        h.scope.cancelFaceEnrollment();
        h.scope.startFaceEnrollment(h.scope.list[1]);
        await h.resolveRequest('beginEnrollment', {state: 'READY', capture_session_token: 'second-enrollment',
            has_pending_reference: true, pending_reference: {id: 2, portrait_data_url: 'data:second'}});
        await h.resolveCamera();
        const current = h.pending('previewEnrollment').find(r => r !== old);
        setup(current, 'new student preview must be pending');
        await h.resolveRequest(current, face());
        setup(h.scope.faceEnrollmentState === 'READY', 'new student enrollment must be ready');
        await h.resolveRequest(old, face());
        await h.advanceClock(1000);
        assert.equal(h.scope.faceEnrollmentState, 'READY');
        assert.equal(h.scope.faceEnrollmentMemberName, 'Fixture 102');
        assert.equal(h.scope.faceEnrollmentFrameCount, 0);
        assert.equal(h.scope.faceEnrollmentPaused, false);
        assert.equal(h.pending('enrollFace').length, 0);
    },
    async hidden_page_invalidates_recovery(h) {
        await startWithTwoFrames(h);
        await sustainedDrift(h);
        const count = h.requests.length;
        h.document.hidden = true;
        h.emit('visibilitychange');
        await h.advanceClock(1500);
        assert.equal(h.scope.faceEnrollmentState, 'ERROR');
        assert.equal(h.streams[0].track.readyState, 'ended');
        assert.equal(h.requests.length, count, 'hidden camera must not continue recovery requests');
        assert.equal(h.pending('enrollFace').length, 0);
    }
};

for (const [name, response, reject] of [
    ['detector_outage', null, true],
    ['multiple_faces', {...face(), reason_code: 'MULTIPLE_FACES_IN_GUIDE'}, false],
    ['reference_mismatch', {...face(), reference_match_state: 'MISMATCH'}, false]
]) {
    cases[name + '_does_not_receive_drift_grace'] = async h => {
        await startWithTwoFrames(h);
        await replyCapture(h, response, reject);
        assert.equal(h.scope.faceEnrollmentPaused, true, 'non-drift failure must pause immediately');
        assert.ok(h.scope.faceEnrollmentPauseReason, 'non-drift failure must have a pause reason');
        assert.equal(h.scope.faceEnrollmentFrameCount, 2);
        assert.equal(h.pending('enrollFace').length, 0);
    };
}

runCases(cases);
