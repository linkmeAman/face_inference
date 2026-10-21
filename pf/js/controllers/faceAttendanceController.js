(function (root, factory) {
    if (typeof module === 'object' && module.exports) {
        module.exports = { FaceAttendanceStateMachine: factory() };
    } else {
        root.FaceAttendanceStateMachine = factory();
    }
}(typeof self !== 'undefined' ? self : this, function () {
    'use strict';

    var transitions = {
        IDLE: ['STARTING_CAMERA'],
        STARTING_CAMERA: ['SCANNING', 'ERROR', 'CANCELLED'],
        SCANNING: ['CAPTURING', 'RECONCILING', 'CANCELLED', 'ERROR'],
        CAPTURING: ['PROCESSING', 'SCANNING', 'ERROR', 'CANCELLED'],
        PROCESSING: ['MATCHED', 'CHALLENGE', 'REVIEW', 'REJECTED', 'ERROR', 'SCANNING', 'CANCELLED'],
        MATCHED: ['SCANNING', 'RECONCILING', 'COMPLETED', 'CANCELLED'],
        CHALLENGE: ['CAPTURING', 'SCANNING', 'MANUAL', 'CANCELLED', 'ERROR'],
        REVIEW: ['SCANNING', 'MANUAL', 'CAPTURING', 'RECONCILING', 'CANCELLED'],
        REJECTED: ['SCANNING', 'MANUAL', 'CAPTURING', 'CANCELLED'],
        MANUAL: ['SCANNING', 'RECONCILING', 'CANCELLED'],
        ERROR: ['SCANNING', 'MANUAL', 'CANCELLED'],
        RECONCILING: ['COMPLETED', 'MANUAL', 'CANCELLED'],
        COMPLETED: ['STARTING_CAMERA'],
        CANCELLED: ['STARTING_CAMERA']
    };

    function FaceAttendanceStateMachine(hooks) {
        this.hooks = hooks || {};
        this.state = 'IDLE';
        this.inFlight = false;
    }

    FaceAttendanceStateMachine.prototype.transition = function (next, payload) {
        if ((transitions[this.state] || []).indexOf(next) === -1) {
            throw new Error('Invalid face attendance transition: ' + this.state + ' -> ' + next);
        }
        this.state = next;
        if (this.hooks.onStateChange) this.hooks.onStateChange(next, payload || {});
        if (next === 'CANCELLED' || next === 'COMPLETED') {
            this.inFlight = false;
            if (this.hooks.onStopCamera) this.hooks.onStopCamera();
        }
        return this.state;
    };

    FaceAttendanceStateMachine.prototype.start = function () { return this.transition('STARTING_CAMERA'); };
    FaceAttendanceStateMachine.prototype.restart = function () {
        if (this.state !== 'IDLE' && this.state !== 'COMPLETED' && this.state !== 'CANCELLED') {
            this.cancel();
        }
        return this.start();
    };
    FaceAttendanceStateMachine.prototype.cameraReady = function () { return this.transition('SCANNING'); };
    FaceAttendanceStateMachine.prototype.captureStarted = function () {
        if (this.inFlight) throw new Error('A face attempt is already processing');
        this.inFlight = true;
        return this.transition('CAPTURING');
    };
    FaceAttendanceStateMachine.prototype.framesCaptured = function () { return this.transition('PROCESSING'); };
    FaceAttendanceStateMachine.prototype.result = function (result) {
        this.inFlight = false;
        var decision = result && result.decision;
        if (result && result.liveness === 'CHALLENGE' && result.challenge) return this.transition('CHALLENGE', result);
        if (decision === 'ACCEPT') return this.transition('MATCHED', result);
        if (decision === 'REVIEW') return this.transition('REVIEW', result);
        if (decision === 'REJECT') return this.transition('REJECTED', result);
        return this.transition('ERROR', { reason: 'INVALID_RESULT' });
    };
    FaceAttendanceStateMachine.prototype.manualFallback = function () { this.inFlight = false; return this.transition('MANUAL'); };
    FaceAttendanceStateMachine.prototype.resume = function () { this.inFlight = false; return this.transition('SCANNING'); };
    FaceAttendanceStateMachine.prototype.reconcile = function () { return this.transition('RECONCILING'); };
    FaceAttendanceStateMachine.prototype.complete = function () { return this.transition('COMPLETED'); };
    FaceAttendanceStateMachine.prototype.cancel = function () { return this.transition('CANCELLED'); };
    FaceAttendanceStateMachine.prototype.error = function (reason, message) {
        this.inFlight = false;
        if (this.hooks.onError) this.hooks.onError(reason, message || '');
        return this.transition('ERROR', { reason: reason, message: message || '' });
    };

    FaceAttendanceStateMachine.prototype.canCapture = function () {
        return (this.state === 'SCANNING' || this.state === 'CHALLENGE') && !this.inFlight;
    };

    return FaceAttendanceStateMachine;
}));
