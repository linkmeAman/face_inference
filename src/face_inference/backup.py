"""Offline database snapshots; configuration and secrets are kept separately."""
import json
import os
import sqlite3
import tempfile
from contextlib import closing
from pathlib import Path

from cryptography.fernet import Fernet, InvalidToken

from .config import default_home


def safe_path(value):
    path = Path(os.path.abspath(value))
    if path.is_symlink():
        raise RuntimeError('Symlink paths are not allowed')
    return path


def check_database(db, key):
    if db.execute('PRAGMA integrity_check').fetchall() != [('ok',)]:
        raise RuntimeError('Database integrity check failed')
    row = db.execute("SELECT value FROM metadata WHERE key='encryption_check'").fetchone()
    if not row:
        raise RuntimeError('Database has no encryption-key binding')
    try:
        if Fernet(key.encode()).decrypt(row[0].encode()) != b'face-standalone-key-v1':
            raise RuntimeError('Wrong database encryption key')
    except InvalidToken as error:
        raise RuntimeError('Wrong database encryption key') from error


def snapshot(command, config_path, filename):
    config_path = (config_path or os.getenv('FACE_STANDALONE_CONFIG')
                   or default_home() / 'config.json')
    config = json.loads(safe_path(config_path).read_text())
    if (not isinstance(config['database'], str)
            or not Path(config['database']).is_absolute()
            or not isinstance(config['encryption_key'], str)):
        raise ValueError('Invalid database path or encryption key')
    database = safe_path(config['database'])
    other = safe_path(filename)
    source, destination = (database, other) if command == 'backup' else (other, database)
    for suffix in ('', '-wal', '-shm', '-journal'):
        if os.path.lexists(str(destination) + suffix):
            raise FileExistsError(f'Destination already exists: {destination}{suffix}')
    if command == 'restore':
        fd, temporary = tempfile.mkstemp(prefix='.restore-', dir=destination.parent)
        target = Path(temporary)
    else:
        fd = os.open(destination, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        target = destination
    os.close(fd)
    try:
        with closing(sqlite3.connect(source.as_uri() + '?mode=ro', uri=True)) as src:
            with closing(sqlite3.connect(target)) as dst:
                src.backup(dst)
                check_database(dst, config['encryption_key'])
        if command == 'restore':
            try:
                os.link(target, destination)
            except OSError as error:
                raise RuntimeError(
                    f'Restore needs a filesystem that supports hard links: {destination.parent}; '
                    'restore into a directory on a supported filesystem '
                    '(the server must be stopped) and move the file afterwards'
                ) from error
    except BaseException:
        if command == 'backup':
            target.unlink(missing_ok=True)
        raise
    finally:
        if command == 'restore':
            target.unlink(missing_ok=True)
