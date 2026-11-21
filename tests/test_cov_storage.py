import sqlite3

import pytest
from cryptography.fernet import Fernet

from face_inference.storage import SQLiteRepository


def test_key_binding_tamper_and_rollback(tmp_path):
    path = tmp_path / 'faces.db'
    key = Fernet.generate_key().decode()
    repo = SQLiteRepository(path, key)
    encrypted = repo.encrypt([1, 2])
    assert repo.decrypt(encrypted) == [1, 2]
    with pytest.raises(RuntimeError, match='cannot be decrypted'):
        repo.decrypt(encrypted[:-1] + b'!')
    with pytest.raises(RuntimeError, match='Wrong database encryption key'):
        SQLiteRepository(path, Fernet.generate_key().decode())
    with pytest.raises(ValueError):
        with repo.transaction() as db:
            db.execute("INSERT INTO metadata VALUES ('rollback', 'x')")
            raise ValueError('abort')
    with repo.connect() as db:
        assert db.execute("SELECT value FROM metadata WHERE key='rollback'").fetchone() is None
        db.execute("UPDATE metadata SET value='2' WHERE key='schema_version'")
        db.commit()
    with pytest.raises(RuntimeError, match='schema version'):
        SQLiteRepository(path, key)


def test_existing_unbound_database_rejected(tmp_path):
    path = tmp_path / 'faces.db'
    key = Fernet.generate_key().decode()
    SQLiteRepository(path, key)
    with sqlite3.connect(path) as db:
        db.execute("DELETE FROM metadata WHERE key='encryption_check'")
        db.execute("INSERT INTO collections(id, name, created_at) VALUES ('c', 'C', 0)")
        db.execute("INSERT INTO people(collection_id, id, name, created_at) "
                   "VALUES ('c', 'p', 'P', 0)")
    with pytest.raises(RuntimeError, match='no encryption-key binding'):
        SQLiteRepository(path, key)


def test_transaction_modes_and_validation(tmp_path):
    path = tmp_path / 'faces.db'
    key = Fernet.generate_key().decode()
    repo = SQLiteRepository(path, key)
    with repo.transaction(mode='DEFERRED') as db:
        assert db.execute('SELECT 1').fetchone()[0] == 1
    with repo.read_transaction() as db:
        assert db.execute('SELECT 1').fetchone()[0] == 1
    with pytest.raises(ValueError, match='Unsupported transaction mode'):
        with repo.transaction(mode='INVALID'):
            pass
