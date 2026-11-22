import test from 'node:test';
import assert from 'node:assert/strict';
import { FaceCaptureElement } from '../src/face-capture.js';

function createMockDom() {
  function makeEl(tag, classes = [], attrs = {}) {
    return {
      tagName: tag.toUpperCase(),
      className: classes.join(' '),
      classList: {
        _classes: new Set(classes),
        add(c) { this._classes.add(c); },
        remove(c) { this._classes.delete(c); },
        contains(c) { return this._classes.has(c); },
      },
      attributes: { ...attrs },
      getAttribute(k) { return this.attributes[k] ?? null; },
      setAttribute(k, v) { this.attributes[k] = String(v); },
      removeAttribute(k) { delete this.attributes[k]; },
      hasAttribute(k) { return k in this.attributes; },
      textContent: '',
      value: 0,
      checked: false,
      disabled: false,
      hidden: false,
      listeners: {},
      addEventListener(type, fn) {
        (this.listeners[type] = this.listeners[type] || []).push(fn);
      },
      dispatchEvent(evt) {
        const handlers = this.listeners[evt.type] || [];
        for (const h of handlers) h(evt);
        return true;
      },
      click() {
        if (!this.disabled) {
          this.dispatchEvent({ type: 'click' });
        }
      },
    };
  }

  const container = makeEl('div', ['container']);
  const video = makeEl('video');
  const canvas = makeEl('canvas');
  const statusText = makeEl('div', ['status-text']);
  const progress = makeEl('progress');
  const consentPanel = makeEl('div', ['consent-panel']);
  const consentCheckbox = makeEl('input', ['consent-checkbox']);
  const consentSpan = makeEl('span');
  const btnStart = makeEl('button', ['btn-start']);
  const btnStop = makeEl('button', ['btn-stop']);
  const btnRetry = makeEl('button', ['btn-retry']);
  btnRetry.hidden = true;

  const shadowRoot = {
    querySelector(selector) {
      if (selector === '.container') return container;
      if (selector === 'video') return video;
      if (selector === 'canvas') return canvas;
      if (selector === '.status-text') return statusText;
      if (selector === 'progress') return progress;
      if (selector === '.consent-panel') return consentPanel;
      if (selector === '.consent-checkbox') return consentCheckbox;
      if (selector === '.consent-label span' || selector === '.consent-panel span') return consentSpan;
      if (selector === '.btn-start') return btnStart;
      if (selector === '.btn-stop') return btnStop;
      if (selector === '.btn-retry') return btnRetry;
      return null;
    },
    querySelectorAll() { return []; },
  };

  return {
    shadowRoot,
    container,
    video,
    canvas,
    statusText,
    progress,
    consentPanel,
    consentCheckbox,
    consentSpan,
    btnStart,
    btnStop,
    btnRetry,
  };
}

class FakeFaceClient {
  constructor() {
    this.pending = null;
    this.sendCalls = [];
    this.retryCalls = 0;
    this.nextSendError = null;
    this.nextRetryError = null;
    this.nextResult = { state: 'MATCHED', person_id: 'p1', person_name: 'Person One' };
  }

  async send(path, body) {
    this.sendCalls.push({ path, body });
    const idempotency_key = body.idempotency_key || 'mock-idempotency-key';
    const request = { path, body: { ...body, idempotency_key } };
    this.pending = request;
    if (this.nextSendError) {
      const err = this.nextSendError;
      if (err.confirmed) {
        this.pending = null;
      }
      throw err;
    }
    this.pending = null;
    return this.nextResult;
  }

  async retry() {
    this.retryCalls++;
    if (!this.pending) throw new Error('No unresolved request');
    if (this.nextRetryError) {
      const err = this.nextRetryError;
      if (err.confirmed) {
        this.pending = null;
      }
      throw err;
    }
    const res = this.nextResult;
    this.pending = null;
    return res;
  }
}

class FakeCapture {
  constructor(video, canvas, config, callbacks) {
    this.video = video;
    this.canvas = canvas;
    this.config = config;
    this.callbacks = callbacks;
    this.active = true;
    this.backendPending = false;
    this.resumeCalled = 0;
    this.stopCalled = 0;
    this.waitForDeparture = false;
  }

  async start() {
    this.callbacks.running?.();
  }

  resumeAfterRequest() {
    this.resumeCalled++;
    this.backendPending = false;
    return true;
  }

  stop() {
    this.stopCalled++;
    this.active = false;
  }
}

test('Retry: retry() method is defined and fails if no pending request', async () => {
  const el = new FaceCaptureElement();
  assert.equal(typeof el.retry, 'function');
  assert.equal(el.hasPendingSave, false);

  await assert.rejects(
    async () => { await el.retry(); },
    /no unresolved request/i
  );
});

test('Retry: uncertain failure retains pending request and reveals retry button', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  const uncertainError = new Error('Connection interrupted');
  uncertainError.confirmed = false;
  uncertainError.code = 'CONNECTION_INTERRUPTED';
  client.nextSendError = uncertainError;
  el._client = client;

  const events = [];
  el.dispatchEvent = (evt) => {
    events.push({ type: evt.type, detail: evt.detail });
    return true;
  };

  const fakeCapture = new FakeCapture(null, null, {}, {});
  el._capture = fakeCapture;

  // Simulate a batch execution that fails with uncertain error
  const batchFn = async () => {
    try {
      await client.send('/api/v1/collections/col/recognitions', {
        capture_session: 's-123',
        observations: [1, 2, 3],
        idempotency_key: 'idem-key-abc',
      });
    } catch (err) {
      el._setStatus(err.message, true);
      el.dispatchEvent(new CustomEvent('face-error', {
        bubbles: true,
        composed: true,
        detail: { code: err.code || 'REQUEST_FAILED', message: err.message },
      }));
      if (client.pending) {
        if (el._btnRetry) {
          el._btnRetry.hidden = false;
          el._btnRetry.disabled = false;
        }
        return { pending: true };
      }
      return false;
    }
  };

  const batchResult = await batchFn();
  assert.deepEqual(batchResult, { pending: true });
  assert.equal(el.hasPendingSave, true);
  assert.equal(mock.btnRetry.hidden, false);
  assert.equal(mock.btnRetry.disabled, false);
  assert.equal(client.pending.body.idempotency_key, 'idem-key-abc');
  assert.equal(events.length, 1);
  assert.equal(events[0].type, 'face-error');
});

test('Retry: successful retry dispatches face-result, clears pending, resumes capture', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/recognitions',
    body: { capture_session: 'sess-1', observations: [], idempotency_key: 'idem-preserve-123' },
  };
  client.nextResult = { state: 'MATCHED', person_id: 'alice', person_name: 'Alice' };
  el._client = client;
  mock.btnRetry.hidden = false;

  const fakeCapture = new FakeCapture(null, null, {}, {});
  fakeCapture.backendPending = true;
  el._capture = fakeCapture;
  el.mode = 'recognize';

  const events = [];
  el.dispatchEvent = (evt) => {
    events.push({ type: evt.type, detail: evt.detail });
    return true;
  };

  const result = await el.retry();

  assert.deepEqual(result, client.nextResult);
  assert.equal(el.hasPendingSave, false);
  assert.equal(mock.btnRetry.hidden, true);
  assert.equal(client.retryCalls, 1);
  assert.equal(fakeCapture.resumeCalled, 1);
  assert.equal(fakeCapture.waitForDeparture, true);
  assert.equal(fakeCapture.backendPending, false);
  assert.equal(events.length, 1);
  assert.equal(events[0].type, 'face-result');
  assert.equal(events[0].detail.state, 'MATCHED');
});

test('Retry: repeated clicks reuse in-flight retry promise without duplicate requests', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/recognitions',
    body: { capture_session: 'sess-1', observations: [], idempotency_key: 'idem-1' },
  };
  el._client = client;
  mock.btnRetry.hidden = false;

  let resolveRetry;
  const slowRetryPromise = new Promise((resolve) => { resolveRetry = resolve; });
  client.retry = async () => {
    client.retryCalls++;
    return slowRetryPromise;
  };

  const retry1 = el.retry();
  const retry2 = el.retry(); // Concurrent call

  assert.equal(client.retryCalls, 1, 'Only one retry should be sent to client');
  assert.equal(mock.btnRetry.disabled, true, 'Retry button should be disabled during flight');

  resolveRetry({ state: 'ACTIVE' });
  const [res1, res2] = await Promise.all([retry1, retry2]);

  assert.deepEqual(res1, res2);
  assert.equal(mock.btnRetry.hidden, true);
});

test('Retry: unresolved pending save blocks start() and does not discard pending save', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/enrollments',
    body: { person_id: 'alice', idempotency_key: 'pending-key-999' },
  };
  el._client = client;

  const errors = [];
  el.dispatchEvent = (evt) => {
    if (evt.type === 'face-error') errors.push(evt.detail);
    return true;
  };

  await el.start();

  assert.equal(errors.length, 1);
  assert.equal(errors[0].code, 'PENDING_SAVE_UNRESOLVED');
  assert.match(errors[0].message, /retry/i);
  // Ensure the pending save was NOT discarded
  assert.equal(el.hasPendingSave, true);
  assert.equal(client.pending.body.idempotency_key, 'pending-key-999');
});

test('Retry: stop() does not discard pending save', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/recognitions',
    body: { idempotency_key: 'preserve-me' },
  };
  el._client = client;
  mock.btnRetry.hidden = false;

  el.stop();

  assert.equal(el.hasPendingSave, true);
  assert.equal(client.pending.body.idempotency_key, 'preserve-me');
  assert.equal(mock.btnRetry.hidden, false);
});

test('Retry: uncertain retry failure retains pending save and enables subsequent retry', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/recognitions',
    body: { idempotency_key: 'keep-on-retry-fail' },
  };
  const err = new Error('Retry connection timed out');
  err.confirmed = false;
  client.nextRetryError = err;
  el._client = client;
  mock.btnRetry.hidden = false;

  await assert.rejects(
    async () => { await el.retry(); },
    /retry connection timed out/i
  );

  assert.equal(el.hasPendingSave, true);
  assert.equal(mock.btnRetry.hidden, false);
  assert.equal(mock.btnRetry.disabled, false);
  assert.equal(client.pending.body.idempotency_key, 'keep-on-retry-fail');
});

test('Retry: confirmed failure on retry clears pending save and hides retry button', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/recognitions',
    body: { idempotency_key: 'confirmed-fail-key' },
  };
  const err = new Error('Conflict: duplicate observation');
  err.confirmed = true;
  err.code = 'CONFLICT';
  client.nextRetryError = err;
  el._client = client;
  mock.btnRetry.hidden = false;

  const errors = [];
  el.dispatchEvent = (evt) => {
    if (evt.type === 'face-error') errors.push(evt.detail);
    return true;
  };

  await assert.rejects(
    async () => { await el.retry(); },
    /conflict/i
  );

  assert.equal(el.hasPendingSave, false);
  assert.equal(mock.btnRetry.hidden, true);
  assert.equal(errors.length, 1);
  assert.equal(errors[0].code, 'CONFLICT');
});

test('Retry: retry succeeds after stop() and delivers result', async () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const client = new FakeFaceClient();
  client.pending = {
    path: '/api/v1/collections/test/enrollments',
    body: { person_id: 'alice', idempotency_key: 'post-stop-key' },
  };
  client.nextResult = { state: 'ACTIVE', person_id: 'alice' };
  el._client = client;
  el.mode = 'enroll';

  el.stop();
  assert.equal(el.hasPendingSave, true);

  const results = [];
  el.dispatchEvent = (evt) => {
    if (evt.type === 'face-result') results.push(evt.detail);
    return true;
  };

  const res = await el.retry();
  assert.equal(res.state, 'ACTIVE');
  assert.equal(el.hasPendingSave, false);
  assert.equal(results.length, 1);
  assert.equal(results[0].state, 'ACTIVE');
});
