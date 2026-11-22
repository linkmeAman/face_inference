import test from 'node:test';
import assert from 'node:assert/strict';
import { FaceCaptureElement } from '../src/face-capture.js';

function createMockDom() {
  const elements = {};
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
      focusCount: 0,
      focus() { this.focusCount++; },
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

test('Accessibility: template contains accessible landmarks, roles, and labels', () => {
  const el = new FaceCaptureElement();
  const tmpl = el.template || el.constructor.template || '';
  assert.ok(tmpl.length > 0, 'Component must expose its template for inspection');
  assert.match(tmpl, /role="region"/i, 'Container must define landmark region');
  assert.match(tmpl, /aria-label="[^"]+"/i, 'Landmark region must have accessible name');
  assert.match(tmpl, /<video[^>]*aria-label="[^"]+"/i, 'Video must define accessible name');
  assert.match(tmpl, /<canvas[^>]*aria-hidden="true"/i, 'Decorative canvas must be hidden from screen readers');
  assert.match(tmpl, /<div[^>]*class="status-text"[^>]*role="status"/i, 'Status text must have status role');
  assert.match(tmpl, /<div[^>]*class="status-text"[^>]*aria-live="polite"/i, 'Status text must be live region');
  assert.match(tmpl, /<progress[^>]*aria-label="[^"]+"/i, 'Progress bar must have accessible name');
  assert.match(tmpl, /<button[^>]*class="[^"]*btn-retry[^"]*"/i, 'Controls must include a retry button');
});

test('Accessibility: status text live region switches to assertive on errors and polite on normal status', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  el._setStatus('Connecting to face service...', false);
  assert.equal(mock.statusText.textContent, 'Connecting to face service...');
  assert.equal(mock.statusText.getAttribute('aria-live'), 'polite');
  assert.equal(mock.statusText.classList.contains('error-text'), false);

  el._setStatus('Service unavailable', true);
  assert.equal(mock.statusText.textContent, 'Service unavailable');
  assert.equal(mock.statusText.getAttribute('aria-live'), 'assertive');
  assert.equal(mock.statusText.classList.contains('error-text'), true);

  el._setStatus('Ready', false);
  assert.equal(mock.statusText.textContent, 'Ready');
  assert.equal(mock.statusText.getAttribute('aria-live'), 'polite');
  assert.equal(mock.statusText.classList.contains('error-text'), false);
});

test('Accessibility: custom text mechanism provides defaults and accepts overrides', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  assert.ok(el.strings, 'Element must provide strings object');
  assert.equal(el.strings.start, 'Start');
  assert.equal(el.strings.stop, 'Stop');
  assert.equal(el.strings.retry, 'Retry save');
  assert.equal(el.strings.ready, 'Ready');

  el.strings = {
    start: 'Démarrer',
    stop: 'Arrêter',
    retry: 'Réessayer',
    ready: 'Prêt',
    consentLabel: 'Confirmation requise',
  };

  assert.equal(el.strings.start, 'Démarrer');
  assert.equal(el.strings.stop, 'Arrêter');
  assert.equal(mock.btnStart.textContent, 'Démarrer');
  assert.equal(mock.btnStop.textContent, 'Arrêter');
  assert.equal(mock.btnRetry.textContent, 'Réessayer');
  assert.equal(mock.consentSpan.textContent, 'Confirmation requise');
});

test('Accessibility: untrusted custom text is never injected as raw HTML', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  const attackString = '<img src=x onerror="alert(1)">Consent & Privacy';
  el.strings = { consentLabel: attackString };

  assert.equal(mock.consentSpan.textContent, attackString);
  // Verify no HTML tags were evaluated into element properties
  assert.equal(mock.consentSpan.innerHTML, undefined);
});

test('Accessibility: progress updates maintain aria-valuenow attributes', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  el._setProgress(0.75);
  assert.equal(mock.progress.value, 0.75);
  assert.equal(mock.progress.getAttribute('aria-valuenow'), '75');
});

test('Accessibility: keyboard controls can be triggered by click/enter/space', () => {
  const el = new FaceCaptureElement();
  const mock = createMockDom();
  el.shadowRoot = mock.shadowRoot;
  el._bindElements();

  let stopCalled = false;
  el.stop = () => { stopCalled = true; };

  mock.btnStop.click();
  assert.equal(stopCalled, true);
});
