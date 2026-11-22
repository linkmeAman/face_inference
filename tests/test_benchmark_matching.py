"""Deterministic checks for the opt-in synthetic benchmark; no speed assertions."""

import json
import sys
from pathlib import Path

import pytest

from face_inference.matching import aggregate, identify, vector

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from tools.benchmark_matching import (  # noqa: E402
    main,
    measure,
    percentile,
    run_scenario,
    synthetic_gallery,
    synthetic_service,
)


def test_samples_are_reproducible_valid_and_match():
    gallery = synthetic_gallery(4, seed=7)
    assert gallery == synthetic_gallery(4, seed=7)
    assert gallery != synthetic_gallery(4, seed=8)
    assert len({tuple(row['vector']) for row in gallery}) == 4
    for row in gallery:
        assert vector(row['vector']) == row['vector']
    values = [gallery[0]['vector'][:] for _ in range(3)]
    assert vector(aggregate(values))
    assert identify(values, gallery, 0.65) == (gallery[0]['person_id'], 'MATCHED')


def test_percentile_and_measurement_exclude_warmup():
    assert percentile([4, 1, 3, 2], 0.5) == 2
    assert percentile([4, 1, 3, 2], 0.95) == 4
    assert percentile([8], 0.95) == 8
    with pytest.raises(ValueError):
        percentile([], 0.5)
    ticks = iter([0, 99, 100, 101, 102, 104, 105, 108])
    calls, preparations = [], []
    result = measure(lambda: calls.append(True), 3, prepare=lambda: preparations.append(True),
                     clock=lambda: next(ticks))
    assert result == {'samples': 3, 'p50_ms': 2000, 'p95_ms': 3000}
    assert len(calls) == len(preparations) == 4
    with pytest.raises(ValueError):
        measure(lambda: None, 0)


def test_temporary_service_is_isolated_and_cleans_up(monkeypatch, tmp_path):
    sentinel = tmp_path / 'production.json'
    sentinel.write_text('DO NOT READ OR CHANGE')
    monkeypatch.setenv('FACE_STANDALONE_CONFIG', str(sentinel))
    monkeypatch.setenv('FACE_INFERENCE_HOME', str(tmp_path))
    gallery = synthetic_gallery(3)
    with synthetic_service(gallery) as service:
        folder = service.repository.path.parent
        assert folder != tmp_path
        assert service.config['max_people'] == 3
        with service.repository.transaction() as db:
            assert len(service.gallery(db, 'synthetic')) == 3
        assert service.config['mode'] == 'EVALUATION'
    assert not folder.exists()
    assert sentinel.read_text() == 'DO NOT READ OR CHANGE'
    assert list(tmp_path.iterdir()) == [sentinel]
    with pytest.raises(RuntimeError, match='cleanup'):
        with synthetic_service(gallery) as service:
            failed_folder = service.repository.path.parent
            raise RuntimeError('cleanup')
    assert not failed_folder.exists()


def test_small_run_exercises_service_activation_and_contention():
    result = run_scenario(3, samples=2, workers=2, seed=7, pending=1)
    assert result['templates'] == 3
    assert result['kind'] == 'synthetic_service_and_algorithm'
    for name in ('aggregate', 'identify', 'service_recognize', 'activation'):
        assert result[name]['samples'] == 2
        assert result[name]['p95_ms'] >= result[name]['p50_ms'] >= 0
    assert result['contention']['requests']['samples'] == 4
    assert result['contention']['lock_wait']['samples'] == 4
    assert result['contention']['errors'] == 0
    assert result['memory']['python_setup_peak_bytes'] > 0
    encoded = json.dumps(result)
    assert 'person_id' not in encoded
    assert 'encryption_key' not in encoded
    assert 'synthetic-' not in encoded


def test_algorithm_only_never_creates_storage(monkeypatch):
    def forbidden(*args, **kwargs):
        raise AssertionError('algorithm-only must not open storage')

    monkeypatch.setattr('tools.benchmark_matching.synthetic_service', forbidden)
    result = run_scenario(3, samples=1, workers=2, seed=7, pending=1, algorithm_only=True)
    assert result['kind'] == 'synthetic_algorithm_only'
    assert result['activation'] is None
    assert result['service_recognize'] is None
    assert result['contention'] is None


def test_limit_rejection_and_cli_routes_100k_to_algorithm_only(monkeypatch, capsys):
    with pytest.raises(ValueError, match='10k limit'):
        with synthetic_service([{}] * 10001):
            pytest.fail('Oversized service fixture must be rejected')
    with pytest.raises(ValueError, match='Above 10k'):
        run_scenario(10001)
    with pytest.raises(ValueError, match='Positive'):
        run_scenario(3, workers=0)
    scenarios = []

    def record(size, *args, algorithm_only, gallery_cache=False):
        scenarios.append((size, algorithm_only))
        return {'templates': size}

    monkeypatch.setattr('tools.benchmark_matching.run_scenario', record)
    main(['--sizes', '1000', '10000', '100000', '--samples', '1'])
    assert scenarios == [(1000, False), (10000, False), (100000, True)]
    assert len(json.loads(capsys.readouterr().out)['scenarios']) == 3
    with pytest.raises(SystemExit) as error:
        main(['--samples', '0'])
    assert error.value.code == 2
