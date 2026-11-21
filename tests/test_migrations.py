import sqlite3

import pytest
from cryptography.fernet import Fernet

from face_inference.storage import SQLiteRepository

KEY = Fernet.generate_key().decode()

SCHEMA_V1 = """
CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS collections (
 id TEXT PRIMARY KEY, name TEXT NOT NULL, created_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS people (
 collection_id TEXT NOT NULL REFERENCES collections(id), id TEXT NOT NULL,
 name TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT '{}',
 state TEXT NOT NULL DEFAULT 'NOT_ENROLLED',
 encrypted_template BLOB, model_version TEXT, preprocessing_version TEXT,
 consented_at REAL, expires_at REAL, created_at REAL NOT NULL,
 reference_capture_id TEXT,
 PRIMARY KEY(collection_id,id)
);
CREATE TABLE IF NOT EXISTS captures (
 id TEXT PRIMARY KEY, collection_id TEXT NOT NULL, person_id TEXT NOT NULL,
 purpose TEXT NOT NULL, split TEXT NOT NULL, visit TEXT NOT NULL,
 encrypted_observations BLOB NOT NULL, expires_at REAL NOT NULL,
 metadata TEXT NOT NULL,
 FOREIGN KEY(collection_id,person_id) REFERENCES people(collection_id,id)
);
CREATE INDEX IF NOT EXISTS captures_person ON captures(collection_id,person_id,visit);
CREATE TABLE IF NOT EXISTS requests (
 collection_id TEXT NOT NULL, key TEXT NOT NULL, payload_sha256 TEXT NOT NULL,
 response TEXT NOT NULL, created_at REAL NOT NULL, PRIMARY KEY(collection_id,key)
);
CREATE TABLE IF NOT EXISTS capture_claims (
 id TEXT PRIMARY KEY, collection_id TEXT NOT NULL, request_key TEXT NOT NULL,
 created_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id TEXT NOT NULL, person_id TEXT,
 event TEXT NOT NULL, state TEXT NOT NULL, created_at REAL NOT NULL
);
"""


def make_v1(path):
    with sqlite3.connect(path) as db:
        db.executescript(SCHEMA_V1)
        db.execute("INSERT INTO metadata VALUES ('schema_version', '1')")
        check = Fernet(KEY.encode()).encrypt(b'face-standalone-key-v1').decode()
        db.execute("INSERT INTO metadata VALUES ('encryption_check', ?)", (check,))
        db.execute("INSERT INTO collections VALUES ('c', 'Collection', 100.0)")
        db.execute(
            "INSERT INTO people (collection_id,id,name,metadata,state,encrypted_template,"
            "model_version,preprocessing_version,consented_at,expires_at,created_at,"
            "reference_capture_id) VALUES ('c','p','Person','{}','ENROLLED',?,'m','p',"
            "100.0,500.0,100.0,NULL)",
            (b'ciphertext',),
        )
        db.execute(
            "INSERT INTO captures (id,collection_id,person_id,purpose,split,visit,"
            "encrypted_observations,expires_at,metadata) "
            "VALUES ('cap1','c','p','enroll','train','v1',?,500.0,'{}')",
            (b'obs',),
        )
        db.execute(
            "INSERT INTO requests (collection_id,key,payload_sha256,response,created_at) "
            "VALUES ('c','req1','sha','resp',100.0)"
        )
        db.execute(
            "INSERT INTO capture_claims (id,collection_id,request_key,created_at) "
            "VALUES ('claim1','c','req1',100.0)"
        )
        db.execute(
            "INSERT INTO events (collection_id,person_id,event,state,created_at) "
            "VALUES ('c','p','enrolled','ENROLLED',100.0)"
        )


def _index_names(db):
    rows = db.execute("SELECT name FROM sqlite_master WHERE type='index'").fetchall()
    return {row[0] for row in rows}


def test_upgrade_and_restart_preserves_data_and_key(tmp_path):
    path = tmp_path / 'db'
    make_v1(path)

    # Verify indexes do not exist before migration
    with sqlite3.connect(path) as db:
        indexes_before = _index_names(db)
        assert 'events_collection_id' not in indexes_before
        assert 'captures_expires_at' not in indexes_before
        assert 'people_expires_at' not in indexes_before

    # First open applies migration
    SQLiteRepository(path, KEY)

    # Second open tests restart / idempotence
    SQLiteRepository(path, KEY)

    with sqlite3.connect(path) as db:
        v = db.execute("SELECT value FROM metadata WHERE key='schema_version'").fetchone()
        assert v == ('2',)
        assert db.execute('SELECT id,encrypted_template FROM people').fetchone() == (
            'p',
            b'ciphertext',
        )
        assert db.execute('SELECT id,encrypted_observations FROM captures').fetchone() == (
            'cap1',
            b'obs',
        )
        assert db.execute('SELECT key,payload_sha256 FROM requests').fetchone() == (
            'req1',
            'sha',
        )
        assert db.execute('SELECT id,request_key FROM capture_claims').fetchone() == (
            'claim1',
            'req1',
        )
        assert db.execute('SELECT event FROM events').fetchone() == ('enrolled',)

        # Verify new indexes exist
        indexes_after = _index_names(db)
        assert 'events_collection_id' in indexes_after
        assert 'captures_expires_at' in indexes_after
        assert 'people_expires_at' in indexes_after

    # Wrong key rejection still works
    with pytest.raises(RuntimeError, match='Wrong database encryption key'):
        SQLiteRepository(path, Fernet.generate_key().decode())


def test_fresh_database(tmp_path):
    path = tmp_path / 'db'
    SQLiteRepository(path, KEY)
    with sqlite3.connect(path) as db:
        v = db.execute("SELECT value FROM metadata WHERE key='schema_version'").fetchone()
        assert v == ('2',)
        assert db.execute("SELECT value FROM metadata WHERE key='encryption_check'").fetchone()

        indexes = _index_names(db)
        assert 'events_collection_id' in indexes
        assert 'captures_expires_at' in indexes
        assert 'people_expires_at' in indexes


def test_future_version_unchanged(tmp_path):
    path = tmp_path / 'db'
    make_v1(path)
    with sqlite3.connect(path) as db:
        db.execute("UPDATE metadata SET value='999' WHERE key='schema_version'")
        before = list(db.iterdump())
    with pytest.raises(RuntimeError, match='schema version'):
        SQLiteRepository(path, KEY)
    with sqlite3.connect(path) as db:
        assert list(db.iterdump()) == before


def test_migration_failure_rolls_back(tmp_path, monkeypatch):
    from face_inference import migrations

    path = tmp_path / 'db'
    make_v1(path)
    with sqlite3.connect(path) as db:
        before = list(db.iterdump())

    monkeypatch.setitem(migrations.MIGRATIONS, 1, (*migrations.MIGRATIONS[1], 'INVALID SQL'))
    with pytest.raises(sqlite3.OperationalError):
        SQLiteRepository(path, KEY)

    with sqlite3.connect(path) as db:
        assert list(db.iterdump()) == before
        v = db.execute("SELECT value FROM metadata WHERE key='schema_version'").fetchone()
        assert v == ('1',)
        indexes = _index_names(db)
        assert 'events_collection_id' not in indexes
