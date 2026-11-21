/**
 * Browser Performance Benchmark Runner & UI Harness
 *
 * Provides a local, opt-in harness for measuring browser-side face inference:
 * - Cold & warm startup latencies
 * - Model & WASM transfer vs decoded body sizes (PerformanceResourceTiming)
 * - Detection & recognition inference latency samples (p50/p95)
 * - Hardware, WASM threading, and WebGPU isolation assessment
 * - Zero collection of faces, tokens, vectors, or device IDs
 * - Zero automatic external uploads
 */

import {
    calculateLatencyMetrics,
    calculateResourceSizes,
    probePlatformCapabilities,
    generateBenchmarkReport,
    redactBenchmarkReport
} from './metrics.mjs';

/**
 * Runs the browser benchmark in an opt-in context.
 * Requires explicit operator consent.
 */
export async function runBrowserBenchmark(options = {}) {
    const {
        consented = false,
        iterations = 10,
        warmup = 2,
        assetsBase = '../../src/face_inference/web/vendor/browser-face/',
        ortInstance = typeof window !== 'undefined' ? window.ort : null,
        performanceApi = typeof performance !== 'undefined' ? performance : null,
        env = {},
        onProgress = () => {}
    } = options;

    if (!consented) {
        throw new Error('Operator consent required: benchmarking cannot proceed without explicit consent.');
    }

    onProgress({stage: 'probing', message: 'Probing browser capabilities and hardware isolation...'});
    const platform = probePlatformCapabilities(env);

    // Collect performance resource timing entries
    let resourceEntries = [];
    if (performanceApi && typeof performanceApi.getEntriesByType === 'function') {
        const allResources = performanceApi.getEntriesByType('resource');
        resourceEntries = allResources.filter(entry => {
            const name = entry.name.toLowerCase();
            return name.includes('yunet') ||
                   name.includes('sface') ||
                   name.includes('ort-wasm') ||
                   name.includes('ort.wasm');
        });
    }

    const resourceSizes = calculateResourceSizes(resourceEntries);

    let coldStartupMs = null;
    let warmStartupMs = null;
    const inferenceSamples = [];

    // If ONNX Runtime is present in global scope, measure live sessions
    if (ortInstance && ortInstance.InferenceSession) {
        try {
            onProgress({stage: 'startup_cold', message: 'Measuring cold session creation...'});
            const t0 = performanceApi ? performanceApi.now() : Date.now();
            const detector = await ortInstance.InferenceSession.create(
                assetsBase + 'yunet.onnx',
                {executionProviders: ['wasm']}
            );
            const recognizer = await ortInstance.InferenceSession.create(
                assetsBase + 'sface.onnx',
                {executionProviders: ['wasm']}
            );
            const t1 = performanceApi ? performanceApi.now() : Date.now();
            coldStartupMs = Math.round((t1 - t0) * 100) / 100;

            onProgress({stage: 'startup_warm', message: 'Measuring warm session creation...'});
            const t2 = performanceApi ? performanceApi.now() : Date.now();
            const detectorWarm = await ortInstance.InferenceSession.create(
                assetsBase + 'yunet.onnx',
                {executionProviders: ['wasm']}
            );
            const t3 = performanceApi ? performanceApi.now() : Date.now();
            warmStartupMs = Math.round((t3 - t2) * 100) / 100;

            // Warmup iterations
            onProgress({stage: 'warmup', message: `Running ${warmup} warmup iteration(s)...`});
            const inputTensor = new ortInstance.Tensor('float32', new Float32Array(3 * 640 * 640), [1, 3, 640, 640]);
            for (let i = 0; i < warmup; i++) {
                await detector.run({input: inputTensor});
            }

            // Benchmark iterations
            onProgress({stage: 'inference', message: `Measuring ${iterations} inference iteration(s)...`});
            for (let i = 0; i < iterations; i++) {
                const start = performanceApi ? performanceApi.now() : Date.now();
                await detector.run({input: inputTensor});
                const elapsed = (performanceApi ? performanceApi.now() : Date.now()) - start;
                inferenceSamples.push(Math.round(elapsed * 100) / 100);
                onProgress({
                    stage: 'iteration',
                    iteration: i + 1,
                    total: iterations,
                    durationMs: elapsed
                });
            }

            // Clean up session tensors
            inputTensor.dispose?.();
        } catch (err) {
            onProgress({stage: 'warning', message: `Inference timing skipped: ${err.message}`});
        }
    } else {
        onProgress({
            stage: 'info',
            message: 'Inference runtime not available in this context; reporting platform capabilities and asset inventory only.'
        });
    }

    const report = generateBenchmarkReport({
        platform,
        resourceSizes,
        coldStartupMs,
        warmStartupMs,
        inferenceSamples,
        modelVersions: {
            detector: 'yunet640-similarity5-rgb255-v1',
            recognizer: 'sface-int8bq-fb143eea',
            detectorFile: 'yunet.onnx',
            recognizerFile: 'sface.onnx'
        }
    });

    onProgress({stage: 'complete', message: 'Benchmark completed successfully.'});
    return report;
}

/**
 * Initializes the interactive browser benchmark DOM interface.
 */
export function initBenchmarkUI(rootElement = document) {
    const $ = id => rootElement.getElementById ? rootElement.getElementById(id) : rootElement.querySelector('#' + id);
    const consentCheckbox = $('consent');
    const startButton = $('start-btn');
    const iterationsInput = $('iterations');
    const statusBox = $('status');
    const resultsContainer = $('results-container');
    const reportPre = $('report-json');
    const copyButton = $('copy-btn');
    const downloadButton = $('download-btn');

    if (!consentCheckbox || !startButton) return;

    consentCheckbox.addEventListener('change', () => {
        startButton.disabled = !consentCheckbox.checked;
    });

    let currentReport = null;

    startButton.addEventListener('click', async () => {
        startButton.disabled = true;
        statusBox.textContent = 'Starting benchmark...';
        statusBox.className = 'status-running';

        try {
            const iterations = parseInt(iterationsInput?.value || '10', 10);
            const report = await runBrowserBenchmark({
                consented: consentCheckbox.checked,
                iterations,
                warmup: 2,
                onProgress: ({message}) => {
                    statusBox.textContent = message;
                }
            });

            currentReport = report;
            if (reportPre) {
                reportPre.textContent = JSON.stringify(report, null, 2);
            }
            if (resultsContainer) {
                resultsContainer.hidden = false;
            }

            // Populate summary cards if present
            const setField = (id, val) => {
                const el = $(id);
                if (el) el.textContent = val ?? 'N/A';
            };

            setField('res-browser', `${report.browser.name} ${report.browser.majorVersion} (${report.os})`);
            setField('res-cache', report.cacheState);
            setField('res-cold', report.latencies.coldStartupMs ? `${report.latencies.coldStartupMs} ms` : 'not measured');
            setField('res-warm', report.latencies.warmStartupMs ? `${report.latencies.warmStartupMs} ms` : 'not measured');
            setField('res-samples', report.sampleCount);
            setField('res-p50', report.latencies.inference.p50 ? `${report.latencies.inference.p50.toFixed(2)} ms` : 'not measured');
            setField('res-p95', report.latencies.inference.p95 ? `${report.latencies.inference.p95.toFixed(2)} ms` : 'not measured');
            setField('res-transfer', `${(report.sizes.totalTransferBytes / (1024 * 1024)).toFixed(2)} MB`);
            setField('res-decoded', `${(report.sizes.totalDecodedBytes / (1024 * 1024)).toFixed(2)} MB`);

            statusBox.textContent = 'Benchmark complete. Results stored locally in browser session.';
            statusBox.className = 'status-complete';
        } catch (err) {
            statusBox.textContent = `Error: ${err.message}`;
            statusBox.className = 'status-error';
        } finally {
            startButton.disabled = !consentCheckbox.checked;
        }
    });

    if (copyButton) {
        copyButton.addEventListener('click', async () => {
            if (!currentReport) return;
            await navigator.clipboard.writeText(JSON.stringify(currentReport, null, 2));
            const old = copyButton.textContent;
            copyButton.textContent = 'Copied!';
            setTimeout(() => { copyButton.textContent = old; }, 2000);
        });
    }

    if (downloadButton) {
        downloadButton.addEventListener('click', () => {
            if (!currentReport) return;
            const blob = new Blob([JSON.stringify(currentReport, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `browser-performance-report-${Date.now()}.json`;
            a.click();
            URL.revokeObjectURL(url);
        });
    }
}
