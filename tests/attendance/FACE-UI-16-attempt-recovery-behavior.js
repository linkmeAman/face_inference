'use strict';
const {assert, setup, face, empty, acceptFirst, pumpScan, runCases} = require('./helpers/face-controller-harness');
async function nextAttempt(h) {
    await acceptFirst(h);
    await h.advanceClock(1600, () => pumpScan(h, empty));
    await h.advanceClock(1400, () => pumpScan(h, face));
    setup(h.pending('recognizeFaceAttempt').length === 1, 'second attempt pending');
}
const cases = {};
for (const reason of ['INFERENCE_BUSY', 'INFERENCE_OFFLINE', 'INFERENCE_TIMEOUT']) {
    cases['temporary_failure_preserves_selection_' + reason] = async h => {
        await nextAttempt(h);
        await h.resolveRequest('recognizeFaceAttempt', {state: 'REJECTED', reason,
            retryable: true, retry_after_ms: 1000, recovery_action: 'CHECK_STATUS'});
        assert.equal(h.scope.list[0].selected, true, 'temporary failure must preserve accepted selection');
        assert.equal(h.requests.filter(r => r.action === 'cancelFaceScan').length, 0);
    };
}
cases.lost_response_checks_status_first = async h => {
    await nextAttempt(h);
    const before = h.requests.length;
    await h.resolveRequest('recognizeFaceAttempt', null, true);
    await h.advanceClock(1000, () => pumpScan(h));
    const actions = h.requests.slice(before).filter(r => ['getFaceScanStatus', 'recognizeFaceAttempt', 'cancelFaceScan', 'getCaptureNonce'].includes(r.action));
    assert.equal(actions[0]?.action, 'getFaceScanStatus', 'lost response must check attempt status before recapture or cancel');
};
cases.bounded_retry_and_cancel = async h => {
    await nextAttempt(h);
    await h.resolveRequest('recognizeFaceAttempt', null, true);
    const start = h.now(), observed = [];
    await h.advanceClock(7500, async () => {
        for (const r of h.pending('getFaceScanStatus')) { observed.push(r.at - start); await h.resolveRequest(r, null, true); }
    });
    assert.deepEqual(observed, [1000, 3000, 7000], 'status recovery must use bounded 1s, 2s, 4s backoff');
    h.scope.cancelFaceScan(); await h.flush(); const count = h.requests.length;
    await h.advanceClock(20000);
    assert.equal(h.requests.length, count, 'cancel must remove recovery timers');
};
cases.cancel_ignores_late_attempt = async h => {
    await nextAttempt(h); const old = h.pending('recognizeFaceAttempt')[0];
    h.scope.cancelFaceScan(); await h.flush();
    await h.resolveRequest(old, {state: 'RECORDED', decision: 'ACCEPT', effect: 'SELECT_PRESENT', contact_id: 1102, member_id: 102, liveness: 'LIVE'});
    assert.equal(h.scope.list[1].selected, false);
};
runCases(cases);
