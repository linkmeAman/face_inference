'use strict';
const {assert, face, empty, unavailable, acceptFirst, pumpScan, runCases} = require('./helpers/face-controller-harness');
const attempts = h => h.requests.filter(r => r.action === 'recognizeFaceAttempt').length;
runCases({
    async same_person_never_rearms_by_timeout(h) {
        await acceptFirst(h); await h.advanceClock(6000, () => pumpScan(h));
        assert.equal(attempts(h), 1, 'continuous presence must not rearm recognition');
    },
    async departure_requires_750ms(h) {
        await acceptFirst(h);
        // The detector observes one empty frame for <750ms, then the same face.
        await h.advanceClock(600, () => pumpScan(h, empty));
        await h.advanceClock(1200, () => pumpScan(h, face));
        assert.equal(attempts(h), 1, 'short empty interval must not rearm recognition');
        await h.advanceClock(1600, () => pumpScan(h, empty));
        await h.advanceClock(1400, () => pumpScan(h, face));
        assert.equal(attempts(h), 2, 'sustained departure followed by one face must rearm once');
    },
    async preview_failure_is_not_absence(h) {
        await acceptFirst(h); await h.advanceClock(6000, () => pumpScan(h, unavailable));
        assert.equal(attempts(h), 1);
    }
});
