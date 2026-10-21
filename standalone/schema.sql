CREATE TABLE IF NOT EXISTS metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS collections (
 id TEXT PRIMARY KEY, name TEXT NOT NULL, created_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS people (
 collection_id TEXT NOT NULL REFERENCES collections(id), id TEXT NOT NULL,
 name TEXT NOT NULL, metadata TEXT NOT NULL DEFAULT '{}', state TEXT NOT NULL DEFAULT 'NOT_ENROLLED',
 encrypted_template BLOB, model_version TEXT, preprocessing_version TEXT,
 consented_at REAL, expires_at REAL, created_at REAL NOT NULL, reference_capture_id TEXT,
 PRIMARY KEY(collection_id,id)
);
CREATE TABLE IF NOT EXISTS captures (
 id TEXT PRIMARY KEY, collection_id TEXT NOT NULL, person_id TEXT NOT NULL,
 purpose TEXT NOT NULL, split TEXT NOT NULL, visit TEXT NOT NULL,
 encrypted_observations BLOB NOT NULL, expires_at REAL NOT NULL, metadata TEXT NOT NULL,
 FOREIGN KEY(collection_id,person_id) REFERENCES people(collection_id,id)
);
CREATE INDEX IF NOT EXISTS captures_person ON captures(collection_id,person_id,visit);
CREATE TABLE IF NOT EXISTS requests (
 collection_id TEXT NOT NULL, key TEXT NOT NULL, payload_sha256 TEXT NOT NULL,
 response TEXT NOT NULL, created_at REAL NOT NULL, PRIMARY KEY(collection_id,key)
);
CREATE TABLE IF NOT EXISTS capture_claims (
 id TEXT PRIMARY KEY, collection_id TEXT NOT NULL, request_key TEXT NOT NULL, created_at REAL NOT NULL
);
CREATE TABLE IF NOT EXISTS events (
 id INTEGER PRIMARY KEY AUTOINCREMENT, collection_id TEXT NOT NULL, person_id TEXT,
 event TEXT NOT NULL, state TEXT NOT NULL, created_at REAL NOT NULL
);
