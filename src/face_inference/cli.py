#!/usr/bin/env python3
"""Run the independent face application; uses Python, SQLite and browser WASM."""
import argparse
import json
import os
import sys


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('command', nargs='?', default='serve', choices=('init', 'serve', 'check', 'token', 'purge', 'export'))
    parser.add_argument('--config', help='Private standalone JSON configuration path')
    parser.add_argument('--host', help='Override listener IP address or hostname for this run')
    parser.add_argument('--port', help='Override listener port for this run (1–65535)')
    parser.add_argument('--collection', help='Collection to export for calibration')
    parser.add_argument('--output', help='New private JSONL export file')
    args = parser.parse_args()
    from .config import load_config, verify_assets
    from .service import FaceService
    from .storage import SQLiteRepository
    try:
        config = load_config(args.config, host=args.host, port=args.port)
    except (RuntimeError, ValueError) as error:
        print(' '.join(str(error).splitlines()), file=sys.stderr)
        return 1
    if args.command == 'token':
        print(config['api_token'])
        return 0
    model = verify_assets()
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
        print(json.dumps({'exported_captures':len(rows), 'output':str(destination)}))
        return 0
    if args.command == 'purge':
        print(json.dumps({'erased_expired_records': service.purge()}))
        return 0
    if args.command in ('init', 'check'):
        print(json.dumps({'status': 'ready', 'config': config['_path'], 'database': config['database'],
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
