from contextlib import contextmanager, closing
import json
from pathlib import Path
import sqlite3
import time

from cryptography.fernet import Fernet, InvalidToken


class SQLiteRepository:
    """Storage boundary: service operations receive a transaction connection."""
    def __init__(self, path, encryption_key):
        self.path = Path(path)
        self.path.parent.mkdir(parents=True, mode=0o700, exist_ok=True)
        self.cipher = Fernet(encryption_key.encode())
        with closing(self.connect()) as db, db:
            db.execute('PRAGMA journal_mode=WAL')
            db.executescript(Path(__file__).with_name('schema.sql').read_text())
            version = db.execute("SELECT value FROM metadata WHERE key='schema_version'").fetchone()
            if version and version[0] != '1':
                raise RuntimeError('Unsupported face database schema version')
            check = db.execute("SELECT value FROM metadata WHERE key='encryption_check'").fetchone()
            if check:
                try:
                    if self.cipher.decrypt(check[0].encode()) != b'face-standalone-key-v1':
                        raise RuntimeError('Wrong database encryption key')
                except InvalidToken as error:
                    raise RuntimeError('Wrong database encryption key; restore the original key') from error
            else:
                if db.execute('SELECT COUNT(*) FROM people').fetchone()[0]:
                    raise RuntimeError('Existing face database has no encryption-key binding')
                db.execute('INSERT INTO metadata VALUES(?,?)', ('encryption_check', self.cipher.encrypt(b'face-standalone-key-v1').decode()))
                db.execute("INSERT OR REPLACE INTO metadata VALUES('schema_version','1')")
        self.path.chmod(0o600)

    def connect(self):
        db = sqlite3.connect(self.path, timeout=10)
        db.row_factory = sqlite3.Row
        db.execute('PRAGMA foreign_keys=ON')
        db.execute('PRAGMA busy_timeout=10000')
        db.execute('PRAGMA secure_delete=ON')
        return db

    @contextmanager
    def transaction(self):
        db = self.connect()
        try:
            db.execute('BEGIN IMMEDIATE')
            yield db
            db.commit()
        except BaseException:
            db.rollback()
            raise
        finally:
            db.close()

    def checkpoint(self):
        with closing(self.connect()) as db:
            db.execute('PRAGMA busy_timeout=0')
            for attempt in range(6):
                if db.execute('PRAGMA wal_checkpoint(TRUNCATE)').fetchone()[0] == 0:
                    return True
                if attempt < 5:
                    time.sleep(0.05)
        return False

    def encrypt(self, value):
        return self.cipher.encrypt(json.dumps(value, separators=(',', ':'), allow_nan=False).encode())

    def decrypt(self, value):
        try:
            return json.loads(self.cipher.decrypt(value))
        except (InvalidToken, ValueError, TypeError) as error:
            raise RuntimeError('Stored face data cannot be decrypted; recognition is unavailable') from error
