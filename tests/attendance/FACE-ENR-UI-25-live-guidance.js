'use strict';
const fs = require('node:fs');
const {assert, face, runCases} = require('./helpers/face-controller-harness');

const capability = {pose_protocol: 'guided-five-select-v1', template_count: 6,
    burst_a_count: 10, burst_b_count: 3, samples_per_pose: 2,
    windows: {centre_yaw_max: .06, centre_pitch_min: .35, centre_pitch_max: .75,
        side_yaw_min: .08, side_yaw_max: .16, pitch_tolerance: .08,
        pitch_delta_min: .05, pitch_delta_max: .10, stable_ms: 300}};
function observation(yaw = 0, inside = true) {
    const body = face(); body.guided_enrollment = capability;
    body.reference_score = .94;
    Object.assign(body.faces[0].quality, {accepted: inside, inside_guide: inside,
        pose_yaw: yaw, pose_pitch: .55, reasons: inside ? [] : ['FACE_TOO_LEFT']});
    return body;
}
async function pump(h, body) {
    for (const request of h.pending('previewEnrollment')) await h.resolveRequest(request, body());
}
async function begin(h) {
    h.scope.startFaceEnrollment(h.scope.list[0]);
    await h.resolveRequest('beginEnrollment', {state: 'READY', capture_session_token: 'guide',
        has_pending_reference: true, pending_reference: {id: 1, portrait_data_url: 'data:fixture'}});
    await h.resolveCamera(); await h.resolveRequest('previewEnrollment', observation());
    h.scope.captureFaceEnrollment();
    await h.advanceClock(900, () => pump(h, observation));
    assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'both centre photos must be accepted first');
}

runCases({
    async recovering_to_wrong_pose_is_waiting_not_paused(h) {
        await begin(h);
        await h.advanceClock(1500, () => pump(h, () => observation(0, false)));
        assert.equal(h.scope.faceEnrollmentPaused, true);
        await h.advanceClock(650, () => pump(h, observation));
        assert.equal(h.scope.faceEnrollmentFrameCount, 2, 'wrong pose cannot advance capture');
        assert.equal(h.scope.faceEnrollmentPaused, false, 'valid face recovery must clear stale pause');
        assert.equal(h.scope.faceEnrollmentPauseReason, '');
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(), /slightly.*right/i);
        assert.equal(h.scope.faceEnrollmentVisualDirection, 'right');
        assert.equal(h.scope.faceEnrollmentDetectionStatusClass, 'is-warning',
            'wrong pose must not make the guide look capture-ready');
    },
    async paused_instruction_explains_blocker(h) {
        await begin(h);
        await h.advanceClock(1500, () => pump(h, () => observation(0, false)));
        assert.equal(h.scope.faceEnrollmentPaused, true);
        assert.match(h.scope.faceEnrollmentPrimaryInstruction(), /move face right|re.center face/i);
        assert.doesNotMatch(h.scope.faceEnrollmentPrimaryInstruction(), /turn your face slightly/i);
    },
    async guide_is_visible_and_feedback_does_not_cover_face(h) {
        const page = fs.readFileSync('pf/attendance.php', 'utf8');
        assert.ok(page.includes('face-pose-visual'));
        assert.ok(page.includes('faceEnrollmentVisualDirection'));
        assert.ok(/\.face-enrollment-card \.face-camera-feedback\s*\{[^}]*position:\s*static/s.test(page));
    },
    async capture_keeps_anonymous_pose_and_reference_diagnostics(h) {
        await begin(h);
        const samples = h.scope.faceEnrollmentDiagnosticSamples;
        assert.ok(Array.isArray(samples) && samples.length >= 1);
        const capture = samples.find(sample => Number.isFinite(sample.yaw) && Number.isFinite(sample.pitch));
        assert.ok(capture,'capture measurements must be recorded beside preview timings');
        assert.equal(capture.reference_score, .94);
        assert.ok(samples.some(sample => sample.phase === 'live_preview' && sample.total_ms === undefined),
            'preview timing record remains present even if the fixture omits timings');
        assert.ok(!('frame' in capture) && !('member_id' in capture));
    }
});
