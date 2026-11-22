import json
import os
import sqlite3
import sys
from pathlib import Path

import pytest
from cryptography.fernet import Fernet

from face_inference import cli
from face_inference.config import load_config
from face_inference.storage import SQLiteRepository


def run(monkeypatch, *args):
    monkeypatch.setattr(sys, 'argv', ['face-inference', *map(str, args)])
    return cli.main()


@pytest.fixture
def source(tmp_path):
    config_path = tmp_path / 'config.json'
    config = load_config(config_path)
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    db = sqlite3.connect(config['database'])
    db.execute('PRAGMA journal_mode=WAL')
    db.execute("INSERT INTO collections VALUES ('c', 'collection', 1)")
    db.execute(
        "INSERT INTO people (collection_id,id,name,created_at,encrypted_template) "
        "VALUES (?,?,?,?,?)",
        ('c', 'p', 'person', 1, repo.encrypt([1, 2])),
    )
    db.execute(
        "INSERT INTO captures VALUES (?,?,?,?,?,?,?,?,?)",
        ('capture', 'c', 'p', 'enroll', 'train', 'v1', repo.encrypt([3]), 10, '{}'),
    )
    db.commit()
    yield config_path, config, db
    db.close()


def test_wal_round_trip_and_private_files(source, tmp_path, monkeypatch):
    config_path, config, db = source
    assert Path(config['database'] + '-wal').stat().st_size > 0
    backup = tmp_path / 'backup.sqlite3'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', backup) == 0
    assert backup.stat().st_mode & 0o777 == 0o600
    assert config['api_token'].encode() not in backup.read_bytes()
    assert config['encryption_key'].encode() not in backup.read_bytes()
    destination = tmp_path / 'restored.sqlite3'
    config['database'] = str(destination)
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', backup) == 0
    assert destination.stat().st_mode & 0o777 == 0o600
    with sqlite3.connect(destination) as restored:
        encrypted = restored.execute('SELECT encrypted_template FROM people').fetchone()[0]
        observation = restored.execute('SELECT encrypted_observations FROM captures').fetchone()[0]
    restored_repo = SQLiteRepository(destination, config['encryption_key'])
    assert restored_repo.decrypt(encrypted) == [1, 2]
    assert restored_repo.decrypt(observation) == [3]
    assert db.execute('SELECT count(*) FROM people').fetchone()[0] == 1


def test_refuses_existing_and_symlink_targets(source, tmp_path, monkeypatch):
    config_path, config, _ = source
    target = tmp_path / 'target'
    target.write_bytes(b'keep')
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', target) == 1
    assert target.read_bytes() == b'keep'
    link = tmp_path / 'link'
    link.symlink_to(target)
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', link) == 1
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', target) == 1
    assert Path(config['database']).exists()


def test_invalid_wrong_or_missing_key_and_cleanup(source, tmp_path, monkeypatch):
    config_path, config, _ = source
    backup = tmp_path / 'backup'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', backup) == 0
    destination = tmp_path / 'new-db'
    config['database'] = str(destination)
    config['encryption_key'] = Fernet.generate_key().decode()
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', backup) == 1
    assert not destination.exists()
    corrupt = tmp_path / 'corrupt'
    corrupt.write_bytes(b'not sqlite')
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', corrupt) == 1
    assert not destination.exists()
    missing = tmp_path / 'missing.json'
    assert run(monkeypatch, 'restore', '--config', missing, '--input', backup) == 1
    assert not missing.exists()
    assert not list(tmp_path.glob('.restore-*'))


def test_partial_backup_failure_cleans_output(source, tmp_path, monkeypatch):
    config_path, _, _ = source
    output = tmp_path / 'output'
    from face_inference import backup
    def fail(*_):
        raise RuntimeError('failure')

    monkeypatch.setattr(backup, 'check_database', fail)
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', output) == 1
    assert not os.path.lexists(output)


def test_missing_key_symlink_directory_and_publish_failure(source, tmp_path, monkeypatch, capsys):
    config_path, config, _ = source
    backup_path = tmp_path / 'backup'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', backup_path) == 0
    destination = tmp_path / 'new-db'
    config['database'] = str(destination)
    key = config.pop('encryption_key')
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', backup_path) == 1
    assert not destination.exists()
    config['encryption_key'] = key
    config_path.write_text(json.dumps(config))
    linked = tmp_path / 'linked'
    linked.symlink_to(tmp_path, target_is_directory=True)
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', linked / 'out') == 1
    monkeypatch.setattr(os, 'link', lambda *_: (_ for _ in ()).throw(OSError('publish failed')))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', backup_path) == 1
    assert capsys.readouterr().err.splitlines()[-1] == (
        f'Restore needs a filesystem that supports hard links: {tmp_path}; '
        'restore into a directory on a supported filesystem (the server must be stopped) '
        'and move the file afterwards'
    )
    assert not destination.exists()
    assert not list(tmp_path.glob('.restore-*'))


def test_symlinked_ancestor(source, tmp_path, monkeypatch):
    config_path, config, _ = source
    link = tmp_path / 'alias'
    link.symlink_to(tmp_path, target_is_directory=True)
    config['database'] = str(link / Path(config['database']).name)
    config_path.write_text(json.dumps(config))
    output = link / 'snapshot'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', output) == 0
    config['database'] = str(link / 'restored')
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', output) == 0
    assert (tmp_path / 'restored').exists()


def test_link_failure_reports_requirement(source, tmp_path, monkeypatch, capsys):
    config_path, config, _ = source
    snapshot = tmp_path / 'snapshot'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', snapshot) == 0
    destination = tmp_path / 'restored'
    config['database'] = str(destination)
    config_path.write_text(json.dumps(config))
    monkeypatch.setattr(os, 'link', lambda *_: (_ for _ in ()).throw(OSError('publish failed')))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', snapshot) == 1
    assert capsys.readouterr().err == (
        f'Restore needs a filesystem that supports hard links: {tmp_path}; '
        'restore into a directory on a supported filesystem (the server must be stopped) '
        'and move the file afterwards\n'
    )
    assert not destination.exists()
    assert not list(tmp_path.glob('.restore-*'))


def test_database_symlink_rejected(source, tmp_path, monkeypatch):
    config_path, config, _ = source
    link = tmp_path / 'database-link'
    link.symlink_to(config['database'])
    config['database'] = str(link)
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', tmp_path / 'output') == 1


@pytest.mark.parametrize('suffix', ['', '-wal', '-shm', '-journal'])
def test_restore_preserves_existing_destination_or_sidecar(source, tmp_path, monkeypatch, suffix):
    config_path, config, _ = source
    snapshot = tmp_path / 'snapshot'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', snapshot) == 0
    destination = tmp_path / 'new-db'
    existing = Path(str(destination) + suffix)
    existing.write_bytes(b'keep')
    config['database'] = str(destination)
    config_path.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', config_path, '--input', snapshot) == 1
    assert existing.read_bytes() == b'keep'
    assert suffix == '' or not destination.exists()


def test_service_round_trip(tmp_path, monkeypatch):
    from face_inference.config import contract
    from face_inference.service import FaceService

    original = tmp_path / 'original'
    config_path = original / 'config.json'
    config = load_config(config_path)
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    service = FaceService(repo, config, contract())
    service.mutate('team', 'collection', {
        'id': 'team', 'name': 'Team', 'idempotency_key': 'collection-key',
    })
    payload = {'id': 'alice', 'name': 'Alice', 'idempotency_key': 'person-key'}
    result = service.mutate('team', 'person', payload)
    events = service.events('team')
    snapshot = tmp_path / 'snapshot'
    assert run(monkeypatch, 'backup', '--config', config_path, '--output', snapshot) == 0
    recovery = tmp_path / 'recovery'
    recovery.mkdir()
    config['database'] = str(recovery / 'faces.sqlite3')
    recovery_config = recovery / 'config.json'
    recovery_config.write_text(json.dumps(config))
    assert run(monkeypatch, 'restore', '--config', recovery_config, '--input', snapshot) == 0
    restored_repo = SQLiteRepository(config['database'], config['encryption_key'])
    restored = FaceService(restored_repo, config, contract())
    assert restored.list_people('team')[0]['id'] == 'alice'
    assert restored.events('team') == events
    with restored_repo.transaction() as db:
        assert db.execute('SELECT count(*) FROM requests').fetchone()[0] == 2
    assert restored.mutate('team', 'person', payload) == result
    assert restored.events('team') == events
