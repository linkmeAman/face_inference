import json
import sqlite3
import sys
import uuid
from pathlib import Path

import pytest
from cryptography.fernet import Fernet, InvalidToken

from face_inference import cli, rotation
from face_inference.config import contract, load_config
from face_inference.matching import aggregate
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


def run(monkeypatch, *args):
    monkeypatch.setattr(sys, 'argv', ['face-inference', *map(str, args)])
    return cli.main()


def populated(path):
    config = load_config(path)
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    model = contract()
    service = FaceService(repo, config, model, clock=lambda: 1800000000.0)
    def mutate(action, payload):
        return service.mutate('c', action, {'idempotency_key': str(uuid.uuid4()), **payload})
    mutate('collection', {'id': 'c', 'name': 'collection'})
    mutate('person', {'id': 'p', 'name': 'person'})
    values = [[1.0] + [0.0] * 127] * 5
    mutate('enroll', dict(person_id='p', observations=values,
                          template=aggregate(values), consent=True,
                          capture_session=str(uuid.uuid4()), model_version=model['model_version'],
                          preprocessing_version=model['preprocessing_version']))
    return config


def payloads(config):
    repo = SQLiteRepository(config['database'], config['encryption_key'])
    with sqlite3.connect(config['database']) as db:
        return [repo.decrypt(row[0]) for table, column in
                [('people', 'encrypted_template'), ('captures', 'encrypted_observations')]
                for row in db.execute(f'SELECT {column} FROM {table} WHERE {column} IS NOT NULL')]


def test_rotates_populated_database_and_retains_snapshot(tmp_path, monkeypatch, capsys, caplog):
    path = tmp_path / 'config.json'
    config = populated(path)
    before = payloads(config)
    old_config = path.read_bytes()
    assert run(monkeypatch, 'rotate-key', '--config', path) == 0
    new = load_config(path)
    assert new['encryption_key'] != config['encryption_key']
    assert payloads(new) == before
    snapshots = list(tmp_path.glob('faces.sqlite3.pre-rotation-*'))
    assert len(snapshots) == 1
    assert payloads(dict(config, database=str(snapshots[0]))) == before
    assert json.loads(old_config)['encryption_key'] == config['encryption_key']
    assert path.stat().st_mode & 0o777 == snapshots[0].stat().st_mode & 0o777 == 0o600
    output = capsys.readouterr()
    for key in (config['encryption_key'], new['encryption_key']):
        assert key not in output.out + output.err + caplog.text
    with pytest.raises(RuntimeError):
        SQLiteRepository(new['database'], config['encryption_key'])


def test_refuses_wrong_key_and_corrupt_ciphertext(tmp_path, monkeypatch):
    path = tmp_path / 'config.json'
    config = populated(path)
    wrong = json.loads(path.read_text())
    wrong['encryption_key'] = Fernet.generate_key().decode()
    path.write_text(json.dumps(wrong))
    assert run(monkeypatch, 'rotate-key', '--config', path) == 1
    assert json.loads(path.read_text()) == wrong
    wrong['encryption_key'] = config['encryption_key']
    path.write_text(json.dumps(wrong))
    with sqlite3.connect(config['database']) as db:
        db.execute('UPDATE people SET encrypted_template=?', (b'corrupt',))
    assert run(monkeypatch, 'rotate-key', '--config', path, '--no-backup') == 1
    rotation.recover(path)
    assert json.loads(path.read_text()) == wrong
    with sqlite3.connect(config['database']) as db:
        assert db.execute('SELECT encrypted_template FROM people').fetchone()[0] == b'corrupt'


@pytest.mark.parametrize('boundary', ['snapshot', 'staged', 'midway', 'committed', 'promotion'])
def test_interruption_recovery(tmp_path, monkeypatch, boundary):
    path = tmp_path / 'config.json'
    config = populated(path)
    before = payloads(config)
    original_write = rotation.write_config
    original_snapshot = rotation.snapshot
    def fail(*args, **kwargs):
        raise RuntimeError('injected failure')
    with monkeypatch.context() as patch:
        if boundary == 'snapshot':
            def snapshot_then_fail(*args):
                original_snapshot(*args)
                fail()
            patch.setattr(rotation, 'snapshot', snapshot_then_fail)
        elif boundary == 'staged':
            count = 0
            def write_then_fail(*args):
                nonlocal count
                count += 1
                original_write(*args)
                if boundary == 'staged' and count == 1:
                    fail()
            patch.setattr(rotation, 'write_config', write_then_fail)
        elif boundary == 'midway':
            def partial(db, old, new):
                row = db.execute('SELECT rowid, encrypted_template FROM people').fetchone()
                db.execute('UPDATE people SET encrypted_template=? WHERE rowid=?',
                           (new.encrypt(old.decrypt(row[1])), row[0]))
                fail()
            patch.setattr(rotation, 'reencrypt', partial)
        elif boundary == 'promotion':
            original_replace = rotation.os.replace
            calls = 0
            def replace(source, destination):
                nonlocal calls
                calls += 1
                if calls == 2:
                    fail()
                original_replace(source, destination)
            patch.setattr(rotation.os, 'replace', replace)
        else:
            def after_commit(path, data):
                if 'encryption_key_next' not in data:
                    fail()
                original_write(path, data)
            patch.setattr(rotation, 'write_config', after_commit)
        with pytest.raises(RuntimeError, match='injected'):
            rotation.rotate(path)
    pending = json.loads(path.read_text())
    rotation.recover(path)
    active = json.loads(path.read_text())
    assert 'encryption_key_next' not in active
    assert payloads(active) == before
    if 'encryption_key_next' in pending:
        other = pending['encryption_key']
        if active['encryption_key'] == other:
            other = pending['encryption_key_next']
        with pytest.raises(RuntimeError):
            SQLiteRepository(active['database'], other)
    if boundary in ('snapshot', 'staged', 'midway'):
        assert active['encryption_key'] == config['encryption_key']
    saved = path.read_bytes()
    rotation.recover(path)
    assert path.read_bytes() == saved
    rotation.rotate(path, no_backup=True)
    assert payloads(json.loads(path.read_text())) == before


def test_recovery_rejects_neither_key_without_changes(tmp_path):
    path = tmp_path / 'config.json'
    config = populated(path)
    data = json.loads(path.read_text())
    data['encryption_key'] = Fernet.generate_key().decode()
    data['encryption_key_next'] = Fernet.generate_key().decode()
    path.write_text(json.dumps(data))
    before = path.read_bytes(), Path(config['database']).read_bytes()
    with pytest.raises(RuntimeError, match='Cannot determine'):
        rotation.recover(path)
    assert (path.read_bytes(), Path(config['database']).read_bytes()) == before


def test_symlink_ancestor_and_final_component(tmp_path):
    home = tmp_path / 'home'
    home.mkdir()
    link = tmp_path / 'link'
    link.symlink_to(home, target_is_directory=True)
    path = link / 'config.json'
    config = populated(path)
    rotation.rotate(path, no_backup=True)
    assert payloads(json.loads(path.read_text()))
    alias = tmp_path / 'config-link'
    alias.symlink_to(path)
    with pytest.raises(RuntimeError, match='Symlink'):
        rotation.recover(alias)
    with pytest.raises(InvalidToken):
        Fernet(config['encryption_key'].encode()).decrypt(
            Fernet(json.loads(path.read_text())['encryption_key'].encode()).encrypt(b'test'))
