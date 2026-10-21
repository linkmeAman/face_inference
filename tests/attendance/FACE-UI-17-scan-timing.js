'use strict';
const {assert, face, empty, startScan, pumpScan, runCases} = require('./helpers/face-controller-harness');

runCases({
    async recorded_attempt_has_anonymous_stage_timings(h) {
        await startScan(h);
        await h.advanceClock(1000, () => pumpScan(h));
        const request = h.pending('recognizeFaceAttempt')[0];
        assert.ok(request);
        await h.resolveRequest(request, {state: 'RECORDED', decision: 'REVIEW', latency_ms: 240,
            stage: 'MATCHING', reason_code: 'LOW_CONFIDENCE'});
        assert.equal(h.scope.faceScanTimingSamples.length, 1);
        const sample = h.scope.faceScanTimingSamples[0];
        for (const key of ['total_ms', 'nonce_ms', 'capture_ms', 'request_ms', 'inference_ms']) {
            assert.ok(Number.isFinite(sample[key]) && sample[key] >= 0, key + ' must be measured');
        }
        assert.equal(sample.inference_ms, 240);
        assert.ok(!('member_id' in sample) && !('frames' in sample) && !('scan_id' in sample),
            'timing sample must contain no identity or image data');
    },
    async fresh_face_after_real_departure_starts_without_extra_delay(h) {
        await startScan(h);
        await h.advanceClock(1000, () => pumpScan(h));
        await h.resolveRequest('recognizeFaceAttempt', {state: 'RECORDED', decision: 'ACCEPT',
            effect: 'SELECT_PRESENT', member_id: 101, contact_id: 1101, member_name: 'Fixture 101',
            liveness: 'LIVE', next_capture_nonce: 'next'});
        await h.advanceClock(1600, () => pumpScan(h, empty));
        const departureAt = h.now();
        await h.advanceClock(1200, async () => {
            await pumpScan(h, face);
            for (const request of h.pending()) {
                if (request.action === 'getCaptureNonce') {
                    await h.resolveRequest(request, {state: 'READY', capture_nonce: 'next'});
                }
            }
        });
        const nextNonce = h.requests.filter(r => r.action === 'getCaptureNonce').at(-1);
        assert.ok(nextNonce && nextNonce.at >= departureAt && nextNonce.at - departureAt <= 1200,
            'fresh face should start nonce request promptly after the edge observer verifies departure');
    }
});
