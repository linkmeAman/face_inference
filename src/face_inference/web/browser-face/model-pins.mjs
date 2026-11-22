export const modelPins = Object.freeze({
    'sface.onnx': '487a139eb85ccd5ff30aef7d0add7f12d2bb7abb90c5f08a7f481b452a660e00',
    'yunet.onnx': '321aa5a6afabf7ecc46a3d06bfab2b579dc96eb5c3be7edd365fa04502ad9294',
});

export async function verifyModel(url, name) {
    try {
        const pin = Object.hasOwn(modelPins, name) && modelPins[name];
        if (!/^[a-f0-9]{64}$/.test(pin || '') || !globalThis.crypto?.subtle) throw new Error();
        const response = await fetch(url);
        if (!response.ok) throw new Error();
        const bytes = await response.arrayBuffer();
        const digest = await crypto.subtle.digest('SHA-256', bytes);
        const actual = Array.from(new Uint8Array(digest), byte => byte.toString(16).padStart(2, '0')).join('');
        if (actual !== pin) throw new Error();
        return bytes;
    } catch {
        throw new Error('Model integrity verification failed');
    }
}
