# SQLite deletion and WAL policy

Every repository connection enables `PRAGMA secure_delete=ON`, alongside foreign
keys and the existing 10-second busy timeout. The database uses WAL journaling
(`src/face_inference/storage.py:17`, `src/face_inference/storage.py:36`). Secure
deletion overwrites deleted content in SQLite database pages; a successful
`PRAGMA wal_checkpoint(TRUNCATE)` then removes old frames from the active WAL
file (`src/face_inference/storage.py:41`, `src/face_inference/storage.py:57`).

Retention purge deletes expired captures and clears expired encrypted face
templates (`src/face_inference/service.py:57`). Face deletion clears the person's
template and consent/reference fields and deletes their captures. It retains the
person row, including name and person metadata; it is not complete subject-record
erasure (`src/face_inference/service.py:127`). Requests and audit events are also
retained (`src/face_inference/service.py:144`,
`src/face_inference/service.py:146`).

## Commit, failure and retry

Service methods explicitly call `SQLiteRepository.checkpoint()` **after commit**,
outside the transaction, when standalone purge removes expired captures or clears
expired templates, when purge does that work inside `list_people`, `mutate`, or
`export_records`, and after a new person-face delete action commits
(`src/face_inference/service.py:57`, `src/face_inference/service.py:73`,
`src/face_inference/service.py:147`, `src/face_inference/service.py:257`).
`transaction()` itself never checkpoints. Reads, empty purges, mutations without
deletion, and stored idempotent response replays do not request a checkpoint
(`src/face_inference/storage.py:45`, `src/face_inference/service.py:108`).

- Failures before or during commit still roll back and close the transaction
  connection (`src/face_inference/storage.py:45`).
- Checkpoints use a fresh connection with its busy timeout disabled. A busy
  `PRAGMA wal_checkpoint(TRUNCATE)` result is retried up to five times with 50 ms
  sleeps (six attempts total). Success returns `True`; if all attempts are busy,
  truncation is skipped and `False` is returned, without raising or logging
  sensitive data. The service still returns the committed deletion's normal
  response (`src/face_inference/storage.py:57`).
- Full WAL truncation is **best-effort under constant read load**. Blocking readers
  or competing connections can leave old WAL frames after deletion. A later
  deletion checkpoint, or an explicit `repository.checkpoint()` after readers
  release, can complete cleanup. Until it succeeds, raw-file cleanup is incomplete
  (`src/face_inference/storage.py:57`, `src/face_inference/service.py:147`).
- SQL execution errors still propagate; the deletion has already committed and
  cannot be rolled back by checkpoint failure. Resolve the storage error before
  explicitly retrying the checkpoint. Retrying a mutation with the **same
  idempotency key and payload** reuses its stored response without duplicate
  mutations or audit events; that replay does not checkpoint
  (`src/face_inference/storage.py:57`, `src/face_inference/service.py:108`).

## Limits and operator controls

This policy covers new deletions through the repository; it does not scrub
historical free pages or cover independent SQL connections that bypass its
settings and service deletion paths (`src/face_inference/storage.py:36`,
`src/face_inference/service.py:57`). Callers that supply their own transaction to
`purge(db)` must request a checkpoint after that transaction commits.

Physical SSD wear-leveling, backups, replicas and snapshots are **not covered**.
Raw-file checks are not a guarantee of forensic erasure from storage hardware.
Encrypted blobs, encryption keys and plaintext metadata still require operator
controls: restrict access, protect keys separately, minimize metadata, apply
retention to every copy, and ensure restored backups cannot silently reintroduce
deleted records. Retained person records and audit/idempotency data need their
own retention and subject-erasure procedures.

## Regression evidence

`tests/test_deletion_storage.py` uses temporary databases only:

- `test_secure_delete_on_each_connection`: two separate connections enforce the
  pragma even when SQLite starts with secure deletion disabled.
- `test_deletion_removes_marker_from_database_and_wal`: a distinctive plaintext
  capture-metadata marker is present in both raw files before real standalone
  purge, purge via `list_people`, `export_records`, or `mutate`, or face deletion;
  afterward it is absent from both, with an empty or removed WAL. A keeper
  connection prevents implicit last-connection cleanup from masking a missing
  checkpoint.
- `test_precommit_failure_rolls_back_without_checkpoint`: body failure,
  interruption and commit failure preserve the capture and encrypted template.
- `test_read_only_transactions_do_not_checkpoint`: eight threads each perform
  40 read-only transactions with zero errors and no checkpoint statements.
- `test_reads_and_empty_purge_do_not_checkpoint`: ordinary service reads, empty
  purge, and a mutation without deletion never request a checkpoint.
- `test_busy_checkpoint_does_not_fail_deletion`: a real blocking reader causes
  `checkpoint()` to return `False` after five 50 ms retry sleeps; every service
  deletion path still succeeds. Explicit checkpoint after the reader releases
  removes the marker from both raw files.
- `test_checkpoint_retry_succeeds_when_reader_releases`: a reader releasing
  during a retry allows the checkpoint to return `True`.
- `test_postcommit_error_visible_and_checkpoint_retry_recovers`: an injected SQL
  error surfaces from a fresh checkpoint connection after commit, without
  rollback. An identical mutation retry has no duplicate audit events; explicit
  checkpoint retry completes raw-file cleanup.
