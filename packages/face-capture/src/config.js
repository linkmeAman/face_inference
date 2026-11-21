/**
 * Configuration parsing, validation, consent gating, and error mapping for <face-capture>.
 * Pure functions suitable for testing without DOM or browser dependencies.
 */

export function parseConfig(input = {}) {
  const get = (kebab, camel) => {
    if (input[kebab] !== undefined) return input[kebab];
    if (input[camel] !== undefined) return input[camel];
    return undefined;
  };

  const rawApiUrl = String(get('api-url', 'apiUrl') || '').trim();
  const apiUrl = rawApiUrl.replace(/\/+$/, '');

  const token = String(input.token || '').trim();
  const collection = String(input.collection || '').trim();

  const rawMode = String(input.mode || 'recognize').trim().toLowerCase();
  const mode = rawMode || 'recognize';

  const personId = String(get('person-id', 'personId') || '').trim();

  const rawModelBase = String(get('model-base-url', 'modelBaseUrl') || '').trim();
  let modelBaseUrl = '';
  if (rawModelBase) {
    modelBaseUrl = rawModelBase.endsWith('/') ? rawModelBase : rawModelBase + '/';
  } else if (apiUrl) {
    modelBaseUrl = apiUrl + '/';
  }

  const rawAutostart = input.autostart;
  const autostart = rawAutostart === '' || Boolean(rawAutostart);

  return {
    apiUrl,
    token,
    collection,
    mode,
    personId,
    modelBaseUrl,
    autostart,
  };
}

export function validateConfig(config) {
  if (!config.apiUrl) {
    return {
      valid: false,
      code: 'INVALID_CONFIG',
      message: 'api-url attribute or property is required',
    };
  }
  if (!config.collection) {
    return {
      valid: false,
      code: 'INVALID_CONFIG',
      message: 'collection attribute or property is required',
    };
  }
  if (!['enroll', 'recognize'].includes(config.mode)) {
    return {
      valid: false,
      code: 'INVALID_CONFIG',
      message: 'mode must be "enroll" or "recognize"',
    };
  }
  if (config.mode === 'enroll' && !config.personId) {
    return {
      valid: false,
      code: 'INVALID_CONFIG',
      message: 'person-id attribute or property is required when mode is "enroll"',
    };
  }
  return { valid: true };
}

export function checkConsentGating({ mode, consentChecked }) {
  if (mode === 'enroll' && !consentChecked) {
    return {
      allowed: false,
      code: 'CONSENT_REQUIRED',
      message: 'Confirm consent before capturing a face',
    };
  }
  return { allowed: true };
}

export function mapCameraError(error = {}) {
  const name = error.name || '';
  const message = error.message || '';

  if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || /permission|denied|allow camera/i.test(message)) {
    return {
      code: 'CAMERA_DENIED',
      message: 'Camera access denied. Please allow camera permissions and retry.',
    };
  }
  if (name === 'NotFoundError' || name === 'DevicesNotFoundError' || /not found|no camera/i.test(message)) {
    return {
      code: 'CAMERA_NOT_FOUND',
      message: 'No camera found on this device.',
    };
  }
  if (name === 'NotReadableError' || name === 'TrackStartError' || /in use|start/i.test(message)) {
    return {
      code: 'CAMERA_NOT_READABLE',
      message: 'Camera is in use by another application or could not be started.',
    };
  }
  if (name === 'OverconstrainedError' || /constraint/i.test(message)) {
    return {
      code: 'CAMERA_OVERCONSTRAINED',
      message: 'No camera satisfies the requested constraints.',
    };
  }
  if (name === 'SecurityError' || /https|secure/i.test(message)) {
    return {
      code: 'INSECURE_CONTEXT',
      message: 'Camera requires HTTPS or localhost.',
    };
  }

  return {
    code: error.code || 'CAMERA_ERROR',
    message: message || 'An unexpected camera error occurred.',
  };
}
