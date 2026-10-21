"""Publish existing, checksum-verified models and pinned ORT Web assets locally.

Run using the existing inference venv (onnx is required). No new weights.
SFace's stale weight input declarations are removed exactly as in app/encoder.py.
"""
import hashlib
import json
import shutil
from pathlib import Path
import onnx

ROOT = Path(__file__).resolve().parents[1]
config = json.loads((ROOT / 'pf/js/browser-face/contract.json').read_text())
destination = ROOT / 'pf/vendor/browser-face'
destination.mkdir(parents=True, exist_ok=True)
manifest = {}
for role, source_name, target_name in [
    ('recognizer', 'face_recognition_sface_2021dec_int8bq.onnx', 'sface.onnx'),
    ('detector', 'face_detection_yunet_2023mar_int8.onnx', 'yunet.onnx'),
]:
    source = ROOT / 'services/face-inference/models' / source_name
    if hashlib.sha256(source.read_bytes()).hexdigest() != config[role + '_source_sha256']:
        raise SystemExit('Model checksum mismatch: ' + role)
    model = onnx.load(str(source))
    if role == 'recognizer':
        image = [item for item in model.graph.input if item.name == 'data']
        if len(image) != 1:
            raise SystemExit('Missing recognition input')
        del model.graph.input[:]
        model.graph.input.extend(image)
    onnx.checker.check_model(model)
    target = destination / target_name
    onnx.save(model, str(target))
    manifest[target_name] = hashlib.sha256(target.read_bytes()).hexdigest()
dist = ROOT / 'node_modules/onnxruntime-web/dist'
for name in ['ort.wasm.min.mjs', 'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm']:
    shutil.copyfile(dist / name, destination / name)
(destination / 'ORT-LICENSE').write_text('ONNX Runtime Web: MIT license. https://github.com/microsoft/onnxruntime/blob/v1.24.3/LICENSE\n')
(destination / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print('Prepared verified YuNet/SFace and ORT Web assets:', destination)
