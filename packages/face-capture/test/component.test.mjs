import test from 'node:test';
import assert from 'node:assert/strict';
import { FaceCaptureElement } from '../src/face-capture.js';

test('FaceCaptureElement: observedAttributes are defined correctly', () => {
  const expected = [
    'api-url',
    'token',
    'collection',
    'mode',
    'person-id',
    'model-base-url',
    'autostart',
  ];
  assert.deepEqual(FaceCaptureElement.observedAttributes, expected);
});

test('FaceCaptureElement: _resultMessage formats known server responses correctly', () => {
  const element = new FaceCaptureElement();

  assert.equal(
    element._resultMessage({ state: 'ACTIVE' }),
    'Face enrolled.'
  );

  assert.equal(
    element._resultMessage({ state: 'PENDING' }),
    'Face saved for evaluation. Approval is pending.'
  );

  assert.equal(
    element._resultMessage({ state: 'REJECTED_DUPLICATE', ambiguous: false }),
    'This face is already registered to another person.'
  );

  assert.equal(
    element._resultMessage({ state: 'REJECTED_DUPLICATE', ambiguous: true }),
    'This capture conflicts with multiple saved people.'
  );

  assert.equal(
    element._resultMessage({ state: 'MATCHED', person_name: 'Alice' }),
    'Matched: Alice'
  );

  assert.equal(
    element._resultMessage({ state: 'SUGGESTED', person_name: 'Bob' }),
    'Evaluation suggestion: Bob (unverified)'
  );

  assert.equal(
    element._resultMessage({ state: 'UNKNOWN', reason: 'Low similarity' }),
    'Face not recognized. Low similarity'
  );
});

test('FaceCaptureElement: start emits face-error when config is invalid', async () => {
  const element = new FaceCaptureElement();
  const errors = [];

  // Mock EventTarget / dispatchEvent in pure Node environment
  element.dispatchEvent = (event) => {
    if (event.type === 'face-error') {
      errors.push(event.detail);
    }
    return true;
  };

  // Start with empty configuration
  await element.start();

  assert.equal(errors.length, 1);
  assert.equal(errors[0].code, 'INVALID_CONFIG');
  assert.match(errors[0].message, /api-url/i);
});

test('FaceCaptureElement: start emits face-error when enroll mode lacks consent', async () => {
  const element = new FaceCaptureElement();
  const errors = [];

  element.dispatchEvent = (event) => {
    if (event.type === 'face-error') {
      errors.push(event.detail);
    }
    return true;
  };

  element.getAttribute = (name) => {
    const map = {
      'api-url': 'http://127.0.0.1:6122',
      collection: 'test-col',
      mode: 'enroll',
      'person-id': 'person-1',
    };
    return map[name] || '';
  };

  // _consentCheckbox is not checked
  element._consentCheckbox = { checked: false };

  await element.start();

  assert.equal(errors.length, 1);
  assert.equal(errors[0].code, 'CONSENT_REQUIRED');
  assert.match(errors[0].message, /consent/i);
});

test('FaceCaptureElement: stop cleans up stream and progress', () => {
  const element = new FaceCaptureElement();
  let stoppedTrack = false;
  let stoppedCapture = false;

  element._capture = {
    stop: () => {
      stoppedCapture = true;
    },
  };
  element._video = {
    srcObject: {
      getTracks: () => [{ stop: () => { stoppedTrack = true; } }],
    },
  };
  element._progress = { value: 0.8 };
  element._btnStart = { disabled: true };
  element._btnStop = { disabled: false };

  element.stop();

  assert.equal(stoppedCapture, true);
  assert.equal(stoppedTrack, true);
  assert.equal(element._progress.value, 0);
  assert.equal(element._btnStart.disabled, false);
  assert.equal(element._btnStop.disabled, true);
  assert.equal(element._capture, null);
  assert.equal(element._video.srcObject, null);
});
