#!/usr/bin/env python3
"""Opt-in synthetic local baseline. Run with PYTHONPATH=src; never opens user data."""

import argparse
import itertools
import json
import math
import os
import platform
import random
import sqlite3
import sys
import tempfile
import threading
import time
import tracemalloc
from concurrent.futures import ThreadPoolExecutor
from contextlib import contextmanager
from datetime import datetime, timezone
from pathlib import Path

import cryptography

from face_inference.config import contract, load_config
from face_inference.matching import aggregate, identify, normalize
from face_inference.service import FaceService
from face_inference.storage import SQLiteRepository


def synthetic_gallery(count, seed=7):
    if count < 1:
        raise ValueError('At least one synthetic template is required')
    rng = random.Random(seed)
    return [dict(person_id=f'synthetic-{i}',
                 vector=normalize([rng.uniform(-1, 1) for _ in range(128)]))
            for i in range(count)]


def percentile(values, quantile):
    """Nearest-rank percentile (no interpolation)."""
    if not values or not 0 < quantile <= 1:
        raise ValueError('Nonempty samples and a quantile in (0, 1] required')
    return sorted(values)[math.ceil(len(values) * quantile) - 1]


def summarize(seconds):
    return dict(samples=len(seconds),
                p50_ms=percentile(seconds, 0.5) * 1000 if seconds else None,
                p95_ms=percentile(seconds, 0.95) * 1000 if seconds else None)


def measure(operation, samples, prepare=lambda: None, clock=time.perf_counter):
    if samples < 1:
        raise ValueError('At least one measurement is required')
    durations = []
    for i in range(samples + 1):
        prepare()
        start = clock()
        operation()
        elapsed = clock() - start
        if i:  # One warmup, excluded from reported samples.
            durations.append(elapsed)
    return summarize(durations)


@contextmanager
def synthetic_service(gallery, gallery_cache=False):
    if not 1 <= len(gallery) <= 10000:
        raise ValueError('Service fixture must respect the configured 10k limit')
    scratch = Path(__file__).resolve().parents[1] / '.scratch'
    scratch.mkdir(exist_ok=True)
    with tempfile.TemporaryDirectory(prefix='matching-', dir=scratch) as folder:
        path = Path(folder) / 'config.json'
        config = load_config(path)
        config['max_people'] = len(gallery)
        config['gallery_cache_enabled'] = gallery_cache
        path.write_text(json.dumps(config))
        config = load_config(path)  # Apply the production config bounds to the fixture.
        repo = SQLiteRepository(config['database'], config['encryption_key'])
        service = FaceService(repo, config, contract())
        with repo.transaction() as db:
            now = service.clock()
            db.execute('INSERT INTO collections VALUES(?,?,?)', ('synthetic', 'Synthetic', now))
            for row in gallery:
                service.upsert_person(db, 'synthetic',
                                      {'id': row['person_id'], 'name': 'Synthetic'})
                # Seed encrypted references; enrollment/duplicate scans are outside this baseline.
                db.execute("UPDATE people SET encrypted_template=?,state='ACTIVE',model_version=?,"
                           'preprocessing_version=?,consented_at=?,expires_at=? '
                           'WHERE collection_id=? AND id=?',
                           (repo.encrypt(row['vector']), service.model['model_version'],
                            service.model['preprocessing_version'], now, now + 86400,
                            'synthetic', row['person_id']))
        yield service


def contention(service, values, samples, workers):
    """Synchronized batches using the real transaction + recognition methods."""
    barrier = threading.Barrier(workers)
    policy = service.policy()

    def request():
        barrier.wait(timeout=30)
        start = time.perf_counter()
        try:
            with service.repository.transaction() as db:
                wait = time.perf_counter() - start
                result = service.recognize(db, 'synthetic', {}, values, policy)
                assert result['state'] == 'SUGGESTED'
            return time.perf_counter() - start, wait, False
        except sqlite3.OperationalError as error:
            if 'locked' not in str(error).lower() and 'busy' not in str(error).lower():
                raise
            return time.perf_counter() - start, None, True

    results = []
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for i in range(samples + 1):
            futures = [pool.submit(request) for _ in range(workers)]
            batch = [future.result() for future in futures]
            if i:
                results.extend(batch)
    return dict(workers=workers, batches=samples,
                requests=summarize([duration for duration, _, _ in results]),
                lock_wait=summarize([wait for _, wait, _ in results if wait is not None]),
                errors=sum(failed for _, _, failed in results))


def peak_rss_bytes():
    try:
        import resource
    except ImportError:
        return None
    peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
    if sys.platform == 'darwin':
        return peak
    if sys.platform.startswith('linux'):
        return peak * 1024
    return None


def run_scenario(
    count, samples=5, workers=4, seed=7, pending=1, algorithm_only=False, gallery_cache=False
):
    if samples < 1 or workers < 1 or not 1 <= pending <= count:
        raise ValueError('Positive samples/workers and pending in [1, templates] required')
    if count > 10000 and not algorithm_only:
        raise ValueError('Above 10k only synthetic algorithm timing is supported')
    tracemalloc.start()
    try:
        gallery = synthetic_gallery(count, seed)
        values = [gallery[0]['vector'][:] for _ in range(3)]
        result = dict(templates=count, kind='synthetic_algorithm_only' if algorithm_only
                      else 'synthetic_service_and_algorithm', seed=seed, observations=3,
                      pending_per_activation=None if algorithm_only else pending,
                      service_recognize=None, activation=None, contention=None)
        if algorithm_only:
            setup_peak = tracemalloc.get_traced_memory()[1]
            tracemalloc.stop()
        else:
            with synthetic_service(gallery, gallery_cache=gallery_cache) as service:
                setup_peak = tracemalloc.get_traced_memory()[1]
                tracemalloc.stop()  # Allocation tracing must not distort latency measurements.
                keys = itertools.count()

                def recognize():
                    key = f'synthetic-request-{next(keys):016d}'
                    outcome = service.mutate('synthetic', 'recognize', dict(
                        idempotency_key=key, capture_session=key, observations=values,
                        model_version=service.model['model_version'],
                        preprocessing_version=service.model['preprocessing_version']))
                    assert outcome['state'] == 'SUGGESTED'
                    assert outcome['person_id'] == gallery[0]['person_id']

                start = time.perf_counter()
                recognize()
                result['service_recognize_cold_ms'] = (time.perf_counter() - start) * 1000
                result['service_recognize'] = measure(recognize, samples)
                activation_policy = {**service.policy(), 'ready': True}

                def reset_pending():
                    with service.repository.transaction() as db:
                        db.executemany("UPDATE people SET state='PENDING' "
                                       'WHERE collection_id=? AND id=?',
                                       [('synthetic', row['person_id'])
                                        for row in gallery[-pending:]])

                def activate():
                    with service.repository.transaction() as db:
                        service.activate(db, 'synthetic', activation_policy)
                        remaining = db.execute("SELECT COUNT(*) FROM people WHERE state='PENDING'")
                        assert remaining.fetchone()[0] == 0

                result['activation'] = measure(activate, samples, prepare=reset_pending)
                result['contention'] = contention(service, values, samples, workers)
        result['aggregate'] = measure(lambda: aggregate(values), samples)
        def match():
            outcome = identify(values, gallery, 0.65)
            assert outcome == (gallery[0]['person_id'], 'MATCHED')

        result['identify'] = measure(match, samples)
        result['memory'] = dict(python_setup_peak_bytes=setup_peak,
                                process_lifetime_peak_rss_bytes=peak_rss_bytes())
        return result
    finally:
        tracemalloc.stop()


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--sizes', nargs='+', type=int, choices=(1000, 10000, 100000),
                        default=[1000, 10000, 100000])
    parser.add_argument('--samples', type=int, default=5)
    parser.add_argument('--workers', type=int, default=4)
    parser.add_argument('--seed', type=int, default=7)
    parser.add_argument('--pending', type=int, default=1,
                        help='Pending references per activation sample; reset outside timing')
    parser.add_argument(
        '--gallery-cache', action='store_true', help='Enable the decrypted gallery cache'
    )
    args = parser.parse_args(argv)
    if args.samples < 1 or args.workers < 1 or not 1 <= args.pending <= min(args.sizes):
        parser.error('positive samples/workers and pending <= smallest size required')
    report = dict(
        schema='synthetic-matching-baseline-v1',
        environment=dict(utc=datetime.now(timezone.utc).isoformat(),
                         python=platform.python_version(),
                         implementation=platform.python_implementation(),
                         os=platform.platform(), machine=platform.machine(),
                         processor=platform.processor(), logical_cpus=os.cpu_count(),
                         sqlite=sqlite3.sqlite_version, cryptography=cryptography.__version__),
        methodology=dict(warmups=1, percentile='nearest-rank',
                         memory='Python setup peak; cumulative process RSS, null if unavailable',
                         activation='Direct method, synthetic ready policy; no approval evidence',
                         contention='Connection + BEGIN IMMEDIATE + evaluation recognize + commit',
                         service='Full evaluation mutate; no HTTP, browser, or inference',
                         gallery_cache=args.gallery_cache),
        scenarios=[
            run_scenario(
                size, args.samples, args.workers, args.seed, args.pending,
                algorithm_only=size > 10000, gallery_cache=args.gallery_cache,
            )
            for size in args.sizes
        ])
    print(json.dumps(report, indent=2, allow_nan=False))


if __name__ == '__main__':
    main()
