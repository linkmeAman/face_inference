import {test, describe, beforeEach} from 'node:test';
import assert from 'node:assert/strict';
import {initApp} from '../../src/face_inference/web/app.mjs';

class MockElement {
  constructor(tag, id = '') {
    this.tagName = tag.toUpperCase();
    this.id = id;
    this.textContent = '';
    this._value = '';
    this.checked = false;
    this.hidden = false;
    this.disabled = false;
    this.type = '';
    this.className = '';
    this.children = [];
    this.listeners = {};
    this.srcObject = null;
  }
  get value() {
    return this._value;
  }
  set value(v) {
    this._value = typeof v === 'number' ? v : String(v ?? '');
  }
  get selectedOptions() {
    const selected = this.children.filter(c => c.value === this._value);
    return selected.length > 0 ? selected : (this.children.length > 0 ? [this.children[0]] : [{ textContent: '' }]);
  }
  append(...nodes) {
    for (const node of nodes) {
      this.children.push(node);
    }
  }
  replaceChildren(...nodes) {
    this.children = [];
    this.append(...nodes);
  }
  addEventListener(event, fn) {
    this.listeners[event] = this.listeners[event] || [];
    this.listeners[event].push(fn);
  }
  async click() {
    for (const fn of this.listeners['click'] || []) {
      await fn({ type: 'click', target: this });
    }
  }
  async dispatchEvent(evt) {
    for (const fn of this.listeners[evt.type] || []) {
      await fn(evt);
    }
  }
}

class MockDocument {
  constructor() {
    this.elements = new Map();
    this.hidden = false;
    this.listeners = {};
    const ids = [
      'token', 'connect', 'connection', 'workspace', 'notice',
      'collection', 'collection-id', 'collection-name', 'add-collection', 'refresh',
      'recognize', 'person-id', 'person-name', 'add-person', 'consent',
      'people', 'empty', 'camera-panel', 'camera-title', 'switch-camera',
      'stop-camera', 'video', 'overlay', 'progress', 'camera-status', 'result', 'retry'
    ];
    for (const id of ids) {
      const tag = id === 'people' ? 'tbody' : id === 'video' ? 'video' : id === 'overlay' ? 'canvas' : id === 'collection' ? 'select' : id === 'progress' ? 'progress' : 'div';
      const el = new MockElement(tag, id);
      if (['workspace', 'notice', 'camera-panel', 'retry'].includes(id)) {
        el.hidden = true;
      }
      this.elements.set(id, el);
    }
    const video = this.elements.get('video');
    video.srcObject = {
      getVideoTracks: () => [{
        getSettings: () => ({
          deviceId: 'camera-front-123',
          width: 1280,
          height: 720,
          facingMode: 'user'
        })
      }]
    };
  }
  getElementById(id) {
    return this.elements.get(id) || null;
  }
  createElement(tag) {
    return new MockElement(tag);
  }
  addEventListener(type, fn) {
    this.listeners[type] = this.listeners[type] || [];
    this.listeners[type].push(fn);
  }
  async dispatchEvent(evt) {
    for (const fn of this.listeners[evt.type] || []) {
      await fn(evt);
    }
  }
}

class MockWindow {
  constructor() {
    this.listeners = {};
    this.dispatchedEvents = [];
    this.confirmAnswer = true;
  }
  addEventListener(type, fn) {
    this.listeners[type] = this.listeners[type] || [];
    this.listeners[type].push(fn);
  }
  dispatchEvent(evt) {
    this.dispatchedEvents.push(evt);
    for (const fn of this.listeners[evt.type] || []) {
      fn(evt);
    }
  }
  confirm(msg) {
    this.lastConfirmPrompt = msg;
    return this.confirmAnswer;
  }
}

class FakeFaceClient {
  constructor({ token } = {}) {
    this.token = token;
    this.pending = null;
    this.calls = [];
    this.configResponse = {
      contract: { model_version: 'v1.0', preprocessing_version: 'v1.0' },
      approved: true,
      mode: 'RECOGNIZE'
    };
    this.collectionsResponse = [{ id: 'team-1', name: 'Team One' }];
    this.peopleResponse = [{ id: 'p-1', name: 'Alice', state: 'ACTIVE' }];
    this.sendResponse = { state: 'ACTIVE' };
    this.sendError = null;
    this.setPendingOnError = false;
    FakeFaceClient.lastInstance = this;
  }
  async get(path) {
    this.calls.push({ method: 'GET', path });
    if (path === '/api/v1/config') return this.configResponse;
    if (path === '/api/v1/collections') return this.collectionsResponse;
    if (path.startsWith('/api/v1/collections/') && path.endsWith('/people')) {
      if (this.peopleError) throw this.peopleError;
      return this.peopleResponse;
    }
    throw new Error('Unexpected GET: ' + path);
  }
  async send(path, body, method = 'POST') {
    this.calls.push({ method, path, body });
    if (this.sendError) {
      if (this.setPendingOnError) {
        this.pending = { path, body, method };
      }
      throw this.sendError;
    }
    return this.sendResponse;
  }
  async retry() {
    this.calls.push({ method: 'RETRY', request: this.pending });
    const req = this.pending;
    this.pending = null;
    return this.retryResponse || { state: 'ACTIVE', person_name: 'Alice' };
  }
}

class FakeAutomaticCapture {
  constructor(video, overlay, contract, callbacks) {
    this.video = video;
    this.overlay = overlay;
    this.contract = contract;
    this.callbacks = callbacks;
    this.active = false;
    this.mode = null;
    this.canSwitch = true;
    this.switched = false;
    this.hidden = false;
    FakeAutomaticCapture.lastInstance = this;
  }
  async start(mode) {
    this.active = true;
    this.mode = mode;
  }
  stop() {
    this.active = false;
  }
  async switchCamera() {
    this.switched = true;
  }
  setVisibility(hidden) {
    this.hidden = hidden;
  }
}

describe('Face workspace app.mjs', () => {
  let doc, win, app;

  beforeEach(() => {
    doc = new MockDocument();
    win = new MockWindow();
    FakeFaceClient.lastInstance = null;
    FakeAutomaticCapture.lastInstance = null;
    app = initApp({
      doc,
      win,
      FaceClient: FakeFaceClient,
      AutomaticCapture: FakeAutomaticCapture
    });
  });

  test('connection: requires token, displays error when token is empty', async () => {
    doc.getElementById('token').value = '   ';
    await doc.getElementById('connect').click();
    assert.equal(doc.getElementById('result').textContent, 'Enter your private API token');
    assert.equal(doc.getElementById('connection').hidden, false);
    assert.equal(doc.getElementById('workspace').hidden, true);
  });

  test('connection: connects with token, updates UI notice based on policy, loads collections and people', async () => {
    doc.getElementById('token').value = 'secret-tok';
    await doc.getElementById('connect').click();

    const client = FakeFaceClient.lastInstance;
    assert.ok(client);
    assert.equal(client.token, 'secret-tok');
    assert.equal(doc.getElementById('token').value, '');
    assert.equal(doc.getElementById('connection').hidden, true);
    assert.equal(doc.getElementById('workspace').hidden, false);
    assert.equal(doc.getElementById('notice').hidden, false);
    assert.equal(doc.getElementById('notice').textContent, 'Approved matching policy loaded. Use this workspace only for the policy’s validated scope.');
    assert.equal(doc.getElementById('result').textContent, 'Connected.');
    assert.equal(doc.getElementById('collection').value, 'team-1');
    assert.equal(doc.getElementById('recognize').disabled, false);

    // Verify people table rows rendered
    const tbody = doc.getElementById('people');
    assert.equal(tbody.children.length, 1);
    const row = tbody.children[0];
    assert.equal(row.children[0].textContent, 'Alice');
    assert.equal(row.children[1].textContent, 'p-1');
    assert.equal(row.children[2].textContent, 'ACTIVE');
    const actionsCell = row.children[3];
    assert.equal(actionsCell.children.length, 3);
    assert.equal(actionsCell.children[0].textContent, 'Enroll');
    assert.equal(actionsCell.children[1].textContent, 'Check face');
    assert.equal(actionsCell.children[2].textContent, 'Delete face');
  });

  test('connection: notice reflects COLLECT mode and unapproved evaluation mode', async () => {
    // Test COLLECT mode
    const collectDoc = new MockDocument();
    class CollectClient extends FakeFaceClient {
      constructor(opts) {
        super(opts);
        this.configResponse = { contract: {}, approved: false, mode: 'COLLECT' };
      }
    }
    initApp({ doc: collectDoc, win, FaceClient: CollectClient, AutomaticCapture: FakeAutomaticCapture });
    collectDoc.getElementById('token').value = 'tok';
    await collectDoc.getElementById('connect').click();
    assert.equal(collectDoc.getElementById('notice').textContent, 'Collection mode: faces are saved; recognition is unavailable.');

    // Test evaluation mode
    const evalDoc = new MockDocument();
    class EvalClient extends FakeFaceClient {
      constructor(opts) {
        super(opts);
        this.configResponse = { contract: {}, approved: false, mode: 'EVAL' };
      }
    }
    initApp({ doc: evalDoc, win, FaceClient: EvalClient, AutomaticCapture: FakeAutomaticCapture });
    evalDoc.getElementById('token').value = 'tok';
    await evalDoc.getElementById('connect').click();
    assert.equal(evalDoc.getElementById('notice').textContent, 'Evaluation mode: recognition suggestions are provisional and unverified. They do not establish liveness or trigger actions.');
  });

  test('consent guard: enrollment/observation throws error when consent checkbox is unchecked', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();

    doc.getElementById('consent').checked = false;
    const enrollBtn = doc.getElementById('people').children[0].children[3].children[0];
    await enrollBtn.click();

    assert.equal(doc.getElementById('result').textContent, 'Confirm consent for the selected person before capture');
    assert.equal(doc.getElementById('camera-panel').hidden, true);
    assert.equal(FakeAutomaticCapture.lastInstance, null);
  });

  test('collection and person actions: add collection, add person, delete face', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    const client = FakeFaceClient.lastInstance;

    // Add collection
    doc.getElementById('collection-id').value = 'team-2';
    doc.getElementById('collection-name').value = 'Team Two';
    client.collectionsResponse = [
      { id: 'team-1', name: 'Team One' },
      { id: 'team-2', name: 'Team Two' }
    ];
    await doc.getElementById('add-collection').click();
    assert.equal(doc.getElementById('result').textContent, 'Collection created.');
    assert.equal(doc.getElementById('collection').value, 'team-2');

    // Add person
    doc.getElementById('person-id').value = 'p-2';
    doc.getElementById('person-name').value = 'Bob';
    client.peopleResponse = [
      { id: 'p-1', name: 'Alice', state: 'ACTIVE' },
      { id: 'p-2', name: 'Bob', state: 'PENDING' }
    ];
    await doc.getElementById('add-person').click();
    assert.equal(doc.getElementById('result').textContent, 'Person saved.');
    assert.equal(doc.getElementById('people').children.length, 2);

    // Delete face (canceled)
    win.confirmAnswer = false;
    const deleteBtn = doc.getElementById('people').children[0].children[3].children[2];
    await deleteBtn.click();
    assert.equal(win.lastConfirmPrompt, 'Delete the saved face and capture evidence for Alice?');
    const deleteCallsBefore = client.calls.filter(c => c.method === 'DELETE');
    assert.equal(deleteCallsBefore.length, 0);

    // Delete face (confirmed)
    win.confirmAnswer = true;
    await deleteBtn.click();
    const deleteCallsAfter = client.calls.filter(c => c.method === 'DELETE');
    assert.equal(deleteCallsAfter.length, 1);
    assert.equal(deleteCallsAfter[0].path, '/api/v1/collections/team-2/people/p-1/face');
    assert.equal(doc.getElementById('result').textContent, 'Face data deleted.');
  });

  test('collection change: updates currentCollection, reverts on guard failure', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    const client = FakeFaceClient.lastInstance;

    // Change collection successfully
    doc.getElementById('collection').value = 'team-1';
    await doc.getElementById('collection').dispatchEvent({ type: 'change' });
    assert.equal(doc.getElementById('collection').value, 'team-1');

    // Attempt collection change while client.pending is active -> guard fails and reverts select
    client.pending = { path: '/test', body: {} };
    doc.getElementById('collection').value = 'team-invalid';
    await doc.getElementById('collection').dispatchEvent({ type: 'change' });
    assert.equal(doc.getElementById('collection').value, 'team-1');
    assert.equal(doc.getElementById('result').textContent, 'Retry the unresolved save before changing person or collection');
  });

  test('enrollment request body and capture lifecycle', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    const enrollBtn = doc.getElementById('people').children[0].children[3].children[0];
    await enrollBtn.click();

    const capture = FakeAutomaticCapture.lastInstance;
    assert.ok(capture);
    assert.equal(capture.active, true);
    assert.equal(capture.mode, 'enroll');
    assert.equal(doc.getElementById('camera-panel').hidden, false);
    assert.equal(doc.getElementById('camera-title').textContent, 'Capture Alice');

    // Exercise camera callback
    capture.callbacks.camera({ canSwitch: true });
    assert.equal(doc.getElementById('switch-camera').disabled, false);

    // Exercise progress callback
    capture.callbacks.progress(0.75);
    assert.equal(doc.getElementById('progress').value, 0.75);

    // Exercise status callback
    capture.callbacks.status('Looking for face');
    assert.equal(doc.getElementById('camera-status').textContent, 'Looking for face');

    // Exercise batch callback for enrollment
    const client = FakeFaceClient.lastInstance;
    client.sendResponse = { state: 'ACTIVE' };
    const batchResult = await capture.callbacks.batch({
      observations: [{ box: [10, 20, 30, 40] }],
      template: [0.1, 0.2, 0.3],
      brightness: 120
    });

    assert.equal(batchResult, false); // enroll mode returns false to stop loop
    const enrollCalls = client.calls.filter(c => c.path.includes('/enrollments'));
    assert.equal(enrollCalls.length, 1);
    const body = enrollCalls[0].body;
    assert.equal(body.person_id, 'p-1');
    assert.equal(body.consent, true);
    assert.deepEqual(body.template, [0.1, 0.2, 0.3]);
    assert.equal(body.model_version, 'v1.0');
    assert.equal(body.preprocessing_version, 'v1.0');
    assert.equal(body.lighting, 'measured-normal');
    assert.match(body.device, /^camera-[0-9a-f]{17,24}$/);
    assert.equal(doc.getElementById('result').textContent, 'Face enrolled.');
    assert.equal(doc.getElementById('camera-status').textContent, 'Face enrolled.');
  });

  test('recognition request body and departure handling', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();

    await doc.getElementById('recognize').click();

    const capture = FakeAutomaticCapture.lastInstance;
    assert.ok(capture);
    assert.equal(capture.active, true);
    assert.equal(capture.mode, 'attendance');
    assert.equal(doc.getElementById('camera-title').textContent, 'Recognize in Team One');

    const client = FakeFaceClient.lastInstance;
    client.sendResponse = { state: 'MATCHED', person_name: 'Alice' };

    const batchResult = await capture.callbacks.batch({
      observations: [{ box: [5, 5, 50, 50] }],
      template: [0.9],
      brightness: 50
    });

    assert.deepEqual(batchResult, { waitForDeparture: true });
    const recCalls = client.calls.filter(c => c.path.includes('/recognitions'));
    assert.equal(recCalls.length, 1);
    const body = recCalls[0].body;
    assert.ok(body.capture_session);
    assert.equal(body.person_id, undefined); // No person_id in recognition
    assert.equal(body.consent, undefined);
    assert.equal(body.device, undefined);
    assert.equal(doc.getElementById('result').textContent, 'Matched: Alice');
  });

  test('result event: dispatches face-result CustomEvent and formats all outcome states', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();

    const states = [
      [{ state: 'SUGGESTED', person_name: 'Bob' }, 'Evaluation suggestion: Bob (unverified)'],
      [{ state: 'MATCHED', person_name: 'Bob' }, 'Matched: Bob'],
      [{ state: 'PENDING' }, 'Face saved for evaluation. Approval is pending.'],
      [{ state: 'ACTIVE' }, 'Face enrolled.'],
      [{ state: 'REJECTED_DUPLICATE', ambiguous: true }, 'This capture conflicts with multiple saved people.'],
      [{ state: 'REJECTED_DUPLICATE', ambiguous: false }, 'This face is already registered to another person.'],
      [{ state: 'OBSERVATION_SAVED' }, 'Labeled observation saved without changing the enrolled reference.'],
      [{ state: 'UNKNOWN', reason: 'Low quality' }, 'Face not recognized. Low quality'],
      [{ state: 'COLLECTION_ONLY' }, 'This workspace is configured for collection only.'],
      [{ state: 'CUSTOM_STATE' }, 'CUSTOM_STATE']
    ];

    for (const [result, expectedMsg] of states) {
      win.dispatchedEvents = [];
      app.showResult(result);
      assert.equal(doc.getElementById('result').textContent, expectedMsg);
      assert.equal(win.dispatchedEvents.length, 1);
      assert.equal(win.dispatchedEvents[0].type, 'face-result');
      assert.deepEqual(win.dispatchedEvents[0].detail, result);
    }
  });

  test('stop and stale callbacks: stops capture, ignores callbacks from old generations', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    const enrollBtn = doc.getElementById('people').children[0].children[3].children[0];
    await enrollBtn.click();
    const capture1 = FakeAutomaticCapture.lastInstance;

    // Stop camera
    await doc.getElementById('stop-camera').click();
    assert.equal(capture1.active, false);
    assert.equal(doc.getElementById('camera-panel').hidden, true);
    assert.equal(doc.getElementById('result').textContent, 'Camera stopped.');

    // Stale callbacks from capture1 must be ignored
    capture1.callbacks.status('Stale status');
    assert.notEqual(doc.getElementById('camera-status').textContent, 'Stale status');

    capture1.callbacks.progress(0.99);
    assert.notEqual(doc.getElementById('progress').value, 0.99);

    capture1.callbacks.error('Stale error');
    assert.notEqual(doc.getElementById('result').textContent, 'Stale error');

    const staleBatch = await capture1.callbacks.batch({ observations: [], brightness: 100 });
    assert.equal(staleBatch, false);
  });

  test('pending retry and switching guards: prevents person/collection changes, enables retry button, supports recovery', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    const client = FakeFaceClient.lastInstance;
    doc.getElementById('consent').checked = true;

    // Start enrollment
    const enrollBtn = doc.getElementById('people').children[0].children[3].children[0];
    await enrollBtn.click();
    const capture = FakeAutomaticCapture.lastInstance;

    // Active capture guard prevents changing person or collection
    assert.throws(() => app.guard(), /Stop the current camera before changing person or collection/);

    // Simulate batch network failure that sets client.pending
    client.sendError = new Error('Network timeout');
    client.setPendingOnError = true;

    const batchOutcome = await capture.callbacks.batch({
      observations: [],
      template: [0.1],
      brightness: 100
    });

    assert.deepEqual(batchOutcome, { pending: true });
    assert.equal(doc.getElementById('retry').hidden, false);
    assert.equal(doc.getElementById('result').textContent, 'Network timeout');

    // Pending guard prevents changing person or collection
    assert.throws(() => app.guard(), /Retry the unresolved save before changing person or collection/);

    // Stop camera with pending save warns user
    await doc.getElementById('stop-camera').click();
    assert.equal(doc.getElementById('result').textContent, 'Camera stopped. A submitted request may have saved; use Retry save.');

    // Beforeunload event prevents unloading when client.pending
    let prevented = false;
    const unloadEvt = { preventDefault: () => { prevented = true; }, returnValue: 'ok' };
    win.dispatchEvent({ type: 'beforeunload', ...unloadEvt });
    for (const fn of win.listeners['beforeunload'] || []) {
      fn(unloadEvt);
    }
    assert.equal(prevented, true);

    // Click retry
    client.retryResponse = { state: 'ACTIVE', person_name: 'Alice' };
    await doc.getElementById('retry').click();
    assert.equal(doc.getElementById('retry').hidden, true);
    assert.equal(doc.getElementById('result').textContent, 'Face enrolled.');
    assert.equal(client.pending, null);
  });

  test('async failure in refreshPeople after batch displays confirmation warning', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    const client = FakeFaceClient.lastInstance;
    const enrollBtn = doc.getElementById('people').children[0].children[3].children[0];
    await enrollBtn.click();
    const capture = FakeAutomaticCapture.lastInstance;

    client.sendResponse = { state: 'ACTIVE' };
    client.peopleError = new Error('Network glitch on refresh');

    await capture.callbacks.batch({
      observations: [],
      template: [0.5],
      brightness: 100
    });

    assert.ok(doc.getElementById('result').textContent.includes('Saved status is confirmed; refresh the list when connected.'));
  });

  test('visibilitychange and pagehide listeners communicate with capture and lifecycle', async () => {
    doc.getElementById('token').value = 'tok';
    await doc.getElementById('connect').click();
    doc.getElementById('consent').checked = true;

    await doc.getElementById('recognize').click();
    const capture = FakeAutomaticCapture.lastInstance;
    assert.equal(capture.active, true);

    // Visibility change
    doc.hidden = true;
    for (const fn of doc.listeners['visibilitychange'] || []) {
      fn();
    }
    assert.equal(capture.hidden, true);

    // Pagehide stops camera
    for (const fn of win.listeners['pagehide'] || []) {
      fn();
    }
    assert.equal(capture.active, false);
  });
});
