#!/usr/bin/env python3
"""Run the independent face application; uses Python, SQLite and browser WASM."""

import argparse
import json
import os
import sys

from .assets import fetch, models_dir, verify
from .config import contract, load_config


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        'command',
        nargs='?',
        default='serve',
        choices=('init', 'serve', 'check', 'token', 'purge', 'export', 'models'),
    )
    parser.add_argument('action', nargs='?', choices=('fetch', 'verify', 'path'))
    parser.add_argument('--config', help='Private standalone JSON configuration path')
    parser.add_argument('--port', type=int, help='Override private listener port for this run')
    parser.add_argument('--collection', help='Collection to export for calibration')
    parser.add_argument('--output', help='New private JSONL export file')
    parser.add_argument('--base-url', help='Override base download URL for model assets')
    parser.add_argument('--from-dir', help='Copy model assets from a local directory')
    args = parser.parse_args()

    config = load_config(args.config)
    if args.port is not None:
        if not 1024 <= args.port <= 65535:
            parser.error('Port must be 1024–65535')
        config['port'] = args.port

    if args.command == 'token':
        print(config['api_token'])
        return 0

    if args.command == 'models':
        target_dir = models_dir(config)
        if args.action == 'path':
            print(str(target_dir))
            return 0
        if args.action == 'verify':
            result = verify(target_dir)
            print(json.dumps(result, indent=2))
            return 0 if result['status'] == 'PASS' else 1
        if args.action == 'fetch':
            try:
                fetch(target_dir, base_url=args.base_url, from_dir=args.from_dir)
                return 0
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
            print(
                json.dumps({
                    'status': 'assets-missing',
                    'config': config['_path'],
                    'database': config['database'],
                })
            )
            return 0

    model = contract()
    from .service import FaceService
    from .storage import SQLiteRepository

    repository = SQLiteRepository(config['database'], config['encryption_key'])
    service = FaceService(repository, config, model)

    if args.command == 'export':
        if not args.collection or not args.output:
            parser.error('export requires --collection and --output')
        from pathlib import Path

        destination = Path(args.output).resolve()
        rows = service.export_records(args.collection)
        with open(destination, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
            for row in rows:
                stream.write(json.dumps(row, allow_nan=False) + '\n')
        print(json.dumps({'exported_captures': len(rows), 'output': str(destination)}))
        return 0

    if args.command == 'purge':
        print(json.dumps({'erased_expired_records': service.purge()}))
        return 0

    if args.command in ('init', 'check'):
        print(
            json.dumps({
                'status': 'ready',
                'config': config['_path'],
                'database': config['database'],
                'mode': config['mode'],
                'model_version': model['model_version'],
            })
        )
        return 0

    from .server import FaceHTTPServer

    server = FaceHTTPServer((config['host'], config['port']), service)
    print('Face service: http://' + config['host'] + ':' + str(server.server_port), flush=True)
    print(
        'Use python -m face_inference token in your terminal to get the private API token.',
        flush=True,
    )
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
    return 0


if __name__ == '__main__':
    sys.exit(main())
