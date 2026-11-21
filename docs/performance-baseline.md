# Synthetic matching and contention baseline

This opt-in local harness measures the current Python implementation with generated
vectors, isolated encrypted SQLite data, and no camera input. It provides a starting
measurement at 1k and 10k templates and a **synthetic, algorithm-only 100k** scenario.
It establishes no production SLO, capacity breakpoint, recognition accuracy, or
language-rewrite recommendation. See the workload and measurement limits below.

## Reproduce

From the repository root, using the project development environment:

```sh
PYTHONPATH=src .venv/bin/python tools/benchmark_matching.py --samples 5 --workers 4
```

The default sizes are `1000 10000 100000`, seed is `7`, and there is one pending
reference per activation sample. To save machine-readable results locally:

```sh
PYTHONPATH=src .venv/bin/python tools/benchmark_matching.py --samples 5 --workers 4 > .scratch/s04-baseline.json
```

To measure a different incremental activation workload, explicitly select it:

```sh
PYTHONPATH=src .venv/bin/python tools/benchmark_matching.py --sizes 1000 10000 --samples 5 --workers 4 --pending 10
```

The last command is an example, not an observed result in this baseline. Increasing
`--pending` increases the comparisons performed by activation; all-pending activation
is unmeasured here (`service.py:175–181`). Do not infer its timing from one-pending results.

## Source facts and workload

Source paths in this document are relative to `src/face_inference/`, unless specified.
The source baseline is `refactor/v1` at `a371cf10687e5210064bbd13c7cc14d51fa09008`.

- `config.py:53–54` sets evaluation defaults, duplicate threshold `0.85`, recognition
  threshold `0.65`, and default maximum people `1000`. `config.py:158–161` restricts
  configured maximum people to `1..10000`; `service.py:143–153` enforces the collection
  limit. The fixture sets and validates a maximum equal to the scenario size and
  uses `upsert_person` to create each synthetic person. The 100k scenario never
  creates service storage (`tools/benchmark_matching.py:66–94,141–160,228–229`).
- The seeded local RNG generates independent, normalized, 128-dimensional uniform
  random vectors. Three identical observations of the first vector form the known
  probe; matching must return that synthetic reference. These are mathematical
  fixtures, not face embeddings or empirical identity distributions
  (`tools/benchmark_matching.py:30–36,148–149,191–195`).
- Production aggregation validates and normalizes observations, selects a medoid,
  rejects outliers and normalizes the retained mean (`matching.py:37–60`). Matching
  scans the gallery for each observation and can exit early on a failed or ambiguous
  observation (`matching.py:63–76`). This known-match workload exercises all three
  observation scans, not the early-exit unknown case.
- Gallery loading selects eligible, unexpired, consented pending/active references,
  validates model compatibility, decrypts and validates each vector
  (`service.py:161–169`). The fixture creates only synthetic consent timestamps and
  encrypted templates, without performing enrollment or supplying approval evidence
  (`tools/benchmark_matching.py:79–93`).
- Activation loads the gallery, compares each pending reference against other
  references, and updates its state (`service.py:171–181`). Evaluation policy normally
  has `ready=False` (`config.py:193–197`); the separate activation measurement calls
  that method directly with a synthetic `ready=True` policy. This exercises the
  activation algorithm, not the approval flow or a complete approved request
  (`tools/benchmark_matching.py:173–188`). No live-camera approval report is fabricated.
- Repository connections use a 10-second busy timeout; transactions use
  `BEGIN IMMEDIATE`, commit/rollback, and close. Repository initialization enables WAL
  (`storage.py:15–17,35–53`). No timeout, journal mode, or production code is changed.

## What each metric includes

Each sequential metric has one untimed warmup and five timed samples. The timer is
`perf_counter`; p50/p95 use nearest rank, without interpolation. With five samples,
p95 is the maximum and cannot estimate a stable production tail
(`tools/benchmark_matching.py:39–63`). All times below are milliseconds.

| Metric | Timed work |
|---|---|
| `aggregate` | Existing aggregation on three observations; gallery size does not affect its input. |
| `identify` | Existing matching over the in-memory gallery; no SQLite or decryption. |
| `service_recognize` | Full evaluation `mutate`: payload validation/hash, connection/transaction, policy, purge, fresh capture claim, gallery read/decryption, matching, event/idempotency writes, commit and result checks. No HTTP. |
| `activation` | Transaction, gallery read/decryption, one pending reference's comparisons and update, pending-count check and commit. The pending reset occurs outside timing. |
| Contention request | Connection/transaction, direct evaluation `recognize`, result check and commit. Excludes the full mutation envelope, policy reload, purge, capture claims, events and request writes. |
| Contention lock wait | Elapsed time before entering the repository transaction, including connection setup, SQLite `BEGIN IMMEDIATE` acquisition and scheduling. This is not a pure SQLite lock profiler. |

The full mutation path is at `service.py:83–141`; recognition is at
`service.py:229–247`. The harness timing boundaries are at
`tools/benchmark_matching.py:101–125,163–195`. Concurrent workers share one isolated
database and use separate real repository connections. A barrier starts each batch;
one warmup batch is discarded, then five batches of four requests yield 20 request
and (when successful) 20 lock-wait samples. Lock/busy failures count as errors and
remain in request latency samples, with no successful acquisition sample. Unexpected
errors fail the run instead of becoming a timing (`tools/benchmark_matching.py:96–125`).

Memory is reported in bytes: `python_setup_peak_bytes` is the `tracemalloc` peak during
vector/fixture setup only. Tracing stops before latency measurements. It excludes
native SQLite/crypto allocations and is not a per-request allocation peak. RSS is
the process-lifetime high-water mark, cumulative across scenario order, including
tracing overhead; it is not an isolated scenario delta or steady-state working set.
RSS is `null` when unsupported (`tools/benchmark_matching.py:128–160,196–200`).

## Observed run

Run began at `2026-11-21T18:56:26.781397+00:00`. CPython 3.12.16, `macOS-26.5.1-arm64-arm-64bit`;
machine `arm64`, processor `arm`, 8 logical CPUs;
SQLite 3.54.1, cryptography 50.0.2. Shared development host;
CPU affinity, system load, filesystem cache and background activity were not controlled.
Command: the five-sample/four-worker invocation above, with default sizes, seed and pending count.

| Metric (p50 / p95 ms) | 1k service + algorithm | 10k service + algorithm | Synthetic 100k algorithm only |
|---|---:|---:|---:|
| Aggregation (n=5) | 0.736 / 1.768 | 0.322 / 0.328 | 0.479 / 0.655 |
| Direct matching (n=5) | 40.173 / 61.661 | 264.678 / 331.146 | 3202.204 / 4298.579 |
| Full evaluation service (n=5) | 181.257 / 293.166 | 1544.197 / 2176.256 | Unmeasured |
| One-pending activation (n=5) | 138.362 / 172.652 | 1433.908 / 1889.886 | Unmeasured |
| Contention request (n=20) | 286.002 / 712.132 | 4329.716 / 7311.417 | Unmeasured |
| Connection + lock wait (n=20) | 150.220 / 575.316 | 2932.669 / 5436.937 | Unmeasured |

Both contention scenarios completed 20 measured requests with **0 lock/busy errors**.
Successful lock acquisitions also numbered 20 in each; one warmup batch is excluded.

| Memory (bytes) | 1k | 10k | Synthetic 100k algorithm only |
|---|---:|---:|---:|
| Python setup allocation peak | 4,475,107 | 44,047,074 | 439,982,730 |
| Process-lifetime peak RSS | 55,640,064 | 241,254,400 | 898,334,720 |

The observed service path costs more than direct matching; its additional work includes
gallery reads/decryption and the mutation envelope. These timings do not attribute costs
to individual operations. The synchronized contention measurements include time waiting
for the real immediate transaction, without establishing sustained throughput.
The synthetic 100k result is solely an in-memory algorithm measurement; service-level
100k remains unavailable under the validated production limit. No measured workload
here establishes a capacity breakpoint or justifies a Rust/C++ rewrite.

## Checks and limits

```sh
.venv/bin/python -m pytest -q tests/test_benchmark_matching.py
.venv/bin/ruff check src tests
.venv/bin/ruff check tools/benchmark_matching.py
```

The deterministic tests contain no speed thresholds. Test ids in
`tests/test_benchmark_matching.py` cover the following contract:

| Test | Coverage |
|---|---|
| `test_samples_are_reproducible_valid_and_match` | Seed reproducibility, distinct valid unit vectors, real aggregation and matching. |
| `test_percentile_and_measurement_exclude_warmup` | Nearest rank, one sample, empty input, exact fake-clock durations, warmup/reset counts, invalid sample count. |
| `test_temporary_service_is_isolated_and_cleans_up` | Environment-selected production sentinel remains untouched; temporary encrypted gallery exists and is removed on normal exit and exceptions. |
| `test_small_run_exercises_service_activation_and_contention` | Real tiny service/activation/contention path, sample counts, ordering, memory metric, and no IDs/keys in output. |
| `test_algorithm_only_never_creates_storage` | Algorithm-only operation with storage forbidden; unavailable service metrics are null. |
| `test_limit_rejection_and_cli_routes_100k_to_algorithm_only` | Oversize service rejection, invalid arguments, automatic 100k algorithm-only routing. |

No production data, datasets, real faces, approval artifacts, identifiers or secrets
are read or emitted. Temporary fixture secrets and generated internal IDs stay inside
the process and its private temporary directory; only counts, timings, memory and
environment are printed (`tools/benchmark_matching.py:66–94,150–153,196–230`). The
fixture is removed on normal exit or Python exceptions; forced termination can leave
a `matching-*` scratch directory. Synthetic ciphertext and random keys are disposable.

Unmeasured: browser inference, networking/TLS, real-camera distributions, unknown or
ambiguous probes, enrollment, bulk/all-pending activation, approved end-to-end flows,
cross-process or sustained offered-load throughput, cold disk/cache, per-request heap
peaks, and service-level 100k. This local thread workload and tiny sample count cannot
establish deployment capacity or a rewrite breakpoint. The full suite and socket
integration tests are delegated to the captain, per S04 and CONTEXT.
