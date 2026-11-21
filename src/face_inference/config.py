import base64
import binascii
import hashlib
import ipaddress
import json
import math
import os
from pathlib import Path
import re
import secrets
import sqlite3
import sys
import tempfile
from zoneinfo import ZoneInfo
from urllib.parse import urlsplit

from cryptography.fernet import Fernet

ROOT = Path(__file__).resolve().parent / 'web'


def default_home():
    if 'FACE_INFERENCE_HOME' in os.environ:
        return Path(os.environ['FACE_INFERENCE_HOME']).resolve()
    legacy = Path('.face-inference')
    if legacy.is_dir():
        home = legacy.resolve()
        print(f'Using legacy data directory: {home}', file=sys.stderr)
        return home
    if sys.platform == 'darwin':
        home = Path.home() / 'Library' / 'Application Support'
    elif sys.platform == 'win32':
        home = Path(os.getenv('APPDATA') or Path.home() / 'AppData' / 'Roaming')
    else:
        home = Path(os.getenv('XDG_DATA_HOME') or Path.home() / '.local' / 'share')
    return (home / 'face-inference').resolve()


def load_config(path=None, *, host=None, port=None):
    if not path:
        path = os.getenv('FACE_STANDALONE_CONFIG')
        if path is None:
            path = default_home() / 'config.json'
    path = Path(path).resolve()
    if not path.exists():
        path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        database = path.parent / 'faces.sqlite3'
        if database.exists():
            raise RuntimeError('Existing database has no configuration; restore its original encryption key')
        config = dict(version=1, host='127.0.0.1', port=6122, database=str(database),
            api_token=secrets.token_urlsafe(32), encryption_key=Fernet.generate_key().decode(),
            session_secret=base64.urlsafe_b64encode(secrets.token_bytes(32)).decode(),
            mode='EVALUATION', duplicate_threshold=0.85, recognition_threshold=0.65,
            retention_days=30, policy_file='', max_people=1000, timezone='Asia/Kolkata',
            capture_source='unvalidated-camera', approved_collection_ids=[], allowed_origins=[],
            rate_limit_per_minute=120, trusted_proxies=[])
        with open(path, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
            json.dump(config, stream, indent=2)
            stream.write('\n')
    config = json.loads(path.read_text())
    if 'session_secret' not in config:
        with sqlite3.connect(str(path) + '.lock', timeout=30) as lock:
            lock.execute('BEGIN EXCLUSIVE')
            config = json.loads(path.read_text())
            if 'session_secret' not in config:
                config['session_secret'] = base64.urlsafe_b64encode(secrets.token_bytes(32)).decode()
                fd, temporary = tempfile.mkstemp(dir=path.parent, prefix='.config-', suffix='.tmp')
                try:
                    with os.fdopen(fd, 'w') as stream:
                        json.dump(config, stream, indent=2)
                        stream.write('\n')
                    os.replace(temporary, path)
                finally:
                    if os.path.exists(temporary):
                        os.unlink(temporary)
            config = json.loads(path.read_text())
    try:
        secret = config['session_secret']
        if not isinstance(secret, str) or len(base64.b64decode(secret, altchars=b'-_', validate=True)) < 32:
            raise ValueError
    except (ValueError, binascii.Error):
        raise RuntimeError('Invalid session secret') from None
    config['host'] = host if host is not None else os.getenv('FACE_INFERENCE_HOST', config.get('host', '127.0.0.1'))
    port = port if port is not None else os.getenv('FACE_INFERENCE_PORT')
    if port is not None:
        try:
            config['port'] = int(port) if isinstance(port, str) else port
        except ValueError:
            raise RuntimeError('Invalid config: port') from None
    config.setdefault('port', 6122)
    origins = os.getenv('FACE_INFERENCE_ALLOWED_ORIGINS')
    if origins is not None:
        config['allowed_origins'] = [origin.strip() for origin in origins.split(',') if origin.strip()]
    rate = os.getenv('FACE_INFERENCE_RATE_LIMIT')
    if rate is not None:
        try:
            config['rate_limit_per_minute'] = int(rate)
        except ValueError:
            raise RuntimeError('Invalid config: rate_limit_per_minute') from None
    config.setdefault('rate_limit_per_minute', 120)
    proxies = os.getenv('FACE_INFERENCE_TRUSTED_PROXIES')
    if proxies is not None:
        config['trusted_proxies'] = [proxy.strip() for proxy in proxies.split(',') if proxy.strip()]
    proxies = config.setdefault('trusted_proxies', [])
    if not isinstance(proxies, list):
        raise RuntimeError('Invalid config: trusted_proxies')
    for proxy in proxies:
        try:
            if not isinstance(proxy, str):
                raise ValueError
            ipaddress.ip_network(proxy, strict=False)
        except ValueError:
            raise RuntimeError('Invalid config: trusted_proxies') from None
    host = config['host']
    if not isinstance(host, str):
        raise RuntimeError('Invalid config: host')
    try:
        ipaddress.ip_address(host)
    except ValueError:
        labels = host.removesuffix('.').split('.')
        if len(host.removesuffix('.')) > 253 or host.replace('.', '').isdigit() or any(
            not re.fullmatch(r'[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?', label) for label in labels
        ):
            raise RuntimeError('Invalid config: host') from None
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
        try:
            parsed = urlsplit(origin)
            host = parsed.hostname
            _ = parsed.port
            valid_host = host and re.fullmatch(r'(?:\[[0-9a-fA-F:.]+\]|[a-zA-Z0-9.-]+)(?::[0-9]+)?', parsed.netloc)
            valid_scheme = parsed.scheme == 'https' or (parsed.scheme == 'http' and host in ('localhost', '127.0.0.1', '::1'))
            if (not valid_host or not valid_scheme or parsed.username is not None or parsed.password is not None
                    or parsed.netloc.endswith(':') or origin != parsed.scheme + '://' + parsed.netloc
                    or any(c.isspace() or ord(c) < 32 or ord(c) == 127 for c in origin)):
                raise ValueError('Invalid origin')
        except ValueError:
            raise RuntimeError('Additional browser origins must be exact HTTPS origins or local HTTP origins') from None
    for name in ('duplicate_threshold', 'recognition_threshold'):
        value = config.get(name)
        if isinstance(value, bool) or not isinstance(value, (float, int)) or not math.isfinite(value) or not -1 <= value <= 1:
            raise RuntimeError('Invalid server threshold: ' + name)
    for name, minimum, maximum in [('port', 1, 65535), ('retention_days', 1, 365), ('max_people', 1, 10000), ('rate_limit_per_minute', 1, 100000)]:
        value = config.get(name)
        if isinstance(value, bool) or not isinstance(value, int) or not minimum <= value <= maximum:
            raise RuntimeError('Invalid config: ' + name)
    if not isinstance(config.get('database'), str) or not Path(config['database']).is_absolute():
        raise RuntimeError('Database must use an absolute private filesystem path')
    config['_path'] = str(path)
    return config


def contract(root=ROOT):
    return json.loads((root / 'browser-face/contract.json').read_text())


def verify_assets(root=ROOT):
    data = contract(root)
    assets = root / 'vendor/browser-face'
    manifest_path = assets / 'manifest.json'
    if not manifest_path.is_file():
        raise RuntimeError('Missing browser asset manifest')
    manifest = json.loads(manifest_path.read_text())
    files = manifest.get('files', {})
    required = {'sface.onnx', 'yunet.onnx', 'ort.wasm.min.mjs', 'ort-wasm-simd-threaded.mjs', 'ort-wasm-simd-threaded.wasm'}
    if not required.issubset(files):
        raise RuntimeError('Missing browser asset checksum')
    for name, info in files.items():
        expected = info.get('sha256') if isinstance(info, dict) else info
        target = assets / name
        if not target.is_file() or target.is_symlink():
            raise RuntimeError('Missing browser runtime asset: ' + name)
        if Path(name).name != name or hashlib.sha256(target.read_bytes()).hexdigest() != expected:
            raise RuntimeError('Prepared face asset checksum mismatch')
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
