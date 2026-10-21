'use strict';
const {assert, empty, startScan, runCases} = require('./helpers/face-controller-harness');

runCases({
    async renews_an_idle_scan_before_the_five_minute_expiry(h) {
        await startScan(h);
        h.setEdgePreview(empty);
        await h.advanceClock(210500, async () => {
            for (const request of h.pending('renewFaceScan')) {
                assert.equal(request.data.scan_id, 1);
                assert.equal(request.data.scan_token, 'fixture');
                await h.resolveRequest(request, {state:'RENEWED', scan_id:1, scan_token:'fixture',
                    capture_nonce:'renewed-nonce', expires_in:300});
            }
        });
        assert.equal(h.scope.faceMessage, 'Camera ready. Scan lease renewed.');
    },
});
