"""S23: caching must preserve snapshot decisions and avoid repeat decryption."""

from face_inference.service import FaceService
from tools.benchmark_matching import synthetic_gallery, synthetic_service


def test_opt_in_warm_gallery_reuses_decryption(monkeypatch):
    with synthetic_service(synthetic_gallery(1)) as original:
        config = {**original.config, 'gallery_cache_enabled': True}
        service = FaceService(original.repository, config, original.model)
        calls = []
        decrypt = service.repository.decrypt

        def counted(value):
            calls.append(value)
            return decrypt(value)

        monkeypatch.setattr(service.repository, 'decrypt', counted)
        with service.repository.transaction() as db:
            cold = service.gallery(db, 'synthetic')
        with service.repository.transaction() as db:
            warm = service.gallery(db, 'synthetic')
        assert warm == cold
        assert len(calls) == 1


def test_opt_in_revalidates_other_connection_and_old_snapshot():
    with synthetic_service(synthetic_gallery(1)) as original:
        config = {**original.config, 'gallery_cache_enabled': True}
        service = FaceService(original.repository, config, original.model)
        repo = service.repository
        with repo.transaction(mode='DEFERRED') as reader:
            old = service.gallery(reader, 'synthetic')
            with repo.transaction() as writer:
                writer.execute("UPDATE people SET consented_at=NULL "
                               "WHERE collection_id='synthetic'")
            assert service.gallery(reader, 'synthetic') == old
        with repo.transaction() as reader:
            assert service.gallery(reader, 'synthetic') == []


def test_rolled_back_reference_is_not_reused():
    with synthetic_service(synthetic_gallery(1)) as original:
        config = {**original.config, 'gallery_cache_enabled': True}
        service = FaceService(original.repository, config, original.model)
        repo = service.repository
        with repo.transaction() as db:
            before = service.gallery(db, 'synthetic')
        try:
            with repo.transaction() as db:
                db.execute("UPDATE people SET encrypted_template=? WHERE collection_id='synthetic'",
                           (repo.encrypt(synthetic_gallery(1, seed=8)[0]['vector']),))
                assert service.gallery(db, 'synthetic') != before
                raise ValueError('rollback')
        except ValueError:
            pass
        with repo.transaction() as db:
            assert service.gallery(db, 'synthetic') == before


def test_default_off_decrypts_each_time(monkeypatch):
    with synthetic_service(synthetic_gallery(1)) as service:
        calls = []
        decrypt = service.repository.decrypt

        def counted(value):
            calls.append(value)
            return decrypt(value)

        monkeypatch.setattr(service.repository, 'decrypt', counted)
        for _ in range(2):
            with service.repository.transaction() as db:
                service.gallery(db, 'synthetic')
        assert len(calls) == 2


def test_expiry_without_write_and_purge():
    with synthetic_service(synthetic_gallery(1)) as original:
        now = original.clock()
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model, clock=lambda: now)
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 1
        now += 86401
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == []
        assert service.purge() == 1
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == []


def test_model_change_is_checked_even_after_warmup():
    import pytest

    from face_inference.matching import FaceError

    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 1
        with service.repository.transaction() as db:
            db.execute("UPDATE people SET model_version='different' "
                       "WHERE collection_id='synthetic'")
        with pytest.raises(FaceError, match='incompatible'):
            with service.repository.transaction() as db:
                service.gallery(db, 'synthetic')


def test_rollback_does_not_publish_reference(monkeypatch):
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        repo = service.repository
        encrypted = repo.encrypt(synthetic_gallery(1, seed=12)[0]['vector'])
        calls = []
        decrypt = repo.decrypt

        def counted(value):
            calls.append(value)
            return decrypt(value)

        monkeypatch.setattr(repo, 'decrypt', counted)
        try:
            with repo.transaction() as db:
                db.execute("UPDATE people SET encrypted_template=? WHERE collection_id='synthetic'",
                           (encrypted,))
                service.gallery(db, 'synthetic')
                raise ValueError('rollback')
        except ValueError:
            pass
        with repo.transaction() as db:
            db.execute("UPDATE people SET encrypted_template=? WHERE collection_id='synthetic'",
                       (encrypted,))
            service.gallery(db, 'synthetic')
        assert calls == [encrypted, encrypted]


def test_cross_process_delete_revalidates_warm_cache():
    import subprocess
    import sys

    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 1
        subprocess.run([sys.executable, '-c',
                        "import sqlite3, sys; db = sqlite3.connect(sys.argv[1]); "
                        "db.execute(\"UPDATE people SET encrypted_template=NULL, "
                        "consented_at=NULL WHERE collection_id='synthetic'\"); "
                        "db.commit(); db.close()", str(service.repository.path)], check=True)
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == []


def test_savepoint_rollback_does_not_publish_reference(monkeypatch):
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        repo = service.repository
        encrypted = repo.encrypt(synthetic_gallery(1, seed=13)[0]['vector'])
        calls = []
        decrypt = repo.decrypt

        def counted(value):
            calls.append(value)
            return decrypt(value)

        monkeypatch.setattr(repo, 'decrypt', counted)
        with repo.transaction() as db:
            db.execute('SAVEPOINT reference_change')
            db.execute("UPDATE people SET encrypted_template=? WHERE collection_id='synthetic'",
                       (encrypted,))
            service.gallery(db, 'synthetic')
            db.execute('ROLLBACK TO reference_change')
            db.execute('RELEASE reference_change')
        with repo.transaction() as db:
            db.execute("UPDATE people SET encrypted_template=? WHERE collection_id='synthetic'",
                       (encrypted,))
            service.gallery(db, 'synthetic')
        assert calls == [encrypted, encrypted]


def test_warm_gallery_rechecks_state_name_and_template():
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        repo = service.repository
        with repo.transaction() as db:
            first = service.gallery(db, 'synthetic')
        replacement = synthetic_gallery(1, seed=14)[0]['vector']
        with repo.transaction() as db:
            db.execute("UPDATE people SET name='Changed', state='PENDING', encrypted_template=? "
                       "WHERE collection_id='synthetic'", (repo.encrypt(replacement),))
        with repo.transaction() as db:
            changed = service.gallery(db, 'synthetic')
            assert changed[0]['name'] == 'Changed'
            assert changed[0]['state'] == 'PENDING'
            assert changed[0]['vector'] != first[0]['vector']
            assert service.gallery(db, 'synthetic', active_only=True) == []
        with repo.transaction() as db:
            db.execute("UPDATE people SET state='REJECTED_DUPLICATE' "
                       "WHERE collection_id='synthetic'")
        with repo.transaction() as db:
            assert service.gallery(db, 'synthetic') == []


def test_warm_gallery_returns_independent_vectors():
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            expected = service.gallery(db, 'synthetic')
        with service.repository.transaction() as db:
            changed = service.gallery(db, 'synthetic')
            changed[0]['vector'][0] = 999
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == expected


def test_cache_evicts_old_entries(monkeypatch):
    import face_inference.service as module

    monkeypatch.setattr(module, 'GALLERY_CACHE_MAX_ENTRIES', 1)
    with synthetic_service(synthetic_gallery(2)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            service.gallery(db, 'synthetic')
        assert len(service._gallery_cache) <= 1


def test_cache_expires_while_idle(monkeypatch):
    import time

    import face_inference.service as module

    monkeypatch.setattr(module, 'GALLERY_CACHE_TTL', 0.05)
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            service.gallery(db, 'synthetic')
        assert service._gallery_cache
        deadline = time.monotonic() + 2
        while service._gallery_cache and time.monotonic() < deadline:
            time.sleep(0.01)
        assert not service._gallery_cache


def test_writes_to_other_tables_can_warm_the_cache(monkeypatch):
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        repo = service.repository
        calls = []
        decrypt = repo.decrypt

        def counted(value):
            calls.append(value)
            return decrypt(value)

        monkeypatch.setattr(repo, 'decrypt', counted)
        for i in range(2):
            with repo.transaction() as db:
                db.execute('UPDATE collections SET name=? WHERE id=?', (str(i), 'synthetic'))
                service.gallery(db, 'synthetic')
        assert len(calls) == 1


def test_expired_timer_cannot_clear_new_generation():
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            service.gallery(db, 'synthetic')
        service._clear_gallery_cache(service._gallery_generation - 1)
        assert service._gallery_cache


def test_config_rejects_non_boolean_cache_option(tmp_path):
    import json

    import pytest

    from face_inference.config import load_config

    path = tmp_path / 'config.json'
    config = load_config(path)
    config['gallery_cache_enabled'] = 'true'
    path.write_text(json.dumps(config))
    with pytest.raises(RuntimeError, match='gallery_cache_enabled'):
        load_config(path)


def test_new_earlier_expiry_shortens_warm_cache_lifetime():
    import time

    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            service.gallery(db, 'synthetic')
        with service.repository.transaction() as db:
            db.execute("UPDATE people SET expires_at=? WHERE collection_id='synthetic'",
                       (time.time() + 0.08,))
        with service.repository.transaction() as db:
            service.gallery(db, 'synthetic')
        time.sleep(0.2)
        assert not service._gallery_cache


def test_delete_operation_invalidates_warm_gallery():
    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 1
        assert service.mutate('synthetic', 'delete', {'idempotency_key': 'delete-0001'},
                              'synthetic-0')['state'] == 'DELETED'
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == []


def test_warm_snapshot_survives_overlapping_delete():
    from concurrent.futures import ThreadPoolExecutor
    from threading import Event

    with synthetic_service(synthetic_gallery(1)) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        snapshot_ready = Event()
        deleted = Event()

        def reader():
            with service.repository.transaction(mode='DEFERRED') as db:
                before = service.gallery(db, 'synthetic')
                snapshot_ready.set()
                assert deleted.wait(5)
                return before, service.gallery(db, 'synthetic')

        with ThreadPoolExecutor(max_workers=1) as pool:
            future = pool.submit(reader)
            assert snapshot_ready.wait(5)
            service.mutate('synthetic', 'delete', {'idempotency_key': 'delete-0002'}, 'synthetic-0')
            deleted.set()
            before, after = future.result(timeout=5)
        assert len(before) == 1
        assert after == before
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic') == []


def test_enrollment_and_activation_after_warm_delete():
    from face_inference.matching import aggregate

    gallery = synthetic_gallery(1)
    with synthetic_service(gallery) as original:
        service = FaceService(original.repository,
                              {**original.config, 'gallery_cache_enabled': True},
                              original.model)
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 1
        service.mutate('synthetic', 'delete', {'idempotency_key': 'delete-0003'}, 'synthetic-0')
        values = [gallery[0]['vector']] * 3
        result = service.mutate('synthetic', 'enroll', {
            'idempotency_key': 'enroll-0003', 'person_id': 'synthetic-0', 'consent': True,
            'capture_session': 'capture-session-0003', 'observations': values,
            'template': aggregate(values), 'model_version': service.model['model_version'],
            'preprocessing_version': service.model['preprocessing_version'],
        })
        assert result['state'] == 'PENDING'
        with service.repository.transaction() as db:
            assert service.gallery(db, 'synthetic', active_only=True) == []
            service.activate(db, 'synthetic', {**service.policy(), 'ready': True})
            active = service.gallery(db, 'synthetic', active_only=True)
        assert [row['person_id'] for row in active] == ['synthetic-0']
