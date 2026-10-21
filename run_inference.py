#!/usr/bin/env python3
"""Run the unchanged inference app using this bundle's independent configuration."""
import argparse
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys

ROOT = Path(__file__).resolve().parent
SERVICE = ROOT / 'services/face-inference'
RUNTIME = ROOT / 'runtime'


def prepare():
    RUNTIME.mkdir(mode=0o700, exist_ok=True)
    model_dir = SERVICE / 'models'
    for name in ('calibration-approved.json', 'pad-calibration-approved.json'):
        data = json.loads((SERVICE / 'calibration' / name).read_text())
        for item in data.get('files', []):
            model = model_dir / Path(item['path']).name
            if not model.is_file():
                raise RuntimeError('Missing bundled model: ' + model.name)
            item['path'] = str(model)
        destination = RUNTIME / name
        destination.write_text(json.dumps(data, indent=2) + '\n')
        destination.chmod(0o600)
    path = RUNTIME / 'settings.json'
    if path.exists():
        settings = json.loads(path.read_text())
    else:
        settings = {'FACE_INFERENCE_SHARED_SECRET': secrets.token_hex(32)}
    settings.update({
        'FACE_INFERENCE_HOST': '127.0.0.1',
        'FACE_INFERENCE_PORT': settings.get('FACE_INFERENCE_PORT', '6121'),
        'FACE_MODEL_DIR': str(model_dir),
        'FACE_MODEL_MANIFEST': str(model_dir / 'MODEL-MANIFEST.json'),
        'FACE_YUNET_MODEL': str(model_dir / 'face_detection_yunet_2023mar_int8.onnx'),
        'FACE_SFACE_MODEL': str(model_dir / 'face_recognition_sface_2021dec_int8bq.onnx'),
        'FACE_PAD_MODEL': str(model_dir / 'anti-spoof-mn3.onnx'),
        'FACE_CALIBRATION_FILE': str(RUNTIME / 'calibration-approved.json'),
        'FACE_PAD_CALIBRATION_FILE': str(RUNTIME / 'pad-calibration-approved.json'),
        'FACE_MODEL_VERSION': 'opencv-zoo-yunet-sface-int8-omz-anti-spoof-mn3-opencv-preprocess-v2',
        'FACE_MODEL_VERSION_ID': '2',
        'FACE_RECOGNITION_PROVIDER_ID': 'local-onnx',
        'FACE_RECOGNITION_PROVIDER_VERSION': '2',
        'FACE_ACCEPT_THRESHOLD': '0.90',
        'FACE_ENROLLMENT_VERIFY_THRESHOLD': '0.90',
        'FACE_MIN_MARGIN': '0.20',
        'FACE_ENROLLMENT_TEMPLATES': '3',
        'FACE_PILOT_GEOMETRY_DEBUG': '1',
        'FACE_EXPERIMENT_LOGGING_ENABLED': '0',
    })
    if not settings.get('FACE_INFERENCE_SHARED_SECRET'):
        raise RuntimeError('Independent shared token is required')
    temporary = RUNTIME / ('settings-' + secrets.token_hex(8) + '.tmp')
    with open(temporary, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
        stream.write(json.dumps(settings, indent=2) + '\n')
    temporary.replace(path)
    return settings


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--prepare', action='store_true', help='Prepare private portable settings without starting a server')
    parser.add_argument('--check', action='store_true', help='Verify dependencies, model hashes and relocated policies without binding a port')
    parser.add_argument('--port', type=int, help='Independent loopback port (default 6121)')
    args = parser.parse_args()
    if args.port is not None and not 1024 <= args.port <= 65535:
        parser.error('Port must be 1024–65535')
    settings = prepare()
    if args.port:
        settings['FACE_INFERENCE_PORT'] = str(args.port)
    environment = {k: v for k, v in os.environ.items() if not k.startswith(('FACE_', 'BROWSER_FACE_'))}
    environment.update(settings)
    environment.update(PYTHONPATH=str(SERVICE), PYTHONDONTWRITEBYTECODE='1',
        OMP_NUM_THREADS='1', OPENBLAS_NUM_THREADS='1', MKL_NUM_THREADS='1')
    if args.prepare:
        print('Prepared private settings in runtime/settings.json; token is not printed.')
        return 0
    if args.check:
        result = subprocess.run([sys.executable, str(SERVICE / 'tools/verify_runtime.py')], cwd=ROOT, env=environment)
        if result.returncode:
            return result.returncode
        check = '''import json
from app.settings import Settings
from app.matcher import OnnxFaceMatcher
s=Settings.from_environment()
if not (s.model_integrity_verified and s.calibration_approved and s.pad_calibration_approved):
    raise SystemExit('FAIL: model or policy validation failed: '+str((s.model_error,s.calibration_error,s.pad_calibration_error)))
m=OnnxFaceMatcher(s)
print(json.dumps({'status':'ready','model_version':s.model_version,'preprocessing_version':s.preprocessing_version,'independent_port':s.port,'experiment_logging':s.experiment_logging_enabled,'model_health':m.health()}))
'''
        return subprocess.run([sys.executable, '-c', check], cwd=ROOT, env=environment).returncode
    os.chdir(ROOT)
    os.execve(sys.executable, [sys.executable, '-m', 'app.main'], environment)


if __name__ == '__main__':
    sys.exit(main())
