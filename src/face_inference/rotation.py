"""Offline, crash-recoverable database encryption key rotation."""
import json
import os
import sqlite3
import sys
import tempfile
from contextlib import closing
from datetime import datetime, timezone

from cryptography.fernet import Fernet, InvalidToken

from .backup import check_database, safe_path, snapshot
from .config import default_home

CHECK = b'face-standalone-key-v1'


def config_file(path):
    return safe_path(path or os.getenv('FACE_STANDALONE_CONFIG') or default_home() / 'config.json')


def write_config(path, config):
    safe_path(path)
    fd, temporary = tempfile.mkstemp(prefix='.rotation-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as stream:
            json.dump(config, stream, indent=2)
            stream.write('\n')
            stream.flush()
            os.fsync(stream.fileno())
        safe_path(path)
        os.replace(temporary, path)
        if sys.platform != 'win32':
            directory = os.open(path.parent, os.O_RDONLY)
            try:
                os.fsync(directory)
            finally:
                os.close(directory)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def recover(config_path=None):
    path = config_file(config_path)
    if not path.exists():
        return
    config = json.loads(path.read_text())
    if 'encryption_key_next' not in config:
        return
    database = safe_path(config['database'])
    with closing(sqlite3.connect(database.as_uri() + '?mode=ro', uri=True)) as db:
        row = db.execute("SELECT value FROM metadata WHERE key='encryption_check'").fetchone()
    if not row:
        raise RuntimeError('Database has no encryption-key binding; recovery stopped')
    matches = []
    for key in (config['encryption_key'], config['encryption_key_next']):
        try:
            matches.append(Fernet(key.encode()).decrypt(row[0].encode()) == CHECK)
        except (InvalidToken, ValueError, TypeError, AttributeError):
            matches.append(False)
    if matches == [True, False]:
        del config['encryption_key_next']
        message = 'discarded an unused pending key'
    elif matches == [False, True]:
        config['encryption_key'] = config.pop('encryption_key_next')
        message = 'completed an interrupted key rotation'
    else:
        raise RuntimeError('Cannot determine database encryption key; recovery stopped')
    write_config(path, config)
    print(message)


def reencrypt(db, old, new):
    for table, column in (('people', 'encrypted_template'), ('captures', 'encrypted_observations')):
        rows = db.execute(f'SELECT rowid, {column} FROM {table} WHERE {column} IS NOT NULL')
        for rowid, value in rows:
            db.execute(f'UPDATE {table} SET {column}=? WHERE rowid=?',
                       (new.encrypt(old.decrypt(value)), rowid))
    db.execute("UPDATE metadata SET value=? WHERE key='encryption_check'",
               (new.encrypt(CHECK).decode(),))


def rotate(config_path=None, *, no_backup=False):
    recover(config_path)
    path = config_file(config_path)
    config = json.loads(path.read_text())
    database = safe_path(config['database'])
    old = Fernet(config['encryption_key'].encode())
    with closing(sqlite3.connect(database.as_uri() + '?mode=ro', uri=True)) as db:
        check_database(db, config['encryption_key'])
    if not no_backup:
        stamp = datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%fZ')
        snapshot('backup', path, str(database) + '.pre-rotation-' + stamp)
    config['encryption_key_next'] = Fernet.generate_key().decode()
    write_config(path, config)
    new = Fernet(config['encryption_key_next'].encode())
    with closing(sqlite3.connect(database)) as db:
        db.execute('PRAGMA synchronous=FULL')
        db.execute('BEGIN IMMEDIATE')
        try:
            reencrypt(db, old, new)
            db.commit()
        except BaseException:
            db.rollback()
            raise
        if db.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone()[0]:
            raise RuntimeError('Database checkpoint failed; stop all processes and retry recovery')
    config['encryption_key'] = config.pop('encryption_key_next')
    write_config(path, config)
    print('Encryption key rotated')
