# Biometric Export Safety and Handling Guidance

This document details the security model, operational risks, and handling procedures for biometric data produced by the `face-inference export` command.

## Overview and Code Architecture

The `face-inference export` CLI command extracts biometric capture evidence for offline calibration, algorithm evaluation, and cross-visit validation. While stored biometric templates and raw observations remain encrypted at rest inside SQLite via Fernet symmetric encryption (`src/face_inference/storage.py:13-19`), **the export command decrypts observations and emits raw, unencrypted floating-point vectors into a plaintext JSONL file**.

### Code Implementation Facts

1. **CLI Execution (`src/face_inference/cli.py:65-74`)**:
   ```python
   if args.command == 'export':
       if not args.collection or not args.output:
           parser.error('export requires --collection and --output')
       destination = Path(args.output).resolve()
       rows = service.export_records(args.collection)
       with open(destination, 'x', opener=lambda p, flags: os.open(p, flags, 0o600)) as stream:
           for row in rows:
               stream.write(json.dumps(row, allow_nan=False) + '\n')
       print(json.dumps({'exported_captures':len(rows), 'output':str(destination)}))
       return 0
   ```
   - The CLI uses Python's `'x'` flag combined with `os.open(..., flags, 0o600)`, ensuring exclusive creation (`O_EXCL`) and assigning POSIX permissions `0600` (read/write only by the file owner).
   - Each capture record is written as a plaintext JSON line (`json.dumps(row, allow_nan=False)`).

2. **Decryption and Payload Construction (`src/face_inference/service.py:249-261`)**:
   ```python
   def export_records(self, collection):
       identifier(collection)
       with self.repository.transaction() as db:
           self.collection(db, collection)
           self.purge(db)
           result = []
           rows = db.execute("SELECT c.*,p.reference_capture_id FROM captures c JOIN people p ON p.collection_id=c.collection_id AND p.id=c.person_id WHERE c.collection_id=? AND c.purpose IN ('ENROLL','VALIDATION') ORDER BY c.visit,c.id", (collection,))
           for row in rows:
               values = self.repository.decrypt(row['encrypted_observations'])
               result.append(dict(json.loads(row['metadata']), identity=row['person_id'], embedding=aggregate(values),
                   observations=values, visit=row['visit'], split=row['split'], purpose=row['purpose'],
                   capture_session=row['id'], enrollment_candidate=row['id'] == row['reference_capture_id']))
           return result
   ```
   - In `service.py:257`, `values = self.repository.decrypt(row['encrypted_observations'])` decrypts the stored per-frame 128-dimensional biometric observations.
   - In `service.py:258-260`, each record incorporates the subject identity (`row['person_id']`), the recomputed aggregate vector (`embedding=aggregate(values)`), and the raw decrypted observations (`observations=values`).

3. **Server-Side Aggregate Verification (`src/face_inference/service.py:183-190`)**:
   - Enrollment requires explicit consent (`service.py:186-187`).
   - The server independently computes `template = aggregate(values)` from the submitted observations (`service.py:188`).
   - If the client-submitted template deviates from the server-computed aggregate, `service.py:189-190` raises `FaceError('AGGREGATE_MISMATCH', 'Submitted template does not match the observations')` (HTTP status 400).
   - This transaction rolls back immediately; neither the capture nor the person record is modified, ensuring crafted or mismatched templates cannot enter the database or subsequent exports.

4. **Existing Documentation Warning (`docs/guide.md:263-280`)**:
   - `docs/guide.md:270` specifically notes: *"The export is exclusive mode 0600, contains decrypted vectors and must remain private."*

---

## Security Risks of Plaintext Biometric Exports

Although the CLI creates the output file with mode `0600`:

- **Lack of Cryptographic Confidentiality at Rest**: Mode `0600` is purely an operating system Discretionary Access Control (DAC) permission. The file content itself is **completely unencrypted plaintext**.
- **Co-located Processes**: Any process running under the same user ID (UID), as well as any process with `CAP_DAC_OVERRIDE` or root privileges, can read the export file directly.
- **Unintended Data Exposure**: Automated host processes—such as backup daemons, crash reporters, filesystem indexers, log aggregators, or cloud synchronization utilities (e.g., Dropbox, OneDrive, iCloud)—may read, copy, or transmit the unencrypted biometric vectors if written to standard directories.
- **Biometric Immutability**: Unlike passwords or API tokens, facial embeddings cannot be revoked or changed if compromised. Plaintext 128-dimensional unit vectors can enable cross-system correlation or reconstruction attacks against face recognition models.

---

## Operational Safety Guidance

### 1. Destination Selection
Operators executing `face-inference export` must direct the output to an explicitly secured and isolated filesystem:
- **Ephemeral In-Memory Filesystems (Recommended)**: Target a `tmpfs` or RAM disk mounted with restrictive permissions (e.g., `mount -t tmpfs -o mode=0700,noexec,nosuid,nodev tmpfs /secure/ramdisk`). In-memory storage avoids writing plaintext biometric vectors to persistent physical blocks.
- **Encrypted Local Storage**: If written to disk, ensure the path resides on a strongly encrypted volume (e.g., LUKS on Linux, APFS encrypted on macOS, BitLocker on Windows) where hardware-backed keys protect the physical media.
- **Prohibited Destinations**:
  - Never write exports to shared temporary directories (e.g., `/tmp` or `/var/tmp` without private namespacing).
  - Never write exports to network-attached filesystems (NFS, SMB) where vectors traverse the network in plaintext.
  - Never write exports to directories monitored by cloud storage syncing tools.

### 2. Access Restriction and Process Isolation
- Run the export CLI under a dedicated, unprivileged service account that does not run interactive login sessions or third-party background services.
- Ensure the enclosing directory permissions are restricted (e.g., `chmod 0700 /path/to/export/dir`).
- Prohibit running the export command under administrative accounts (`root` or `Administrator`).

### 3. Retention and Lifecycle
- Treat exported evidence files as short-lived artifacts intended solely for active evaluation and validation tasks.
- Establish an explicit time-to-live (TTL). Purge the exported file immediately upon completing the evaluation.
- Regularly invoke `face-inference purge` on the server database to erase expired records as governed by retention policies.

### 4. Physical Storage Realities and Secure Deletion
Operators must **never claim or rely upon guaranteed physical erasure** through standard file deletion:
- **Flash Memory and Wear Leveling**: Modern Solid State Drives (SSDs) and NVMe storage devices utilize Flash Translation Layers (FTL) and wear-leveling algorithms. Overwriting or running user-space utilities (such as `shred`, `srm`, or `wipe`) writes to newly allocated physical NAND flash blocks while leaving the original blocks intact until garbage-collected by the controller.
- **Copy-on-Write (CoW) and Journaling**: Filesystems such as APFS, ZFS, Btrfs, or ext4 with journaling redirect writes to new blocks and maintain metadata logs, preserving past block data.
- **Memory and Swap**: Paged memory or crash dumps may write decrypted vectors to swap partitions if swap encryption is not enabled.
- **Decommissioning**: True physical sanitization requires cryptographic volume erasure (discarding encryption keys) or physical drive destruction meeting NIST SP 800-88 standards.

---

## Not a Complete System Backup

The `face-inference export` command **must not be treated as a complete system or database backup**:

1. **Filtered Scope**: `service.py:255` explicitly filters captures:
   ```sql
   WHERE c.collection_id=? AND c.purpose IN ('ENROLL','VALIDATION')
   ```
   Records with purpose `'LABEL_CONFLICT'`, unconsented attempts, and deleted capture references are excluded from the output.
2. **Missing Relational Metadata**: The export contains only flattened JSONL capture rows. It does not contain:
   - Collection policy definitions and custom thresholds (`duplicate_threshold`, `recognition_threshold`, `retention_days`).
   - Non-enrolled person records, custom person metadata, or pending invitation states.
   - Idempotency transaction logs and token generation secrets.
   - Database schema migrations, event logs, and system configuration.
3. **No Restore Path**: The `face-inference` toolkit does not provide an import mechanism (`face-inference import` does not exist).
4. **Backup Recommendation**: Complete system backups must be performed directly at the SQLite database level using standard atomic backup mechanisms (e.g., `sqlite3 <db-path> ".backup <backup-path>"`) combined with secure, encrypted archival of `config.json`.
