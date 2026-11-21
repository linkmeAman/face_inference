import sqlite3
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from threading import Barrier
from unittest.mock import Mock, call

import pytest

from face_inference.config import contract, load_config
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository

NOW = 1_800_000_000
MARKER = 'R07-DELETABLE-CAPTURE-METADATA-8f6bc250-d763-4fb3-967b-139bebde12cd'
CHECKPOINT = 'PRAGMA wal_checkpoint(TRUNCATE)'


def raw_files(repo):
    return [path.read_bytes() if path.exists() else b''
            for path in (repo.path, repo.path.with_name(repo.path.name + '-wal'))]


@pytest.fixture
def stored_capture(tmp_path):
    config = load_config(tmp_path / 'config.json')
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    model = contract()
    service = FaceService(repo, config, model, clock=lambda: NOW)
    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'collection-request',
    })
    service.mutate('team', 'person', {
        'id': 'person', 'name': 'Person', 'idempotency_key': 'person-request',
    })
    vector = [1.0] + [0.0] * 127
    service.mutate('team', 'enroll', {
        'idempotency_key': 'enrollment-request', 'person_id': 'person',
        'observations': [vector] * 5, 'template': vector, 'consent': True,
        'capture_session': 'capture-session-r07', 'device': MARKER,
        'model_version': model['model_version'],
        'preprocessing_version': model['preprocessing_version'],
    })
    # Keep a connection open so closing the writer cannot silently clean the WAL.
    with closing(repo.connect()) as keeper:
        assert keeper.execute(CHECKPOINT).fetchone()[0] == 0
        keeper.execute("UPDATE captures SET metadata=metadata || ' '")
        keeper.commit()
        assert all(MARKER.encode() in data for data in raw_files(repo))
        yield service, keeper


def test_secure_delete_on_each_connection(stored_capture, monkeypatch):
    service, _ = stored_capture
    original_connect = sqlite3.connect

    def insecure_default(*args, **kwargs):
        db = original_connect(*args, **kwargs)
        db.execute('PRAGMA secure_delete=OFF')
        return db

    monkeypatch.setattr(sqlite3, 'connect', insecure_default)
    with closing(service.repository.connect()) as first, closing(
        service.repository.connect()
    ) as second:
        for db in (first, second):
            assert db.execute('PRAGMA secure_delete').fetchone()[0] == 1
            assert db.execute('PRAGMA foreign_keys').fetchone()[0] == 1
            assert db.execute('PRAGMA busy_timeout').fetchone()[0] == 10000


@pytest.mark.parametrize(
    'operation', ['purge', 'list_people', 'export_records', 'mutate', 'delete'],
)
def test_deletion_removes_marker_from_database_and_wal(stored_capture, operation):
    service, keeper = stored_capture
    if operation == 'delete':
        assert service.mutate('team', 'delete', {
            'idempotency_key': 'delete-request',
        }, 'person') == {'state': 'DELETED', 'person_id': 'person'}
    else:
        service.clock = lambda: NOW + service.config['retention_days'] * 86400 + 1
        if operation == 'purge':
            assert service.purge() == 2
        elif operation == 'list_people':
            assert service.list_people('team')[0]['state'] == 'RECAPTURE_REQUIRED'
        elif operation == 'export_records':
            assert service.export_records('team') == []
        else:
            assert service.mutate('team', 'person', {
                'id': 'person', 'name': 'Person', 'idempotency_key': 'update-request',
            })['state'] == 'UPDATED'
    assert keeper.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 0
    assert keeper.execute('SELECT encrypted_template FROM people').fetchone()[0] is None
    assert keeper.execute('SELECT COUNT(*) FROM people').fetchone()[0] == 1
    assert all(MARKER.encode() not in data for data in raw_files(service.repository))
    assert raw_files(service.repository)[1] == b''


@pytest.mark.parametrize('failure', ['body', 'interrupt', 'commit'])
def test_precommit_failure_rolls_back_without_checkpoint(stored_capture, monkeypatch, failure):
    service, keeper = stored_capture
    repo = service.repository
    tracked = Mock(wraps=repo.connect())
    monkeypatch.setattr(repo, 'connect', lambda: tracked)
    if failure == 'commit':
        tracked.commit.side_effect = sqlite3.OperationalError('commit failed')
    error = KeyboardInterrupt if failure == 'interrupt' else (
        sqlite3.OperationalError if failure == 'commit' else ValueError
    )
    service.clock = lambda: NOW + service.config['retention_days'] * 86400 + 1
    with pytest.raises(error):
        with repo.transaction() as db:
            assert service.purge(db) == 2
            if failure != 'commit':
                raise error('abort')
    tracked.rollback.assert_called_once_with()
    tracked.close.assert_called_once_with()
    assert all(call.args[0] != CHECKPOINT for call in tracked.execute.call_args_list)
    assert keeper.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 1
    assert keeper.execute('SELECT encrypted_template FROM people').fetchone()[0] is not None
    assert any(MARKER.encode() in data for data in raw_files(repo))


def test_read_only_transactions_do_not_checkpoint(stored_capture, monkeypatch):
    service, _ = stored_capture
    repo = service.repository
    original_connect = repo.connect
    statements = []

    def connect():
        db = original_connect()
        db.set_trace_callback(statements.append)
        return db

    monkeypatch.setattr(repo, 'connect', connect)
    start = Barrier(8)

    def read():
        start.wait()
        errors = []
        for _ in range(40):
            try:
                with repo.transaction() as db:
                    assert db.execute('SELECT COUNT(*) FROM people').fetchone()[0] == 1
            except sqlite3.Error as error:
                errors.append(error)
        return errors

    with ThreadPoolExecutor(max_workers=8) as pool:
        assert [error for errors in pool.map(lambda _: read(), range(8)) for error in errors] == []
    assert CHECKPOINT not in statements


def test_reads_and_empty_purge_do_not_checkpoint(stored_capture, monkeypatch):
    service, _ = stored_capture
    checkpoint = Mock(wraps=service.repository.checkpoint)
    monkeypatch.setattr(service.repository, 'checkpoint', checkpoint)
    service.list_collections()
    service.list_people('team')
    service.events('team')
    service.export_records('team')
    assert service.purge() == 0
    service.mutate('team', 'person', {
        'id': 'person', 'name': 'Person', 'idempotency_key': 'update-request',
    })
    checkpoint.assert_not_called()


@pytest.mark.parametrize(
    'operation', ['purge', 'list_people', 'export_records', 'mutate', 'delete'],
)
def test_busy_checkpoint_does_not_fail_deletion(stored_capture, monkeypatch, operation):
    service, keeper = stored_capture
    repo = service.repository
    sleep = Mock()
    monkeypatch.setattr('face_inference.storage.time.sleep', sleep)
    keeper.execute('BEGIN')
    assert keeper.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 1
    try:
        assert repo.checkpoint() is False
        assert sleep.call_args_list == [call(0.05)] * 5
        sleep.reset_mock()
        if operation == 'delete':
            assert service.mutate('team', 'delete', {
                'idempotency_key': 'delete-request',
            }, 'person') == {'state': 'DELETED', 'person_id': 'person'}
        else:
            service.clock = lambda: NOW + service.config['retention_days'] * 86400 + 1
            if operation == 'purge':
                assert service.purge() == 2
            elif operation == 'list_people':
                assert service.list_people('team')[0]['state'] == 'RECAPTURE_REQUIRED'
            elif operation == 'export_records':
                assert service.export_records('team') == []
            else:
                assert service.mutate('team', 'person', {
                    'id': 'person', 'name': 'Person', 'idempotency_key': 'update-request',
                })['state'] == 'UPDATED'
        assert sleep.call_args_list == [call(0.05)] * 5
        with closing(repo.connect()) as reader:
            assert reader.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 0
            assert reader.execute('SELECT encrypted_template FROM people').fetchone()[0] is None
        assert any(MARKER.encode() in data for data in raw_files(repo))
    finally:
        keeper.rollback()
    assert repo.checkpoint() is True
    assert all(MARKER.encode() not in data for data in raw_files(repo))
    assert raw_files(repo)[1] == b''


def test_checkpoint_retry_succeeds_when_reader_releases(stored_capture, monkeypatch):
    service, keeper = stored_capture
    keeper.execute('BEGIN')
    assert keeper.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 1
    sleep = Mock(side_effect=lambda _: keeper.rollback())
    monkeypatch.setattr('face_inference.storage.time.sleep', sleep)
    try:
        assert service.repository.checkpoint() is True
        sleep.assert_called_once_with(0.05)
    finally:
        keeper.rollback()


def test_postcommit_error_visible_and_checkpoint_retry_recovers(stored_capture, monkeypatch):
    service, keeper = stored_capture
    repo = service.repository
    original_connect = repo.connect
    db = original_connect()
    db.execute('PRAGMA busy_timeout=0')
    tracked = Mock(wraps=db)

    def execute(sql, *args):
        if sql == CHECKPOINT:
            assert not db.in_transaction
            raise sqlite3.OperationalError('checkpoint I/O failure')
        return db.execute(sql, *args)

    tracked.execute.side_effect = execute
    writer = Mock(wraps=original_connect())
    monkeypatch.setattr(repo, 'connect', Mock(side_effect=[writer, tracked]))
    payload = {'idempotency_key': 'delete-request'}
    with pytest.raises(sqlite3.OperationalError, match='checkpoint I/O failure'):
        service.mutate('team', 'delete', payload, 'person')
    writer.commit.assert_called_once_with()
    writer.rollback.assert_not_called()
    writer.close.assert_called_once_with()
    tracked.commit.assert_not_called()
    tracked.rollback.assert_not_called()
    tracked.close.assert_called_once_with()
    with closing(original_connect()) as reader:
        assert reader.execute('SELECT COUNT(*) FROM captures').fetchone()[0] == 0
        assert reader.execute('SELECT encrypted_template FROM people').fetchone()[0] is None
        assert reader.execute(
            "SELECT COUNT(*) FROM requests WHERE key='delete-request'"
        ).fetchone()[0] == 1
    assert any(MARKER.encode() in data for data in raw_files(repo))
    monkeypatch.setattr(repo, 'connect', original_connect)
    assert service.mutate('team', 'delete', payload, 'person') == {
        'state': 'DELETED', 'person_id': 'person',
    }
    assert repo.checkpoint() is True
    assert keeper.execute("SELECT COUNT(*) FROM events WHERE event='delete'").fetchone()[0] == 1
    assert keeper.execute(
        "SELECT COUNT(*) FROM requests WHERE key='delete-request'"
    ).fetchone()[0] == 1
    assert all(MARKER.encode() not in data for data in raw_files(repo))
    assert raw_files(repo)[1] == b''
