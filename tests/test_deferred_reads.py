"""Deterministic real WAL concurrency, error/rollback, and retention regression tests."""
import sqlite3
import threading
import time
import uuid
from contextlib import closing

import pytest

from face_inference.config import contract, load_config
from face_inference.matching import FaceError
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository

NOW = 1_800_000_000.0


def unit_vector(index=0, size=128):
    vec = [0.0] * size
    vec[index] = 1.0
    return vec


@pytest.fixture
def test_env(tmp_path):
    config = load_config(tmp_path / 'config.json')
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    model = contract()
    service = FaceService(repo, config, model, clock=lambda: NOW)
    return {
        'config': config,
        'repo': repo,
        'model': model,
        'service': service,
        'path': config['database'],
    }


def test_storage_transaction_mode_deferred_and_validation(test_env, monkeypatch):
    repo = test_env['repo']
    traces = []
    original_connect = repo.connect

    def tracing_connect():
        db = original_connect()
        db.set_trace_callback(traces.append)
        return db

    monkeypatch.setattr(repo, 'connect', tracing_connect)

    # Default transaction mode is IMMEDIATE
    traces.clear()
    with repo.transaction() as db:
        db.execute('SELECT 1')
    assert any('BEGIN IMMEDIATE' in t for t in traces)

    # Explicit IMMEDIATE
    traces.clear()
    with repo.transaction(mode='IMMEDIATE') as db:
        db.execute('SELECT 1')
    assert any('BEGIN IMMEDIATE' in t for t in traces)

    # Explicit DEFERRED
    traces.clear()
    with repo.transaction(mode='DEFERRED') as db:
        db.execute('SELECT 1')
    assert any('BEGIN DEFERRED' in t for t in traces)

    # Case-insensitive
    traces.clear()
    with repo.transaction(mode='deferred') as db:
        db.execute('SELECT 1')
    assert any('BEGIN DEFERRED' in t for t in traces)

    # read_transaction helper executes DEFERRED
    traces.clear()
    with repo.read_transaction() as db:
        db.execute('SELECT 1')
    assert any('BEGIN DEFERRED' in t for t in traces)

    # Unsupported mode raises ValueError
    with pytest.raises(ValueError, match='Unsupported transaction mode: EXCLUSIVE'):
        with repo.transaction(mode='EXCLUSIVE'):
            pass

    # Exception inside deferred transaction triggers rollback and clean close
    with pytest.raises(RuntimeError, match='rollback check'):
        with repo.transaction(mode='DEFERRED') as db:
            db.execute(
                "INSERT INTO collections(id, name, created_at) VALUES ('rollback_test', 'R', ?)",
                (NOW,),
            )
            raise RuntimeError('rollback check')

    # Verify rollback succeeded
    with repo.connect() as db:
        assert db.execute("SELECT 1 FROM collections WHERE id='rollback_test'").fetchone() is None


def test_wal_held_writer_does_not_block_pure_reads(test_env):
    service = test_env['service']
    repo = test_env['repo']

    # Setup collection, person, and event
    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'coll-key-1',
    })
    service.mutate('team', 'person', {
        'id': 'p1', 'name': 'Person 1', 'idempotency_key': 'person-key-1',
    })

    writer_ready = threading.Event()
    readers_done = threading.Event()
    writer_finished = threading.Event()
    writer_error = []

    def writer_worker():
        try:
            with closing(repo.connect()) as writer_db:
                writer_db.execute('BEGIN IMMEDIATE')
                writer_db.execute(
                    "INSERT INTO events(collection_id, person_id, event, state, created_at) "
                    "VALUES ('team', 'p1', 'writer_hold', 'ACTIVE', ?)", (NOW,)
                )
                writer_ready.set()
                # Hold the writer until readers have completed their assertions
                if not readers_done.wait(timeout=3.0):
                    raise TimeoutError('Readers timed out waiting on writer')
                writer_db.rollback()
        except Exception as exc:
            writer_error.append(exc)
        finally:
            writer_finished.set()

    writer_thread = threading.Thread(target=writer_worker, daemon=True)
    writer_thread.start()

    try:
        assert writer_ready.wait(timeout=3.0), 'Writer did not start in time'

        # 1. Pure read: list_collections should succeed immediately without blocking (< 0.5s)
        t0 = time.perf_counter()
        collections = service.list_collections()
        t_colls = time.perf_counter() - t0
        assert any(c['id'] == 'team' for c in collections)
        assert t_colls < 0.5, f"list_collections took too long: {t_colls}s"

        # 2. Pure read: events should succeed immediately without blocking (< 0.5s)
        t0 = time.perf_counter()
        evts = service.events('team')
        t_events = time.perf_counter() - t0
        assert t_events < 0.5, f"events took too long: {t_events}s"
        # Pure read snapshot must not see uncommitted event from held writer
        assert not any(e['event'] == 'writer_hold' for e in evts)

        # 3. In contrast, an IMMEDIATE transaction with short timeout MUST fail while writer is held
        with closing(repo.connect()) as fast_conn:
            fast_conn.execute('PRAGMA busy_timeout=50')
            with pytest.raises(sqlite3.OperationalError, match='database is locked'):
                fast_conn.execute('BEGIN IMMEDIATE')

    finally:
        readers_done.set()
        writer_thread.join(timeout=3.0)
        assert not writer_thread.is_alive(), 'Writer thread hung'
        assert not writer_error, f"Writer thread failed: {writer_error}"


def test_service_pure_read_transaction_modes(test_env, monkeypatch):
    service = test_env['service']
    repo = test_env['repo']
    traces = []
    original_connect = repo.connect

    def tracing_connect():
        db = original_connect()
        db.set_trace_callback(traces.append)
        return db

    monkeypatch.setattr(repo, 'connect', tracing_connect)

    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'coll-key-1',
    })
    service.mutate('team', 'person', {
        'id': 'p1', 'name': 'Person 1', 'idempotency_key': 'person-key-1',
    })

    # list_collections must execute BEGIN DEFERRED
    traces.clear()
    service.list_collections()
    assert any('BEGIN DEFERRED' in t for t in traces), f"Expected BEGIN DEFERRED in {traces}"
    assert not any('BEGIN IMMEDIATE' in t for t in traces)

    # events must execute BEGIN DEFERRED
    traces.clear()
    service.events('team')
    assert any('BEGIN DEFERRED' in t for t in traces), f"Expected BEGIN DEFERRED in {traces}"
    assert not any('BEGIN IMMEDIATE' in t for t in traces)

    # list_people must execute BEGIN IMMEDIATE (for atomic purge)
    traces.clear()
    service.list_people('team')
    assert any('BEGIN IMMEDIATE' in t for t in traces), f"Expected BEGIN IMMEDIATE in {traces}"

    # export_records must execute BEGIN IMMEDIATE (for atomic purge)
    traces.clear()
    service.export_records('team')
    assert any('BEGIN IMMEDIATE' in t for t in traces), f"Expected BEGIN IMMEDIATE in {traces}"


def test_response_and_error_regression(test_env):
    service = test_env['service']

    # Empty collection list
    assert service.list_collections() == []

    # Create collection and person
    service.mutate('c1', 'collection', {
        'id': 'c1', 'name': 'Alpha', 'idempotency_key': 'c1-key-123',
    })
    service.mutate('c2', 'collection', {
        'id': 'c2', 'name': 'Beta', 'idempotency_key': 'c2-key-123',
    })
    service.mutate('c1', 'person', {
        'id': 'p1', 'name': 'Alice', 'idempotency_key': 'p1-key-123',
    })

    # list_collections returns expected schema ordered by name, id
    colls = service.list_collections()
    assert len(colls) == 2
    assert [c['id'] for c in colls] == ['c1', 'c2']
    assert [c['name'] for c in colls] == ['Alpha', 'Beta']
    assert all('created_at' in c for c in colls)

    # events returns expected schema ordered by id DESC
    evts = service.events('c1')
    assert len(evts) >= 2
    assert all(k in evts[0] for k in ('id', 'person_id', 'event', 'state', 'created_at'))

    # events with non-existent collection raises 404 FaceError
    with pytest.raises(FaceError) as exc_info:
        service.events('nonexistent')
    assert exc_info.value.code == 'COLLECTION_NOT_FOUND'
    assert exc_info.value.status == 404

    # events with invalid collection ID format raises FaceError
    with pytest.raises(FaceError) as exc_info:
        service.events('invalid id!')
    assert exc_info.value.code == 'INVALID_ID'


def test_retention_purge_not_triggered_by_pure_reads(test_env):
    service = test_env['service']
    repo = test_env['repo']
    model = test_env['model']

    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'coll-key-123',
    })
    service.mutate('team', 'person', {
        'id': 'p1', 'name': 'Person 1', 'idempotency_key': 'person-key-123',
    })

    vec = unit_vector(0)
    capture_session = 'session-' + uuid.uuid4().hex
    service.mutate('team', 'enroll', {
        'idempotency_key': 'enroll-key-123',
        'person_id': 'p1',
        'observations': [vec] * 5,
        'template': vec,
        'consent': True,
        'capture_session': capture_session,
        'model_version': model['model_version'],
        'preprocessing_version': model['preprocessing_version'],
    })

    # Advance clock past retention period
    expired_time = NOW + service.config['retention_days'] * 86400 + 100
    service.clock = lambda: expired_time

    # Verify capture and template are currently present in db
    with repo.connect() as db:
        expired_count = db.execute(
            'SELECT COUNT(*) FROM captures WHERE expires_at <= ?', (expired_time,),
        ).fetchone()[0]
        assert expired_count == 1
        template_row = db.execute(
            "SELECT encrypted_template FROM people WHERE id='p1'",
        ).fetchone()
        assert template_row[0] is not None

    # Pure reads: list_collections and events must NOT purge expired data
    service.list_collections()
    service.events('team')

    with repo.connect() as db:
        expired_count = db.execute(
            'SELECT COUNT(*) FROM captures WHERE expires_at <= ?', (expired_time,),
        ).fetchone()[0]
        assert expired_count == 1
        template_row = db.execute(
            "SELECT encrypted_template FROM people WHERE id='p1'",
        ).fetchone()
        assert template_row[0] is not None

    # list_people DOES purge expired data (retaining existing purge behavior)
    people = service.list_people('team')
    assert people[0]['state'] == 'RECAPTURE_REQUIRED'

    with repo.connect() as db:
        assert db.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 0
        template_row = db.execute(
            "SELECT encrypted_template FROM people WHERE id='p1'",
        ).fetchone()
        assert template_row[0] is None


def test_recognition_claims_writes_and_uses_immediate_transaction(test_env):
    service = test_env['service']
    repo = test_env['repo']
    model = test_env['model']

    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'coll-key-123',
    })
    service.mutate('team', 'person', {
        'id': 'p1', 'name': 'Person 1', 'idempotency_key': 'person-key-123',
    })

    vec = unit_vector(0)
    service.mutate('team', 'enroll', {
        'idempotency_key': 'enroll-key-123',
        'person_id': 'p1',
        'observations': [vec] * 5,
        'template': vec,
        'consent': True,
        'capture_session': 'sess-enroll-' + uuid.uuid4().hex,
        'model_version': model['model_version'],
        'preprocessing_version': model['preprocessing_version'],
    })

    rec_session = 'sess-rec-' + uuid.uuid4().hex
    rec_result = service.mutate('team', 'recognize', {
        'idempotency_key': 'rec-key-123',
        'observations': [vec] * 5,
        'capture_session': rec_session,
        'model_version': model['model_version'],
        'preprocessing_version': model['preprocessing_version'],
    })
    assert rec_result['state'] == 'SUGGESTED'
    assert rec_result['person_id'] == 'p1'

    # Verify recognition claimed capture session and wrote events
    with repo.connect() as db:
        claim = db.execute('SELECT 1 FROM capture_claims WHERE id=?', (rec_session,)).fetchone()
        assert claim is not None
        evt = db.execute(
            "SELECT 1 FROM events WHERE collection_id='team' AND event='recognize'",
        ).fetchone()
        assert evt is not None
