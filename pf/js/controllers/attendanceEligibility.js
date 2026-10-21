(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    } else {
        root.attendanceEligibility = factory();
    }
}(typeof window !== 'undefined' ? window : this, function () {
    function hasRemainingSessions(item) {
        var sessions = parseInt(item && item.sessions, 10);
        var sessionsUsed = parseInt(item && item.sessions_used, 10);

        // The attendance contract defines sessions = 0 as unlimited.
        return sessions === 0 || sessions > sessionsUsed;
    }

    return {
        hasRemainingSessions: hasRemainingSessions
    };
}));
