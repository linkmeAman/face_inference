/**
 * Browser Performance Measurement Metrics & Analysis
 *
 * Core calculations for browser performance benchmarking:
 * - Deterministic percentile calculations (p50, p95)
 * - Resource size calculations distinguishing transfer from decoded size
 * - Platform and isolation capability probing (WASM threads, WebGPU)
 * - Strict privacy redaction (stripping faces, tokens, vectors, device IDs)
 * - Benchmark report generation with graceful missing-measurement handling
 */

/**
 * Calculates percentile p (0 to 100) using linear interpolation.
 * Returns null for empty or invalid samples.
 */
export function calculatePercentile(samples, p) {
    if (!Array.isArray(samples) || samples.length === 0 || typeof p !== 'number') {
        return null;
    }
    const valid = samples.filter(n => typeof n === 'number' && !Number.isNaN(n));
    if (valid.length === 0) return null;
    const sorted = valid.slice().sort((a, b) => a - b);
    if (sorted.length === 1) return sorted[0];

    const rank = (p / 100) * (sorted.length - 1);
    const lower = Math.floor(rank);
    const upper = Math.ceil(rank);
    const weight = rank - lower;

    if (lower === upper) return sorted[lower];
    return sorted[lower] * (1 - weight) + sorted[upper] * weight;
}

/**
 * Computes summary latency statistics from an array of millisecond samples.
 */
export function calculateLatencyMetrics(samples) {
    if (!Array.isArray(samples) || samples.length === 0) {
        return {
            count: 0,
            min: null,
            max: null,
            mean: null,
            p50: null,
            p95: null,
            status: 'not_measured'
        };
    }
    const valid = samples.filter(n => typeof n === 'number' && !Number.isNaN(n));
    if (valid.length === 0) {
        return {
            count: 0,
            min: null,
            max: null,
            mean: null,
            p50: null,
            p95: null,
            status: 'not_measured'
        };
    }
    const count = valid.length;
    const min = Math.min(...valid);
    const max = Math.max(...valid);
    const sum = valid.reduce((acc, v) => acc + v, 0);
    const mean = sum / count;
    const p50 = calculatePercentile(valid, 50);
    const p95 = calculatePercentile(valid, 95);

    return {
        count,
        min,
        max,
        mean,
        p50,
        p95,
        status: 'measured'
    };
}

/**
 * Evaluates PerformanceResourceTiming entries for model and WASM assets.
 * Distinguishes transferred bytes from decoded bytes and evaluates cache state.
 */
export function calculateResourceSizes(resourceEntries) {
    if (!Array.isArray(resourceEntries) || resourceEntries.length === 0) {
        return {
            totalTransferBytes: 0,
            totalDecodedBytes: 0,
            cacheState: 'unknown',
            resources: {}
        };
    }

    const resources = {};
    let totalTransfer = 0;
    let totalDecoded = 0;
    let anyCold = false;
    let anyWarm = false;

    for (const entry of resourceEntries) {
        if (!entry || !entry.name) continue;
        const url = entry.name;
        const filename = url.split('/').pop().split('?')[0];

        const transferBytes = entry.transferSize ?? 0;
        const decodedBytes = entry.decodedBodySize ?? 0;
        const encodedBytes = entry.encodedBodySize ?? 0;

        resources[filename] = {
            url,
            transferBytes,
            encodedBytes,
            decodedBytes
        };

        totalTransfer += transferBytes;
        totalDecoded += decodedBytes;

        if (transferBytes > 0) {
            anyCold = true;
        } else if (decodedBytes > 0 && transferBytes === 0) {
            anyWarm = true;
        }
    }

    let cacheState = 'unknown';
    if (anyCold && !anyWarm) {
        cacheState = 'cold';
    } else if (anyWarm && !anyCold) {
        cacheState = 'warm';
    } else if (anyCold && anyWarm) {
        cacheState = 'mixed';
    }

    return {
        totalTransferBytes: totalTransfer,
        totalDecodedBytes: totalDecoded,
        cacheState,
        resources
    };
}

/**
 * Probes browser platform, OS, device classification, and acceleration support.
 * Never collects or returns device identifiers, hardware serials, or MAC addresses.
 */
export function probePlatformCapabilities(env = {}) {
    const userAgent = env.userAgent || (typeof navigator !== 'undefined' ? navigator.userAgent : '') || '';
    const crossOriginIsolated = env.crossOriginIsolated ?? (typeof window !== 'undefined' ? Boolean(window.crossOriginIsolated) : false);
    const hasSharedArrayBuffer = env.hasSharedArrayBuffer ?? (typeof SharedArrayBuffer !== 'undefined');
    const hasWebGPU = env.hasWebGPU ?? (typeof navigator !== 'undefined' && Boolean(navigator.gpu));
    const hardwareConcurrency = env.hardwareConcurrency ?? (typeof navigator !== 'undefined' ? navigator.hardwareConcurrency : null);

    // Parse browser family & major version
    let browserName = 'Unknown';
    let majorVersion = 'Unknown';

    if (/Chrome\/(\d+)/i.test(userAgent) && !/Edg\//i.test(userAgent)) {
        browserName = 'Chrome';
        majorVersion = userAgent.match(/Chrome\/(\d+)/i)[1];
    } else if (/Edg\/(\d+)/i.test(userAgent)) {
        browserName = 'Edge';
        majorVersion = userAgent.match(/Edg\/(\d+)/i)[1];
    } else if (/Firefox\/(\d+)/i.test(userAgent)) {
        browserName = 'Firefox';
        majorVersion = userAgent.match(/Firefox\/(\d+)/i)[1];
    } else if (/Version\/(\d+).*Safari/i.test(userAgent)) {
        browserName = 'Safari';
        majorVersion = userAgent.match(/Version\/(\d+)/i)[1];
    } else if (/Node\.js|node/i.test(userAgent) || (typeof process !== 'undefined' && process.versions?.node)) {
        browserName = 'Node';
        majorVersion = typeof process !== 'undefined' ? process.versions?.node?.split('.')[0] : 'Unknown';
    }

    // Parse OS family
    let os = 'Unknown';
    if (/iPhone|iPad|iPod/i.test(userAgent)) {
        os = 'iOS';
    } else if (/Android/i.test(userAgent)) {
        os = 'Android';
    } else if (/Macintosh|Mac OS X/i.test(userAgent)) {
        os = 'macOS';
    } else if (/Windows NT/i.test(userAgent)) {
        os = 'Windows';
    } else if (/Linux/i.test(userAgent)) {
        os = 'Linux';
    }

    // Classify generic device class without device IDs
    let deviceClass = 'desktop';
    if (/Mobile|Android|iPhone/i.test(userAgent)) {
        deviceClass = 'mobile';
    } else if (/iPad|Tablet/i.test(userAgent)) {
        deviceClass = 'tablet';
    }

    // WASM threading capability assessment
    // Requires both crossOriginIsolated (COOP/COEP) and SharedArrayBuffer
    const wasmThreadsSupported = Boolean(crossOriginIsolated && hasSharedArrayBuffer);
    const wasmThreads = {
        supported: wasmThreadsSupported,
        status: wasmThreadsSupported ? 'enabled' : 'fallback_single_thread',
        threads: wasmThreadsSupported ? (hardwareConcurrency || 4) : 1
    };

    // WebGPU capability assessment
    const webgpu = {
        supported: Boolean(hasWebGPU),
        status: hasWebGPU ? 'available' : 'fallback_wasm'
    };

    return {
        browser: {
            name: browserName,
            majorVersion
        },
        os,
        deviceClass,
        crossOriginIsolated,
        wasmThreads,
        webgpu
    };
}

/**
 * Strict privacy redactor: recursively removes faces, tokens, vectors,
 * camera/device identifiers, and secret credentials.
 */
export function redactBenchmarkReport(obj) {
    if (obj === null || obj === undefined) return obj;

    const SENSITIVE_KEY_PATTERNS = [
        /face/i,
        /token/i,
        /secret/i,
        /password/i,
        /auth/i,
        /vector/i,
        /embedding/i,
        /device_?id/i,
        /camera_?id/i,
        /serial/i,
        /hardware/i,
        /uuid/i,
        /subject/i,
        /person/i
    ];

    function isSensitiveKey(key) {
        return SENSITIVE_KEY_PATTERNS.some(pattern => pattern.test(key));
    }

    function isSensitiveValue(val) {
        if (typeof val === 'string') {
            if (val.startsWith('data:image')) return true;
            if (/fi_tok_/i.test(val)) return true;
            if (/Bearer\s+/i.test(val)) return true;
            if (/camera-[a-f0-9]{8,}/i.test(val)) return true;
        }
        // Biometric embeddings / vectors (typically 128 float arrays)
        if (Array.isArray(val) && val.length >= 16 && val.every(item => typeof item === 'number')) {
            return true;
        }
        // Binary pixel buffers (e.g. Uint8Array representing image)
        if (typeof ArrayBuffer !== 'undefined' && (val instanceof Uint8Array || val instanceof Float32Array)) {
            return true;
        }
        return false;
    }

    if (Array.isArray(obj)) {
        return obj.filter(item => !isSensitiveValue(item)).map(item => redactBenchmarkReport(item));
    }

    if (typeof obj === 'object') {
        const result = {};
        for (const [key, value] of Object.entries(obj)) {
            if (isSensitiveKey(key)) continue;
            if (isSensitiveValue(value)) continue;
            result[key] = redactBenchmarkReport(value);
        }
        return result;
    }

    return obj;
}

/**
 * Formats a clean, structured benchmark report.
 * Explicitly marks missing measurements as not_measured without fabricating data.
 */
export function generateBenchmarkReport(input = {}) {
    const platform = input.platform || probePlatformCapabilities();
    const resourceSizes = input.resourceSizes || {
        totalTransferBytes: 0,
        totalDecodedBytes: 0,
        cacheState: 'not_measured',
        resources: {}
    };

    const inferenceMetrics = calculateLatencyMetrics(input.inferenceSamples || []);
    const coldStartupMs = typeof input.coldStartupMs === 'number' ? input.coldStartupMs : null;
    const warmStartupMs = typeof input.warmStartupMs === 'number' ? input.warmStartupMs : null;

    const rawReport = {
        benchmarkVersion: '1.0.0',
        timestamp: new Date().toISOString(),
        browser: platform.browser,
        os: platform.os,
        deviceClass: platform.deviceClass,
        platformCapabilities: {
            crossOriginIsolated: platform.crossOriginIsolated ?? false,
            wasmThreads: platform.wasmThreads,
            webgpu: platform.webgpu
        },
        modelVersions: input.modelVersions || {
            detector: 'yunet640-similarity5-rgb255-v1',
            recognizer: 'sface-int8bq-fb143eea'
        },
        cacheState: resourceSizes.cacheState || 'unknown',
        sampleCount: inferenceMetrics.count,
        latencies: {
            coldStartupMs,
            warmStartupMs,
            inference: inferenceMetrics
        },
        sizes: {
            totalTransferBytes: resourceSizes.totalTransferBytes,
            totalDecodedBytes: resourceSizes.totalDecodedBytes,
            resources: resourceSizes.resources
        }
    };

    return redactBenchmarkReport(rawReport);
}
