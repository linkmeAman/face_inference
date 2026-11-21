import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseConfig,
  validateConfig,
  checkConsentGating,
  mapCameraError,
} from '../src/config.js';

test('parseConfig: parses attributes with defaults and normalization', () => {
  const parsed = parseConfig({
    'api-url': 'http://127.0.0.1:6122/',
    token: 'test-token-1234567890123456789012',
    collection: 'office-main',
    mode: 'enroll',
    'person-id': 'person_001',
    autostart: '',
  });

  assert.equal(parsed.apiUrl, 'http://127.0.0.1:6122');
  assert.equal(parsed.token, 'test-token-1234567890123456789012');
  assert.equal(parsed.collection, 'office-main');
  assert.equal(parsed.mode, 'enroll');
  assert.equal(parsed.personId, 'person_001');
  assert.equal(parsed.modelBaseUrl, 'http://127.0.0.1:6122/');
  assert.equal(parsed.autostart, true);
});

test('parseConfig: defaults mode to recognize and autostart to false', () => {
  const parsed = parseConfig({
    apiUrl: 'http://localhost:6122',
    collection: 'staff',
  });

  assert.equal(parsed.mode, 'recognize');
  assert.equal(parsed.autostart, false);
  assert.equal(parsed.modelBaseUrl, 'http://localhost:6122/');
  assert.equal(parsed.personId, '');
});

test('parseConfig: accepts custom model-base-url', () => {
  const parsed = parseConfig({
    apiUrl: 'http://localhost:6122',
    collection: 'staff',
    modelBaseUrl: 'https://cdn.example.com/models',
  });

  assert.equal(parsed.modelBaseUrl, 'https://cdn.example.com/models/');
});

test('validateConfig: validates required fields according to mode', () => {
  // Missing apiUrl
  const noApi = validateConfig({ collection: 'test', mode: 'recognize' });
  assert.equal(noApi.valid, false);
  assert.equal(noApi.code, 'INVALID_CONFIG');
  assert.match(noApi.message, /api-url/i);

  // Missing collection
  const noCol = validateConfig({ apiUrl: 'http://localhost:6122', mode: 'recognize' });
  assert.equal(noCol.valid, false);
  assert.equal(noCol.code, 'INVALID_CONFIG');
  assert.match(noCol.message, /collection/i);

  // Invalid mode
  const badMode = validateConfig({ apiUrl: 'http://localhost:6122', collection: 'c', mode: 'invalid' });
  assert.equal(badMode.valid, false);
  assert.equal(badMode.code, 'INVALID_CONFIG');

  // Enroll mode requires personId
  const noPerson = validateConfig({ apiUrl: 'http://localhost:6122', collection: 'c', mode: 'enroll' });
  assert.equal(noPerson.valid, false);
  assert.equal(noPerson.code, 'INVALID_CONFIG');
  assert.match(noPerson.message, /person-id/i);

  // Valid recognize config
  const validRec = validateConfig({ apiUrl: 'http://localhost:6122', collection: 'c', mode: 'recognize' });
  assert.equal(validRec.valid, true);

  // Valid enroll config
  const validEnr = validateConfig({ apiUrl: 'http://localhost:6122', collection: 'c', mode: 'enroll', personId: 'p1' });
  assert.equal(validEnr.valid, true);
});

test('checkConsentGating: enforces consent in enroll mode and ignores in recognize', () => {
  // Enroll without consent -> blocked
  const enrollNoConsent = checkConsentGating({ mode: 'enroll', consentChecked: false });
  assert.equal(enrollNoConsent.allowed, false);
  assert.equal(enrollNoConsent.code, 'CONSENT_REQUIRED');
  assert.match(enrollNoConsent.message, /consent/i);

  // Enroll with consent -> allowed
  const enrollConsent = checkConsentGating({ mode: 'enroll', consentChecked: true });
  assert.equal(enrollConsent.allowed, true);

  // Recognize without consent -> allowed
  const recNoConsent = checkConsentGating({ mode: 'recognize', consentChecked: false });
  assert.equal(recNoConsent.allowed, true);
});

test('mapCameraError: translates DOM and media stream errors into human face-error details', () => {
  const denied = mapCameraError({ name: 'NotAllowedError', message: 'Permission dismissed' });
  assert.equal(denied.code, 'CAMERA_DENIED');
  assert.match(denied.message, /denied|permission/i);

  const notFound = mapCameraError({ name: 'NotFoundError', message: 'Requested device not found' });
  assert.equal(notFound.code, 'CAMERA_NOT_FOUND');
  assert.match(notFound.message, /not found|no camera/i);

  const readable = mapCameraError({ name: 'NotReadableError', message: 'Could not start video source' });
  assert.equal(readable.code, 'CAMERA_NOT_READABLE');
  assert.match(readable.message, /in use|start/i);

  const insecure = mapCameraError({ name: 'SecurityError', message: 'Insecure context' });
  assert.equal(insecure.code, 'INSECURE_CONTEXT');
  assert.match(insecure.message, /https|secure/i);

  const insecureCustom = mapCameraError(new Error('Camera requires HTTPS'));
  assert.equal(insecureCustom.code, 'INSECURE_CONTEXT');

  const generic = mapCameraError(new Error('Custom hardware error'));
  assert.equal(generic.code, 'CAMERA_ERROR');
  assert.equal(generic.message, 'Custom hardware error');
});
