# Face experiment logger contract

`face_experiment_log` is personal data.  A row can be linked to a child through
`ground_truth_member_id`; opaque identifiers do not change that classification.

The inference service alone reads `FACE_EXPERIMENT_ID_SECRET_FILE` (a root-owned
0600 systemd credential) and derives versioned HMAC-SHA-256 identifiers. PHP,
Nginx, exports, queues, diagnostics, and logs must never receive that secret,
frames, embeddings, member IDs, candidate IDs, or template IDs.

The writer may call only `face_experiment_log_insert(JSON)`.  The procedure
rejects a supplied ground-truth member ID; an independently authorized teacher
confirmation flow is required before that field may be populated. Raw-table
access belongs only to the writer and DBA roles. Administrators use the
redacted `face_experiment_status` view.

After the migration, a DBA provisions the dedicated local writer and a
status-only reader (with credentials kept outside this repository):

```sql
CREATE USER 'face_experiment_writer'@'127.0.0.1' IDENTIFIED BY '<generated-secret>';
GRANT EXECUTE ON PROCEDURE ccc_database.face_experiment_log_insert TO 'face_experiment_writer'@'127.0.0.1';
CREATE USER 'face_experiment_status'@'localhost' IDENTIFIED BY '<generated-secret>';
GRANT SELECT ON ccc_database.face_experiment_status TO 'face_experiment_status'@'localhost';
GRANT SELECT ON ccc_database.face_experiment_status_by_model TO 'face_experiment_status'@'localhost';
```

Do not grant either account access to `face_experiment_log` or
`face_model_template`.

Each evidence payload has exactly three positional score arrays. Its dictionary
contains HMAC template/candidate identifiers once, followed by numeric score,
quality, and PAD arrays. At most 300 roster members, five templates per member,
three frames, and 256 KiB serialized evidence are accepted. Logging is disabled
at 9.5 GiB (warning begins at 8 GiB); primary SFace decisions never change.

Identifiable log rows expire after 180 days. The purge first makes a restricted,
score-only calibration export, hashes it in the purge ledger, then deletes in
batches. Exports exclude member, scan, session, branch, template, probe,
candidate, timestamp, and opaque-ID values and are retained for 24 months.

Rotation: install a new root-owned credential, increment
`FACE_EXPERIMENT_OPAQUE_ID_VERSION`, restart `face-inference` and
`face-experiment-shadow`, and retain prior rows unchanged. Never join opaque IDs
across versions.
