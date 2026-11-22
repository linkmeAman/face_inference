/**
 * Isolated TypeScript consumer testing the packed face-capture package.
 * Exercises element creation, properties, start/stop/retry, typed events,
 * and config exports (positive and negative cases with @ts-expect-error).
 */

import {
  FaceCaptureElement,
  DEFAULT_STRINGS,
  parseConfig,
  validateConfig,
  checkConsentGating,
  mapCameraError,
  type FaceCaptureStrings,
  type FaceResultDetail,
  type FaceErrorDetail,
  type ParsedConfig,
  type ConfigValidationResult,
  type ConsentGatingResult,
} from 'face-capture';

import {
  parseConfig as parseConfigSub,
  validateConfig as validateConfigSub,
  checkConsentGating as checkConsentGatingSub,
  mapCameraError as mapCameraErrorSub,
} from 'face-capture/config';

// @ts-expect-error - non-existent export from main entrypoint
import { nonExistentExportFromFaceCapture } from 'face-capture';

// @ts-expect-error - non-existent export from config subpath
import { nonExistentExportFromConfig } from 'face-capture/config';

// ---------------------------------------------------------------------------
// 1. Element creation
// ---------------------------------------------------------------------------

// A. Document tagName lookup creates typed FaceCaptureElement
const el: FaceCaptureElement = document.createElement('face-capture');

// B. Constructor instantiation
const elConstructed: FaceCaptureElement = new FaceCaptureElement();

// C. querySelector resolves to FaceCaptureElement | null
const elQuery: FaceCaptureElement | null = document.querySelector('face-capture');

// D. querySelectorAll resolves to NodeListOf<FaceCaptureElement>
const elAll: NodeListOf<FaceCaptureElement> = document.querySelectorAll('face-capture');

// @ts-expect-error - constructor accepts 0 arguments
const elBadConstructor = new FaceCaptureElement('invalid-arg');

// ---------------------------------------------------------------------------
// 2. Properties: read, write, and readonly enforcement
// ---------------------------------------------------------------------------

el.apiUrl = 'https://api.example.com';
const apiUrl: string = el.apiUrl;

el.token = 'bearer-token-123';
const token: string = el.token;

el.collection = 'default-collection';
const collection: string = el.collection;

el.mode = 'enroll';
el.mode = 'recognize';
const mode: 'enroll' | 'recognize' = el.mode;

el.personId = 'user-001';
const personId: string = el.personId;

el.modelBaseUrl = 'https://models.example.com/assets/';
const modelBaseUrl: string = el.modelBaseUrl;

el.autostart = true;
el.autostart = false;
const autostart: boolean = el.autostart;

el.strings = {
  start: 'Begin',
  stop: 'Halt',
  retry: 'Retry save',
  customKey: 'Custom message',
};
const currentStrings: FaceCaptureStrings = el.strings;

const hasPending: boolean = el.hasPendingSave;
const tpl: string = el.template;
const staticTpl: string = FaceCaptureElement.template;

// Negative property assignments
// @ts-expect-error - mode only accepts 'enroll' | 'recognize'
el.mode = 'detect';

// @ts-expect-error - autostart must be boolean
el.autostart = 'true';

// @ts-expect-error - autostart must be boolean
el.autostart = 1;

// @ts-expect-error - hasPendingSave is a readonly property
el.hasPendingSave = true;

// @ts-expect-error - instance template is a readonly property
el.template = '<div>bad</div>';

// @ts-expect-error - static template is a readonly property
FaceCaptureElement.template = '<div>bad</div>';

// @ts-expect-error - property does not exist on FaceCaptureElement
el.unknownProperty = 'invalid';

// ---------------------------------------------------------------------------
// 3. Methods: start, stop, retry, setStrings
// ---------------------------------------------------------------------------

async function exerciseLifecycle(): Promise<void> {
  const startPromise: Promise<void> = el.start();
  await startPromise;

  el.stop();

  const retryPromise: Promise<FaceResultDetail> = el.retry();
  const retryResult: FaceResultDetail = await retryPromise;
  const state: string = retryResult.state;
}

el.setStrings({
  ready: 'Ready',
  retrying: 'Retrying save...',
});
el.setStrings();

// Negative method invocations
// @ts-expect-error - stop accepts 0 arguments
el.stop('unexpected');

// @ts-expect-error - retry accepts 0 arguments
el.retry('unexpected');

// @ts-expect-error - setStrings requires an object
el.setStrings('not-an-object');

// ---------------------------------------------------------------------------
// 4. Typed events: face-ready, face-result, face-error
// ---------------------------------------------------------------------------

const onReady = (ev: CustomEvent<void>): void => {
  const detail: void = ev.detail;
};

const onResult = (ev: CustomEvent<FaceResultDetail>): void => {
  const detail: FaceResultDetail = ev.detail;
  const state: string = detail.state;
  const personId: string | undefined = detail.person_id;
  const personName: string | undefined = detail.person_name;
  const reason: string | undefined = detail.reason;
  const ambiguous: boolean | undefined = detail.ambiguous;
};

const onError = (ev: CustomEvent<FaceErrorDetail>): void => {
  const detail: FaceErrorDetail = ev.detail;
  const code: string = detail.code;
  const message: string = detail.message;
};

el.addEventListener('face-ready', onReady);
el.addEventListener('face-result', onResult);
el.addEventListener('face-error', onError);

// Inline listener with parameter type inference and `this` binding
el.addEventListener('face-result', function (this: FaceCaptureElement, ev) {
  const state: string = ev.detail.state;
  this.stop();
});

el.addEventListener('face-error', (ev) => {
  const code: string = ev.detail.code;
});

el.removeEventListener('face-ready', onReady);
el.removeEventListener('face-result', onResult);
el.removeEventListener('face-error', onError);

// Negative event listeners
// @ts-expect-error - face-result does not receive FaceErrorDetail
el.addEventListener('face-result', (ev: CustomEvent<FaceErrorDetail>) => {});

// @ts-expect-error - face-error does not receive FaceResultDetail
el.addEventListener('face-error', (ev: CustomEvent<FaceResultDetail>) => {});

el.addEventListener('face-result', (ev: CustomEvent<FaceResultDetail>) => {
  // @ts-expect-error - state is string, not boolean
  const badState: boolean = ev.detail.state;
});

el.addEventListener('face-error', (ev: CustomEvent<FaceErrorDetail>) => {
  // @ts-expect-error - code is string, not number
  const badCode: number = ev.detail.code;
});

// ---------------------------------------------------------------------------
// 5. Config exports from package root and subpath
// ---------------------------------------------------------------------------

const defaultStrings: Readonly<Record<string, string>> = DEFAULT_STRINGS;

const parsed1: ParsedConfig = parseConfig({
  'api-url': 'https://api.example.com',
  token: 'secret',
  collection: 'faces',
  mode: 'enroll',
  'person-id': 'p-1',
  'model-base-url': 'https://models.example.com/',
  autostart: true,
});
const validated1: ConfigValidationResult = validateConfig(parsed1);
const isValid1: boolean = validated1.valid;

const consent1: ConsentGatingResult = checkConsentGating({
  mode: 'enroll',
  consentChecked: true,
});
const isAllowed1: boolean = consent1.allowed;

const cameraErr1: FaceErrorDetail = mapCameraError(new Error('Permission denied'));
const errCode1: string = cameraErr1.code;
const errMsg1: string = cameraErr1.message;

// Config subpath exports
const parsed2: ParsedConfig = parseConfigSub({ apiUrl: 'https://api.example.com' });
const validated2: ConfigValidationResult = validateConfigSub(parsed2);
const consent2: ConsentGatingResult = checkConsentGatingSub({
  mode: 'recognize',
  consentChecked: false,
});
const cameraErr2: FaceErrorDetail = mapCameraErrorSub({ name: 'NotAllowedError' });

// Negative config calls
// @ts-expect-error - validateConfig requires ParsedConfig object
validateConfig('not-a-parsed-config');

// @ts-expect-error - checkConsentGating requires consentChecked
checkConsentGating({ mode: 'enroll' });

// @ts-expect-error - checkConsentGating requires boolean consentChecked
checkConsentGating({ mode: 'enroll', consentChecked: 'yes' });

// @ts-expect-error - parseConfig rejects number
parseConfig(12345);

export {
  el,
  elConstructed,
  elQuery,
  elAll,
  apiUrl,
  token,
  collection,
  mode,
  personId,
  modelBaseUrl,
  autostart,
  currentStrings,
  hasPending,
  tpl,
  staticTpl,
  exerciseLifecycle,
  defaultStrings,
  parsed1,
  validated1,
  isValid1,
  consent1,
  isAllowed1,
  cameraErr1,
  errCode1,
  errMsg1,
  parsed2,
  validated2,
  consent2,
  cameraErr2,
};
