import {test} from 'node:test';
import assert from 'node:assert/strict';
import {
    calculatePercentile,
    calculateLatencyMetrics,
    calculateResourceSizes,
    probePlatformCapabilities,
    redactBenchmarkReport,
    generateBenchmarkReport
} from './metrics.mjs';
import {runBrowserBenchmark} from './harness.mjs';

test('percentile calculations compute deterministic p50 and p95', () => {
    // Empty samples return null
    assert.equal(calculatePercentile([], 50), null);
    assert.equal(calculatePercentile([], 95), null);

    // Single sample returns exact value
    assert.equal(calculatePercentile([42.5], 50), 42.5);
    assert.equal(calculatePercentile([42.5], 95), 42.5);

    // Known sequence of 5 samples: [10, 20, 30, 40, 50]
    // p50 is median (30)
    assert.equal(calculatePercentile([50, 10, 40, 20, 30], 50), 30);

    // Sequence of 100 samples from 1 to 100
    const sequence = Array.from({length: 100}, (_, i) => i + 1);
    assert.equal(calculatePercentile(sequence, 50), 50.5);
    assert.equal(calculatePercentile(sequence, 95), 95.05);

    // Latency metrics bundle summary stats
    const metrics = calculateLatencyMetrics([12.0, 14.5, 13.0, 25.0, 18.2]);
    assert.equal(metrics.count, 5);
    assert.equal(metrics.min, 12.0);
    assert.equal(metrics.max, 25.0);
    assert.equal(metrics.p50, 14.5);
    assert.ok(metrics.p95 >= 18.2 && metrics.p95 <= 25.0);
    assert.ok(Math.abs(metrics.mean - 16.54) < 1e-4);
});

test('resource size calculations distinguish transfer from decoded size and identify cache state', () => {
    // Cold cache fixture: network transfer > 0, compressed transfer vs decoded body
    const coldEntries = [
        {
            name: 'http://127.0.0.1:6122/vendor/browser-face/yunet.onnx',
            transferSize: 98000,
            encodedBodySize: 98000,
            decodedBodySize: 100416
        },
        {
            name: 'http://127.0.0.1:6122/vendor/browser-face/sface.onnx',
            transferSize: 10500000,
            encodedBodySize: 10500000,
            decodedBodySize: 10660286
        },
        {
            name: 'http://127.0.0.1:6122/vendor/browser-face/ort-wasm-simd-threaded.wasm',
            transferSize: 12000000,
            encodedBodySize: 12000000,
            decodedBodySize: 12361745
        },
        {
            name: 'http://127.0.0.1:6122/vendor/browser-face/ort.wasm.min.mjs',
            transferSize: 15000,
            encodedBodySize: 15000,
            decodedBodySize: 49997
        }
    ];

    const coldResult = calculateResourceSizes(coldEntries);
    assert.equal(coldResult.cacheState, 'cold');
    assert.equal(coldResult.totalTransferBytes, 98000 + 10500000 + 12000000 + 15000);
    assert.equal(coldResult.totalDecodedBytes, 100416 + 10660286 + 12361745 + 49997);
    assert.equal(coldResult.resources['yunet.onnx'].transferBytes, 98000);
    assert.equal(coldResult.resources['yunet.onnx'].decodedBytes, 100416);
    assert.equal(coldResult.resources['sface.onnx'].transferBytes, 10500000);
    assert.equal(coldResult.resources['sface.onnx'].decodedBytes, 10660286);
    assert.equal(coldResult.resources['ort-wasm-simd-threaded.wasm'].transferBytes, 12000000);
    assert.equal(coldResult.resources['ort-wasm-simd-threaded.wasm'].decodedBytes, 12361745);

    // Warm cache fixture: transferSize is 0 (from memory/disk cache)
    const warmEntries = coldEntries.map(e => ({
        ...e,
        transferSize: 0,
        encodedBodySize: 0
    }));

    const warmResult = calculateResourceSizes(warmEntries);
    assert.equal(warmResult.cacheState, 'warm');
    assert.equal(warmResult.totalTransferBytes, 0);
    assert.equal(warmResult.totalDecodedBytes, 100416 + 10660286 + 12361745 + 49997);
    assert.equal(warmResult.resources['yunet.onnx'].transferBytes, 0);
    assert.equal(warmResult.resources['yunet.onnx'].decodedBytes, 100416);

    // Mixed cache state
    const mixedEntries = [
        { name: 'http://127.0.0.1:6122/vendor/browser-face/yunet.onnx', transferSize: 100416, decodedBodySize: 100416 },
        { name: 'http://127.0.0.1:6122/vendor/browser-face/sface.onnx', transferSize: 0, decodedBodySize: 10660286 }
    ];
    const mixedResult = calculateResourceSizes(mixedEntries);
    assert.equal(mixedResult.cacheState, 'mixed');
    assert.equal(mixedResult.totalTransferBytes, 100416);
    assert.equal(mixedResult.totalDecodedBytes, 100416 + 10660286);
});

test('platform capabilities probe assesses WASM thread and WebGPU support without leaking device IDs', () => {
    // Simulated browser environment with crossOriginIsolated and SharedArrayBuffer
    const mockEnvThreaded = {
        userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        crossOriginIsolated: true,
        hasSharedArrayBuffer: true,
        hasWebGPU: true,
        hardwareConcurrency: 8
    };

    const caps1 = probePlatformCapabilities(mockEnvThreaded);
    assert.equal(caps1.browser.name, 'Chrome');
    assert.equal(caps1.browser.majorVersion, '120');
    assert.equal(caps1.os, 'macOS');
    assert.equal(caps1.deviceClass, 'desktop');
    assert.equal(caps1.wasmThreads.supported, true);
    assert.equal(caps1.wasmThreads.status, 'enabled');
    assert.equal(caps1.webgpu.supported, true);
    assert.equal(caps1.webgpu.status, 'available');
    // Ensure no hardware serial or specific device ID exists
    assert.equal(caps1.deviceId, undefined);
    assert.equal(caps1.serialNumber, undefined);

    // Simulated browser without isolation (e.g. Safari or default unisolated page)
    const mockEnvFallback = {
        userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
        crossOriginIsolated: false,
        hasSharedArrayBuffer: false,
        hasWebGPU: false
    };

    const caps2 = probePlatformCapabilities(mockEnvFallback);
    assert.equal(caps2.browser.name, 'Safari');
    assert.equal(caps2.os, 'iOS');
    assert.equal(caps2.deviceClass, 'mobile');
    assert.equal(caps2.wasmThreads.supported, false);
    assert.equal(caps2.wasmThreads.status, 'fallback_single_thread');
    assert.equal(caps2.webgpu.supported, false);
    assert.equal(caps2.webgpu.status, 'fallback_wasm');
});

test('redaction strictly strips faces, tokens, vectors and device identifiers', () => {
    const rawReportWithSensitiveData = {
        browser: {name: 'Chrome', version: '120'},
        os: 'macOS',
        deviceClass: 'desktop',
        cacheState: 'cold',
        sampleCount: 10,
        modelVersions: {
            detector: 'yunet640-similarity5-rgb255-v1',
            recognizer: 'sface-int8bq-fb143eea'
        },
        p50_ms: 18.5,
        p95_ms: 24.2,
        // Sensitive leaked fields that must be purged:
        face: 'data:image/jpeg;base64,/9j/4AAQSkZJRg...',
        faceImage: new Uint8Array([255, 216, 255]),
        token: 'fi_tok_abcdef1234567890',
        authHeader: 'Bearer fi_tok_secret',
        vector: Array(128).fill(0.088388),
        embedding: [0.1, 0.2, 0.3],
        deviceId: 'camera-d41d8cd98f00b204e9800998ecf8427e',
        device_id: 'camera-987654321',
        hardwareSerial: 'SERIAL123456',
        nested: {
            face_token: 'secret',
            template_vector: Array(128).fill(0.05),
            allowedMeta: 'safe-value'
        }
    };

    const redacted = redactBenchmarkReport(rawReportWithSensitiveData);

    // Verify sensitive keys are absent
    const serialized = JSON.stringify(redacted);
    assert.ok(!serialized.includes('fi_tok_'));
    assert.ok(!serialized.includes('Bearer'));
    assert.ok(!serialized.includes('data:image'));
    assert.ok(!serialized.includes('camera-d41d'));
    assert.ok(!serialized.includes('SERIAL123456'));
    assert.equal(redacted.face, undefined);
    assert.equal(redacted.faceImage, undefined);
    assert.equal(redacted.token, undefined);
    assert.equal(redacted.authHeader, undefined);
    assert.equal(redacted.vector, undefined);
    assert.equal(redacted.embedding, undefined);
    assert.equal(redacted.deviceId, undefined);
    assert.equal(redacted.device_id, undefined);
    assert.equal(redacted.hardwareSerial, undefined);
    assert.equal(redacted.nested.face_token, undefined);
    assert.equal(redacted.nested.template_vector, undefined);
    assert.equal(redacted.nested.allowedMeta, 'safe-value');

    // Safe benchmark metrics must be preserved
    assert.equal(redacted.browser.name, 'Chrome');
    assert.equal(redacted.p50_ms, 18.5);
    assert.equal(redacted.p95_ms, 24.2);
});

test('missing measurements and unavailable APIs report not_measured without fabricating numbers', () => {
    // When no latency samples are provided (e.g. running in Node or without camera/GPU)
    const reportWithoutSamples = generateBenchmarkReport({
        platform: {
            browser: {name: 'Node', version: '20'},
            os: 'macOS',
            deviceClass: 'desktop',
            wasmThreads: {supported: false, status: 'unavailable_in_node'},
            webgpu: {supported: false, status: 'unavailable_in_node'}
        },
        resourceSizes: {
            cacheState: 'cold',
            totalTransferBytes: 22692286,
            totalDecodedBytes: 23172444,
            resources: {}
        },
        coldStartupMs: null,
        warmStartupMs: null,
        inferenceSamples: [],
        modelVersions: {
            detector: 'yunet640-similarity5-rgb255-v1',
            recognizer: 'sface-int8bq-fb143eea'
        }
    });

    assert.equal(reportWithoutSamples.sampleCount, 0);
    assert.equal(reportWithoutSamples.latencies.coldStartupMs, null);
    assert.equal(reportWithoutSamples.latencies.warmStartupMs, null);
    assert.equal(reportWithoutSamples.latencies.inference.p50, null);
    assert.equal(reportWithoutSamples.latencies.inference.p95, null);
    assert.equal(reportWithoutSamples.latencies.inference.status, 'not_measured');
    assert.equal(reportWithoutSamples.cacheState, 'cold');
    assert.equal(reportWithoutSamples.modelVersions.recognizer, 'sface-int8bq-fb143eea');
});

test('harness enforces operator consent and operates without telemetry or external uploads', async () => {
    // Unconsented run must throw
    await assert.rejects(
        () => runBrowserBenchmark({consented: false}),
        /Operator consent required/
    );

    // Consented run in test environment without live ORT returns valid capability and sizing report
    const report = await runBrowserBenchmark({
        consented: true,
        env: {
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            crossOriginIsolated: false,
            hasSharedArrayBuffer: false,
            hasWebGPU: false
        }
    });

    assert.equal(report.browser.name, 'Chrome');
    assert.equal(report.os, 'Linux');
    assert.equal(report.sampleCount, 0);
    assert.equal(report.latencies.inference.status, 'not_measured');
    assert.equal(report.modelVersions.detector, 'yunet640-similarity5-rgb255-v1');
    assert.equal(report.modelVersions.recognizer, 'sface-int8bq-fb143eea');

    // Simulated live ORT execution
    let sessionCreateCount = 0;
    let runCount = 0;
    const mockOrt = {
        InferenceSession: {
            async create() {
                sessionCreateCount++;
                return {
                    async run() {
                        runCount++;
                        return {};
                    }
                };
            }
        },
        Tensor: class {
            constructor(type, data, dims) {
                this.type = type;
                this.data = data;
                this.dims = dims;
            }
            dispose() {}
        }
    };

    let clock = 1000;
    const mockPerf = {
        now() {
            clock += 20; // 20ms per operation
            return clock;
        },
        getEntriesByType(type) {
            if (type === 'resource') {
                return [
                    {name: 'http://test/vendor/browser-face/yunet.onnx', transferSize: 100416, decodedBodySize: 100416},
                    {name: 'http://test/vendor/browser-face/sface.onnx', transferSize: 10660286, decodedBodySize: 10660286}
                ];
            }
            return [];
        }
    };

    const liveReport = await runBrowserBenchmark({
        consented: true,
        iterations: 5,
        warmup: 1,
        ortInstance: mockOrt,
        performanceApi: mockPerf,
        env: {
            userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            crossOriginIsolated: true,
            hasSharedArrayBuffer: true,
            hasWebGPU: true
        }
    });

    assert.equal(liveReport.sampleCount, 5);
    assert.equal(liveReport.latencies.inference.count, 5);
    assert.equal(liveReport.latencies.inference.status, 'measured');
    assert.ok(liveReport.latencies.inference.p50 > 0);
    assert.ok(liveReport.latencies.inference.p95 > 0);
    assert.equal(liveReport.cacheState, 'cold');
    assert.equal(liveReport.sizes.totalTransferBytes, 100416 + 10660286);
    assert.equal(sessionCreateCount, 3); // 2 cold (yunet + sface), 1 warm (yunet)
    assert.equal(runCount, 6); // 1 warmup + 5 iterations
});
