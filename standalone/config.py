import hashlib
import json
import math
import os
from pathlib import Path
import secrets
from zoneinfo import ZoneInfo
from urllib.parse import urlsplit

from cryptography.fernet import Fernet

ROOT = Path(__file__).resolve().parents[1]


def load_config(path=None):
    path = Path(path or os.getenv('FACE_STANDALONE_CONFIG', ROOT / '.standalone/config.json')).resolve()
    if not path.exists():
        path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        database = path.parent / 'faces.sqlite3'
        if database.exists():
            raise RuntimeError('Existing database has no configuration; restore its original encryption key')
        config = dict(version=1, host='127.0.0.1', port=6122, database=str(database),
            api_token=secrets.token_urlsafe(32), encryption_key=Fernet.generate_key().decode(),
            mode='EVALUATION', duplicate_threshold=0.85, recognition_threshold=0.65,
            retention_days=30, policy_file='', max_people=1000, timezone='Asia/Kolkata',
            capture_source='unvalidated-camera', approved_collection_ids=[], allowed_origins=[])
        with open(path, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
            json.dump(config, stream, indent=2)
            stream.write('\n')
    config = json.loads(path.read_text())
    if config.get('version') != 1 or config.get('mode') not in ('COLLECT', 'EVALUATION', 'APPROVED'):
        raise RuntimeError('Unsupported standalone configuration version or mode')
    if not isinstance(config.get('api_token'), str) or len(config['api_token']) < 32:
        raise RuntimeError('API token must contain at least 32 characters')
    Fernet(config['encryption_key'].encode())
    ZoneInfo(config.get('timezone', 'Asia/Kolkata'))
    if config.get('capture_source', 'unvalidated-camera') not in ('unvalidated-camera', 'consented-live-cameras', 'public-synthetic-research', 'test-fixture'):
        raise RuntimeError('Invalid capture evidence source')
    scopes = config.get('approved_collection_ids', [])
    if not isinstance(scopes, list) or any(not isinstance(value, str) for value in scopes) or (config['mode'] == 'APPROVED' and not scopes):
        raise RuntimeError('APPROVED mode requires explicit approved_collection_ids')
    origins = config.get('allowed_origins', [])
    if not isinstance(origins, list):
        raise RuntimeError('allowed_origins must be a list')
    for origin in origins:
        if not isinstance(origin, str):
            raise RuntimeError('Invalid allowed origin')
        parsed = urlsplit(origin)
        if parsed.scheme != 'https' or not parsed.netloc or parsed.username or parsed.path or parsed.query or parsed.fragment:
            raise RuntimeError('Additional browser origins must be exact HTTPS origins')
    for name in ('duplicate_threshold', 'recognition_threshold'):
        value = config.get(name)
        if isinstance(value, bool) or not isinstance(value, (float, int)) or not math.isfinite(value) or not -1 <= value <= 1:
            raise RuntimeError('Invalid server threshold: ' + name)
    for name, minimum, maximum in [('port', 1024, 65535), ('retention_days', 1, 365), ('max_people', 1, 10000)]:
        value = config.get(name)
        if isinstance(value, bool) or not isinstance(value, int) or not minimum <= value <= maximum:
            raise RuntimeError('Invalid config: ' + name)
    if not isinstance(config.get('database'), str) or not Path(config['database']).is_absolute():
        raise RuntimeError('Database must use an absolute private filesystem path')
    config['_path'] = str(path)
    return config


def contract(root=ROOT):
    return json.loads((root / 'pf/js/browser-face/contract.json').read_text())


def verify_assets(root=ROOT):
    data = contract(root)
    assets = root / 'pf/vendor/browser-face'
    manifest = json.loads((assets / 'manifest.json').read_text())
    for name, expected in manifest.items():
        if Path(name).name != name or hashlib.sha256((assets / name).read_bytes()).hexdigest() != expected:
            raise RuntimeError('Prepared face asset checksum mismatch')
    for name in ('ort.wasm.min.mjs', 'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm'):
        if not (assets / name).is_file():
            raise RuntimeError('Missing browser runtime asset: ' + name)
    return data


def load_policy(config, model):
    result = dict(mode=config['mode'], ready=False, duplicate_threshold=config['duplicate_threshold'],
        recognition_threshold=config['recognition_threshold'], report_sha256=None)
    if config['mode'] != 'APPROVED':
        return result
    try:
        evidence = json.loads(Path(config['policy_file']).read_text())
        report_bytes = Path(evidence['report_path']).read_bytes()
        if hashlib.sha256(report_bytes).hexdigest() != evidence['report_sha256']:
            raise ValueError('Report checksum')
        report = json.loads(report_bytes)
        decisions = report['decision_evaluation']
        valid = evidence['approved'] is True and bool(evidence['approved_by']) and bool(evidence['approved_at'])
        valid = valid and evidence['source'] == report['source'] == 'consented-live-cameras'
        valid = valid and evidence['independent_validation'] is True and evidence['independent_workflow_validation'] is True
        valid = valid and evidence['identities'] >= 20 and evidence['samples'] >= 100 and not report.get('blockers')
        valid = valid and decisions['version'] == 'browser-face-decisions-v1' and decisions['complete'] is True
        valid = valid and decisions['full_validation_batches'] > 0
        valid = valid and decisions['validation']['counts']['known'] > 0 and decisions['validation']['counts']['unknown'] > 0
        for field in ('model_version', 'preprocessing_version'):
            valid = valid and evidence[field] == report[field] == model[field]
        for field in ('identities', 'samples'):
            valid = valid and report[field] == evidence[field]
        for field in ('DUPLICATE_THRESHOLD', 'ATTENDANCE_THRESHOLD'):
            value = evidence[field]
            valid = valid and not isinstance(value, bool) and isinstance(value, (float, int)) and math.isfinite(value) and -1 <= value <= 1
            valid = valid and abs(report['recommendations'][field]['threshold'] - value) < 1e-12
        if not valid:
            raise ValueError('Incomplete approval')
        result.update(ready=True, duplicate_threshold=evidence['DUPLICATE_THRESHOLD'],
            recognition_threshold=evidence['ATTENDANCE_THRESHOLD'], report_sha256=evidence['report_sha256'])
        return result
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise RuntimeError('APPROVED mode requires a valid complete live-camera policy and report') from error
