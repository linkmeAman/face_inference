"""A bounded same-origin JSON API and explicit static asset server; no PHP."""
import hmac
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import logging
import math
from pathlib import Path
import sqlite3
import threading
import time
from urllib.parse import unquote, urlsplit

from .config import ROOT
from .math import FaceError
from .service import identifier

MAX_BODY = 128 * 1024
LOGGER = logging.getLogger('face-standalone')


def strict_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate JSON field')
        result[key] = value
    return result


def reject_constant(value):
    raise ValueError('Non-finite JSON number')


def finite_float(value):
    result = float(value)
    if not math.isfinite(result):
        raise ValueError('Non-finite JSON number')
    return result


class FaceHTTPServer(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address, service, root=ROOT):
        self.service, self.root = service, Path(root)
        self.slots = threading.BoundedSemaphore(8)
        self.rate_lock, self.rates = threading.Lock(), {}
        self.assets = {'/': self.root / 'standalone/web/index.html'}
        for file in (self.root / 'standalone/web').iterdir():
            if file.suffix in ('.mjs', '.css'):
                self.assets['/' + file.name] = file
        for directory in ('pf/js/browser-face', 'pf/vendor/browser-face'):
            for file in (self.root / directory).iterdir():
                if file.is_file() and not file.is_symlink() and file.suffix in ('.mjs', '.wasm', '.onnx', '.json'):
                    self.assets['/' + directory + '/' + file.name] = file
        super().__init__(address, Handler)

    def process_request(self, request, client_address):
        if not self.slots.acquire(blocking=False):
            try:
                request.sendall(b'HTTP/1.1 503 Service Unavailable\r\nContent-Length: 0\r\nRetry-After: 1\r\nConnection: close\r\n\r\n')
            finally:
                self.shutdown_request(request)
            return
        try:
            super().process_request(request, client_address)
        except BaseException:
            self.slots.release()
            raise

    def process_request_thread(self, request, client_address):
        try:
            super().process_request_thread(request, client_address)
        finally:
            self.slots.release()

    def check_rate(self, address):
        now = time.monotonic()
        with self.rate_lock:
            # Bound retained peer keys; no request bodies or biometric data.
            self.rates = {key: record for key, record in self.rates.items() if now - record[0] < 60}
            start, count = self.rates.get(address, (now, 0))
            if count >= 120 or len(self.rates) > 1000:
                raise FaceError('RATE_LIMIT', 'Too many requests; wait a minute', 429)
            self.rates[address] = (start, count + 1)


class Handler(BaseHTTPRequestHandler):
    server_version = 'FaceStandalone/1'

    def setup(self):
        super().setup()
        self.connection.settimeout(15)

    def log_message(self, *_):
        # The default access logger can expose user-supplied URLs/query strings.
        pass

    def headers_safe(self, content_type, length):
        self.send_header('Content-Type', content_type)
        self.send_header('Content-Length', str(length))
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Referrer-Policy', 'no-referrer')
        self.send_header('Permissions-Policy', 'camera=(self), microphone=()')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self'; worker-src 'self'; connect-src 'self'; img-src 'self' blob:; object-src 'none'; frame-ancestors 'none'; base-uri 'none'")
        self.end_headers()

    def respond(self, data, status=200, error=None):
        body = json.dumps(dict(error=1 if error else 0, error_msg=str(error) if error else '',
            message=str(error) if error else data.get('message', '') if isinstance(data, dict) else '',
            code=error.code if error else '', data={} if error else data), allow_nan=False).encode()
        self.send_response(status)
        self.headers_safe('application/json; charset=utf-8', len(body))
        self.wfile.write(body)

    def authorize(self):
        expected = 'Bearer ' + self.server.service.config['api_token']
        if not hmac.compare_digest(self.headers.get('Authorization', '').encode(), expected.encode()):
            raise FaceError('AUTH_REQUIRED', 'A valid API token is required', 401)
        origin = self.headers.get('Origin')
        if origin:
            parsed = urlsplit(origin)
            host = self.headers.get('Host', '')
            local_names = ('localhost', '127.0.0.1', '[::1]', self.server.service.config['host'])
            allowed = {name + ':' + str(self.server.server_port) for name in local_names}
            explicitly_allowed = origin in self.server.service.config.get('allowed_origins', [])
            local_origin = parsed.netloc == host and host in allowed and parsed.scheme in ('http', 'https') and not parsed.path
            if not (explicitly_allowed or local_origin):
                raise FaceError('ORIGIN_REJECTED', 'Use the same-origin face service', 403)
        self.server.check_rate(self.client_address[0])

    def read_json(self):
        if self.headers.get('Transfer-Encoding'):
            raise FaceError('INVALID_BODY', 'Chunked request bodies are not supported')
        if self.headers.get_content_type() != 'application/json':
            raise FaceError('INVALID_CONTENT_TYPE', 'Use application/json', 415)
        try:
            length = int(self.headers.get('Content-Length', ''))
        except ValueError:
            raise FaceError('INVALID_BODY', 'A valid Content-Length is required', 411)
        if length < 1 or length > MAX_BODY:
            raise FaceError('BODY_LIMIT', 'JSON body must be between 1 byte and 128 KiB', 413)
        try:
            raw = self.rfile.read(length)
            if len(raw) != length:
                raise ValueError('Incomplete request')
            result = json.loads(raw, parse_constant=reject_constant, parse_float=finite_float, object_pairs_hook=strict_object)
            if not isinstance(result, dict):
                raise ValueError('Object required')
            return result
        except (ValueError, UnicodeError):
            raise FaceError('INVALID_JSON', 'A valid finite JSON object is required')

    def route(self):
        path = unquote(urlsplit(self.path).path)
        if '..' in path.split('/') or '\\' in path or '\x00' in path:
            raise FaceError('NOT_FOUND', 'Route not found', 404)
        service = self.server.service
        if self.command == 'GET' and path == '/health':
            self.respond(dict(status='ok', api_version='1', storage='sqlite', php_required=False))
            return
        if path.startswith('/api/'):
            self.authorize()
            parts = path.strip('/').split('/')
            if self.command == 'GET' and parts == ['api', 'v1', 'config']:
                self.respond(service.safe_config())
                return
            if parts[:3] == ['api', 'v1', 'collections']:
                if len(parts) == 3:
                    if self.command == 'GET':
                        self.respond(service.list_collections())
                        return
                    if self.command == 'POST':
                        body = self.read_json()
                        self.respond(service.mutate(identifier(body.get('id')), 'collection', body))
                        return
                if len(parts) >= 5:
                    collection, resource = identifier(parts[3]), parts[4]
                    if self.command == 'GET' and len(parts) == 5:
                        if resource == 'people':
                            self.respond(service.list_people(collection))
                            return
                        if resource == 'events':
                            self.respond(service.events(collection))
                            return
                    actions = {'people': 'person', 'enrollments': 'enroll', 'recognitions': 'recognize', 'observations': 'observe'}
                    if self.command == 'POST' and len(parts) == 5 and resource in actions:
                        self.respond(service.mutate(collection, actions[resource], self.read_json()))
                        return
                    if self.command == 'DELETE' and len(parts) == 7 and resource == 'people' and parts[6] == 'face':
                        self.respond(service.mutate(collection, 'delete', self.read_json(), identifier(parts[5])))
                        return
            raise FaceError('NOT_FOUND', 'API route not found', 404)
        if self.command == 'GET' and path in self.server.assets:
            file = self.server.assets[path]
            if file.is_symlink():
                raise FaceError('NOT_FOUND', 'File not found', 404)
            content = file.read_bytes()
            mime = {'.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.mjs': 'text/javascript',
                '.json': 'application/json', '.wasm': 'application/wasm'}.get(file.suffix, 'application/octet-stream')
            self.send_response(200)
            self.headers_safe(mime, len(content))
            self.wfile.write(content)
            return
        raise FaceError('NOT_FOUND', 'Route not found', 404)

    def dispatch(self):
        try:
            self.route()
        except FaceError as error:
            self.respond({}, error.status, error)
        except (BrokenPipeError, ConnectionResetError, TimeoutError):
            self.close_connection = True
        except sqlite3.OperationalError:
            self.respond({}, 503, FaceError('STORAGE_BUSY', 'Storage is temporarily unavailable; retry with the same request key', 503))
        except Exception:
            LOGGER.error('Face operation failed; no request payload logged')
            self.respond({}, 500, FaceError('SERVICE_UNAVAILABLE', 'Face operation unavailable; check private configuration and storage', 500))

    do_GET = do_POST = do_DELETE = do_OPTIONS = dispatch
