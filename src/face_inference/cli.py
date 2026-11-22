#!/usr/bin/env python3
"""Run the independent face application; uses Python, SQLite and browser WASM."""
import argparse
import json
import logging
import os
import sqlite3
import sys
from pathlib import Path

from .assets import fetch, models_dir, verify
from .config import contract, load_config


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', nargs='?', default='serve', choices=('init', 'serve', 'check', 'token', 'purge', 'export', 'models', 'backup', 'restore'))
    parser.add_argument('action', nargs='?', choices=('fetch', 'verify', 'path'))
    parser.add_argument('--config', help='Private standalone JSON configuration path')
    parser.add_argument('--host', help='Override listener IP address or hostname for this run')
    parser.add_argument('--port', help='Override listener port for this run (1–65535)')
    parser.add_argument('--log-level', help='Request log level: DEBUG, INFO, WARNING or ERROR')
    parser.add_argument('--collection', help='Collection to export for calibration')
    parser.add_argument('--output', help='New private export or backup file')
    parser.add_argument('--input', help='Database backup to restore')
    parser.add_argument('--base-url', help='Override base download URL for model assets')
    parser.add_argument('--from-dir', help='Copy model assets from a local directory')
    args = parser.parse_args()
    if args.command == 'serve':
        level = (args.log_level if args.log_level is not None else os.environ.get('FACE_INFERENCE_LOG_LEVEL', 'WARNING')).upper()
        if level not in ('DEBUG', 'INFO', 'WARNING', 'ERROR'):
            print('Invalid log level; use DEBUG, INFO, WARNING or ERROR', file=sys.stderr)
            return 1
        logger = logging.getLogger('face-standalone')
        handler = logging.StreamHandler(sys.stderr)
        handler.setFormatter(logging.Formatter('%(message)s'))
        logger.handlers[:] = [handler]
        logger.setLevel(level)
        logger.propagate = False
    if args.command in ('backup', 'restore'):
        filename = args.output if args.command == 'backup' else args.input
        if not filename:
            parser.error(f'{args.command} requires ' + ('--output' if args.command == 'backup' else '--input'))
        from .backup import snapshot
        try:
            snapshot(args.command, args.config, filename)
        except (OSError, ValueError, KeyError, TypeError, sqlite3.Error, RuntimeError) as error:
            print(' '.join(str(error).splitlines()), file=sys.stderr)
            return 1
        return 0
    try:
        config = load_config(args.config, host=args.host, port=args.port)
    except (RuntimeError, ValueError) as error:
        print(' '.join(str(error).splitlines()), file=sys.stderr)
        return 1
    if args.command == 'token':
        print(config['api_token'])
        return 0
    if args.command == 'models':
        target_dir = models_dir(config)
        if args.action == 'path':
            print(str(target_dir))
            return 0
        if args.action == 'verify':
            try:
                result = verify(target_dir)
            except RuntimeError as error:
                print(f"Error: {' '.join(str(error).splitlines())}", file=sys.stderr)
                return 1
            # dict(): VerifyResult is falsy on FAIL and json.dumps renders a falsy dict as {}
            print(json.dumps(dict(result), indent=2))
            return 0 if result['status'] == 'PASS' else 1
        if args.action == 'fetch':
            try:
                fetch(target_dir, base_url=args.base_url, from_dir=args.from_dir)
                return 0
            except RuntimeError as error:
                print(f"Error: {' '.join(str(error).splitlines())}", file=sys.stderr)
                return 1
            except Exception as error:
                print(f'Error fetching models: {error}', file=sys.stderr)
                return 1
        parser.error('models requires an action: fetch, verify, or path')
    target_models = models_dir(config)
    res = verify(target_models)
    if res['status'] != 'PASS':
        if args.command == 'serve':
            print('Run: face-inference models fetch')
            return 1
        if args.command in ('init', 'check'):
            print(json.dumps({'status': 'assets-missing', 'home': str(Path(config['_path']).parent), 'config': config['_path'], 'database': config['database']}))
            return 0
    model = contract()
    from .service import FaceService
    from .storage import SQLiteRepository
    repository = SQLiteRepository(config['database'], config['encryption_key'])
    service = FaceService(repository, config, model)
    if args.command == 'export':
        if not args.collection or not args.output:
            parser.error('export requires --collection and --output')
        destination = Path(args.output).resolve()
        rows = service.export_records(args.collection)
        with open(destination, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
            for row in rows:
                stream.write(json.dumps(row, allow_nan=False) + '\n')
        print(json.dumps({'exported_captures':len(rows), 'output':str(destination)}))
        return 0
    if args.command == 'purge':
        print(json.dumps({'erased_expired_records': service.purge()}))
        return 0
    if args.command in ('init', 'check'):
        print(json.dumps({'status': 'ready', 'home': str(Path(config['_path']).parent), 'config': config['_path'], 'database': config['database'],
            'mode': config['mode'], 'model_version': model['model_version']}))
        return 0
    from .server import FaceHTTPServer
    if config['host'] not in ('127.0.0.1', '::1', 'localhost'):
        print('listening on a non-loopback address: put TLS in front (camera needs HTTPS)', file=sys.stderr)
    server = FaceHTTPServer((config['host'], config['port']), service)
    print('Face service: http://' + config['host'] + ':' + str(server.server_port), flush=True)
    print('Use python -m face_inference token in your terminal to get the private API token.', flush=True)
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
