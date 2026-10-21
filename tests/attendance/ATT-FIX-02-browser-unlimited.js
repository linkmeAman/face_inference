#!/usr/bin/env node
/* ATT-FIX-02 browser roster contract, created before the production fix. */

let attendanceEligibility;
try {
    attendanceEligibility = require('../../pf/js/controllers/attendanceEligibility.js');
} catch (error) {
    console.error(`FAIL ATT-FIX-02 browser unlimited roster: ${error.message} (expected pre-change baseline)`);
    process.exit(1);
}

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(
    attendanceEligibility.hasRemainingSessions({ sessions: '0', sessions_used: '0' }),
    'sessions = 0 must remain eligible as unlimited'
);
assert(
    attendanceEligibility.hasRemainingSessions({ sessions: '3', sessions_used: '2' }),
    'finite membership with remaining sessions must remain eligible'
);
assert(
    !attendanceEligibility.hasRemainingSessions({ sessions: '3', sessions_used: '3' }),
    'exhausted finite membership must be excluded'
);
assert(
    !attendanceEligibility.hasRemainingSessions({ sessions: '3', sessions_used: '4' }),
    'over-consumed finite membership must be excluded'
);

console.log('PASS ATT-FIX-02 browser unlimited roster contract');
