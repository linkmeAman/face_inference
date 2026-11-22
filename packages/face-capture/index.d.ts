/**
 * TypeScript definitions for the face-capture web component.
 */

export interface ParsedConfig {
  apiUrl: string;
  token: string;
  collection: string;
  mode: 'enroll' | 'recognize';
  personId: string;
  modelBaseUrl: string;
  autostart: boolean;
}

export interface ConfigValidationResult {
  valid: boolean;
  code?: string;
  message?: string;
}

export interface ConsentGatingResult {
  allowed: boolean;
  code?: string;
  message?: string;
}

export interface FaceErrorDetail {
  code: string;
  message: string;
}

export interface FaceCaptureStrings {
  start?: string;
  stop?: string;
  retry?: string;
  ready?: string;
  connecting?: string;
  retrying?: string;
  cameraPreview?: string;
  captureProgress?: string;
  consentLabel?: string;
  regionLabel?: string;
  cameraStopped?: string;
  cameraStoppedPending?: string;
  resultPending?: string;
  resultActive?: string;
  resultDuplicate?: string;
  resultDuplicateAmbiguous?: string;
  resultObservationSaved?: string;
  resultUnknown?: string;
  resultCollectionOnly?: string;
  resultSuggested?: string;
  resultMatched?: string;
  [key: string]: string | undefined;
}

export const DEFAULT_STRINGS: Readonly<Record<string, string>>;

export function parseConfig(input?: Record<string, unknown>): ParsedConfig;
export function validateConfig(config: ParsedConfig): ConfigValidationResult;
export function checkConsentGating(params: { mode: string; consentChecked: boolean }): ConsentGatingResult;
export function mapCameraError(error?: unknown): FaceErrorDetail;

export interface FaceResultDetail {
  state: 'ACTIVE' | 'PENDING' | 'REJECTED_DUPLICATE' | 'OBSERVATION_SAVED' | 'MATCHED' | 'SUGGESTED' | 'UNKNOWN' | string;
  person_id?: string;
  person_name?: string;
  reason?: string;
  ambiguous?: boolean;
  [key: string]: unknown;
}

export interface FaceCaptureCustomEventMap {
  'face-ready': CustomEvent<void>;
  'face-result': CustomEvent<FaceResultDetail>;
  'face-error': CustomEvent<FaceErrorDetail>;
}

export class FaceCaptureElement extends HTMLElement {
  static readonly template: string;
  readonly template: string;

  apiUrl: string;
  token: string;
  collection: string;
  mode: 'enroll' | 'recognize';
  personId: string;
  modelBaseUrl: string;
  autostart: boolean;
  strings: FaceCaptureStrings;
  readonly hasPendingSave: boolean;

  setStrings(customStrings?: Partial<FaceCaptureStrings>): void;
  start(): Promise<void>;
  stop(): void;
  retry(): Promise<FaceResultDetail>;

  addEventListener<K extends keyof FaceCaptureCustomEventMap>(
    type: K,
    listener: (this: FaceCaptureElement, ev: FaceCaptureCustomEventMap[K]) => unknown,
    options?: boolean | AddEventListenerOptions
  ): void;
  addEventListener(
    type: string,
    listener: EventListenerOrEventListenerObject,
    options?: boolean | AddEventListenerOptions
  ): void;

  removeEventListener<K extends keyof FaceCaptureCustomEventMap>(
    type: K,
    listener: (this: FaceCaptureElement, ev: FaceCaptureCustomEventMap[K]) => unknown,
    options?: boolean | EventListenerOptions
  ): void;
  removeEventListener(
    type: string,
    listener: EventListenerOrEventListenerObject,
    options?: boolean | EventListenerOptions
  ): void;
}

declare global {
  interface HTMLElementTagNameMap {
    'face-capture': FaceCaptureElement;
  }
}
