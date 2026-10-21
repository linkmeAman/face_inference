"""Application-independent enrollment, evidence and recognition decisions."""
from datetime import datetime, timezone
import hashlib
import json
import re
import time
from zoneinfo import ZoneInfo

from .config import load_policy
from .math import FaceError, aggregate, cosine, identify, observations, vector


def identifier(value, label='ID', minimum=1, maximum=80):
    if not isinstance(value, str) or not minimum <= len(value) <= maximum or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.:-]*', value):
        raise FaceError('INVALID_ID', label + ' must use letters, numbers, dots, colons, dashes or underscores')
    return value


def name(value):
    if not isinstance(value, str) or not value.strip() or len(value) > 160:
        raise FaceError('INVALID_NAME', 'A name of at most 160 characters is required')
    return value.strip()


class FaceService:
    def __init__(self, repository, config, model, clock=time.time):
        self.repository, self.config, self.model, self.clock = repository, config, model, clock
        load_policy(config, model)

    def policy(self):
        # Revoking or replacing an approval report must affect the next operation.
        return load_policy(self.config, self.model)

    def safe_config(self):
        policy = self.policy()
        return dict(contract=self.model, mode=policy['mode'], approved=policy['ready'],
            duplicate_threshold=policy['duplicate_threshold'], recognition_threshold=policy['recognition_threshold'],
            liveness_validated=False, maximum_people=self.config['max_people'])

    @staticmethod
    def person(db, collection, person):
        row = db.execute('SELECT * FROM people WHERE collection_id=? AND id=?', (collection, person)).fetchone()
        if row is None:
            raise FaceError('PERSON_NOT_FOUND', 'Person does not exist in this collection', 404)
        return row

    @staticmethod
    def collection(db, collection):
        if db.execute('SELECT 1 FROM collections WHERE id=?', (collection,)).fetchone() is None:
            raise FaceError('COLLECTION_NOT_FOUND', 'Collection not found', 404)

    @staticmethod
    def dto(row):
        return dict(id=row['id'], name=row['name'], metadata=json.loads(row['metadata']),
            state=row['state'], expires_at=row['expires_at'])

    def purge(self, db=None):
        if db is None:
            with self.repository.transaction() as connection:
                return self.purge(connection)
        now = self.clock()
        count = db.execute('DELETE FROM captures WHERE expires_at<=?', (now,)).rowcount
        count += db.execute("UPDATE people SET encrypted_template=NULL,state='RECAPTURE_REQUIRED' WHERE encrypted_template IS NOT NULL AND expires_at<=?", (now,)).rowcount
        return count

    def list_collections(self):
        with self.repository.transaction() as db:
            return [dict(row) for row in db.execute('SELECT id,name,created_at FROM collections ORDER BY name,id')]

    def list_people(self, collection):
        identifier(collection)
        with self.repository.transaction() as db:
            self.collection(db, collection)
            self.purge(db)
            return [self.dto(row) for row in db.execute('SELECT * FROM people WHERE collection_id=? ORDER BY name,id', (collection,))]

    def events(self, collection):
        identifier(collection)
        with self.repository.transaction() as db:
            self.collection(db, collection)
            return [dict(row) for row in db.execute('SELECT id,person_id,event,state,created_at FROM events WHERE collection_id=? ORDER BY id DESC LIMIT 100', (collection,))]

    def mutate(self, collection, action, payload, person_id=None):
        identifier(collection)
        if not isinstance(payload, dict):
            raise FaceError('INVALID_JSON', 'A JSON object is required')
        key = identifier(payload.get('idempotency_key'), 'Idempotency key', 8, 128)
        allowed = {
            'collection': {'id', 'name'}, 'person': {'id', 'name', 'metadata'},
            'enroll': {'person_id', 'consent', 'capture_session', 'observations', 'template', 'model_version', 'preprocessing_version', 'device', 'lighting'},
            'observe': {'person_id', 'consent', 'capture_session', 'observations', 'template', 'model_version', 'preprocessing_version', 'device', 'lighting'},
            'recognize': {'capture_session', 'observations', 'model_version', 'preprocessing_version', 'eligible_ids'},
            'delete': set(),
        }
        if action not in allowed or set(payload) - allowed[action] - {'idempotency_key'}:
            raise FaceError('UNEXPECTED_FIELD', 'Unknown action or payload fields')
        namespace = ':collections' if action == 'collection' else collection
        serialized = json.dumps([action, collection, person_id, payload], sort_keys=True, separators=(',', ':'), allow_nan=False)
        fingerprint = hashlib.sha256(serialized.encode()).hexdigest()
        with self.repository.transaction() as db:
            previous = db.execute('SELECT payload_sha256,response FROM requests WHERE collection_id=? AND key=?', (namespace, key)).fetchone()
            if previous:
                if previous['payload_sha256'] != fingerprint:
                    raise FaceError('IDEMPOTENCY_CONFLICT', 'This request key belongs to a different payload', 409)
                return json.loads(previous['response'])
            policy = self.policy()
            if policy['ready'] and action in ('enroll', 'observe', 'recognize') and collection not in self.config.get('approved_collection_ids', []):
                raise FaceError('SCOPE_NOT_APPROVED', 'This collection is outside the approved matching scope', 403)
            self.purge(db)
            if action == 'collection':
                if payload.get('id') != collection:
                    raise FaceError('INVALID_ID', 'Collection ID must match the request')
                if db.execute('SELECT 1 FROM collections WHERE id=?', (collection,)).fetchone():
                    raise FaceError('COLLECTION_EXISTS', 'Collection already exists', 409)
                db.execute('INSERT INTO collections VALUES(?,?,?)', (collection, name(payload.get('name')), self.clock()))
                result = dict(state='CREATED', collection_id=collection)
            else:
                self.collection(db, collection)
                if action == 'person':
                    result = self.upsert_person(db, collection, payload)
                elif action == 'delete':
                    person_id = identifier(person_id)
                    self.person(db, collection, person_id)
                    db.execute("UPDATE people SET encrypted_template=NULL,state='NOT_ENROLLED',consented_at=NULL,expires_at=NULL,reference_capture_id=NULL WHERE collection_id=? AND id=?", (collection, person_id))
                    db.execute('DELETE FROM captures WHERE collection_id=? AND person_id=?', (collection, person_id))
                    result = dict(state='DELETED', person_id=person_id)
                else:
                    values = self.validate_capture(payload)
                    capture = identifier(payload.get('capture_session'), 'Capture session', 16, 80)
                    if db.execute('SELECT 1 FROM capture_claims WHERE id=?', (capture,)).fetchone():
                        raise FaceError('CAPTURE_REUSED', 'Capture already submitted; start a new camera capture', 409)
                    db.execute('INSERT INTO capture_claims VALUES(?,?,?,?)', (capture, collection, key, self.clock()))
                    if action == 'recognize':
                        result = self.recognize(db, collection, payload, values, policy)
                    else:
                        result = self.enroll(db, collection, payload, values, policy, action == 'observe')
            person_id = result.get('person_id', person_id)
            db.execute('INSERT INTO events(collection_id,person_id,event,state,created_at) VALUES(?,?,?,?,?)',
                (collection, person_id, action, result['state'], self.clock()))
            db.execute('INSERT INTO requests VALUES(?,?,?,?,?)', (namespace, key, fingerprint, json.dumps(result), self.clock()))
            return result

    def upsert_person(self, db, collection, payload):
        person = identifier(payload.get('id'), 'Person ID')
        metadata = payload.get('metadata', {})
        if not isinstance(metadata, dict) or len(json.dumps(metadata, allow_nan=False)) > 8192:
            raise FaceError('INVALID_METADATA', 'Metadata must be an object of at most 8 KiB')
        exists = db.execute('SELECT 1 FROM people WHERE collection_id=? AND id=?', (collection, person)).fetchone()
        if not exists and db.execute('SELECT COUNT(*) FROM people WHERE collection_id=?', (collection,)).fetchone()[0] >= self.config['max_people']:
            raise FaceError('COLLECTION_FULL', 'Collection person limit reached', 409)
        db.execute('INSERT INTO people(collection_id,id,name,metadata,created_at) VALUES(?,?,?,?,?) ON CONFLICT(collection_id,id) DO UPDATE SET name=excluded.name,metadata=excluded.metadata',
            (collection, person, name(payload.get('name')), json.dumps(metadata, allow_nan=False), self.clock()))
        return dict(state='UPDATED' if exists else 'CREATED', person=self.dto(self.person(db, collection, person)), person_id=person)

    def validate_capture(self, payload):
        for field in ('model_version', 'preprocessing_version'):
            if payload.get(field) != self.model[field]:
                raise FaceError('MODEL_MISMATCH', 'Capture model and preprocessing must match this service')
        return observations(payload.get('observations'))

    def gallery(self, db, collection, active_only=False):
        rows = db.execute("SELECT * FROM people WHERE collection_id=? AND encrypted_template IS NOT NULL AND consented_at IS NOT NULL AND expires_at>? AND state IN ('PENDING','ACTIVE')", (collection, self.clock()))
        result = []
        for row in rows:
            if row['model_version'] != self.model['model_version'] or row['preprocessing_version'] != self.model['preprocessing_version']:
                raise FaceError('INCOMPATIBLE_GALLERY', 'Collection contains incompatible references; recapture is required', 409)
            if not active_only or row['state'] == 'ACTIVE':
                result.append(dict(person_id=row['id'], name=row['name'], state=row['state'], vector=vector(self.repository.decrypt(row['encrypted_template']))))
        return result

    def activate(self, db, collection, policy):
        if not policy['ready']:
            return
        gallery = self.gallery(db, collection)
        for row in gallery:
            if row['state'] != 'PENDING':
                continue
            conflicts = [other for other in gallery if other['person_id'] != row['person_id'] and cosine(row['vector'], other['vector']) >= policy['duplicate_threshold']]
            state = ('REJECTED_DUPLICATE' if any(other['state'] == 'ACTIVE' for other in conflicts)
                     else 'RECAPTURE_REQUIRED' if conflicts else 'ACTIVE')
            db.execute('UPDATE people SET state=? WHERE collection_id=? AND id=?', (state, collection, row['person_id']))

    def enroll(self, db, collection, payload, values, policy, observation_only):
        person = identifier(payload.get('person_id'), 'Person ID')
        current = self.person(db, collection, person)
        if payload.get('consent') is not True:
            raise FaceError('CONSENT_REQUIRED', 'Confirm consent before capturing a face')
        template = aggregate(values)
        if cosine(template, vector(payload.get('template'))) < 1 - 1e-6:
            raise FaceError('AGGREGATE_MISMATCH', 'Submitted template does not match the observations')
        visit = datetime.fromtimestamp(self.clock(), ZoneInfo(self.config.get('timezone', 'Asia/Kolkata'))).date().isoformat()
        visits = [row[0] for row in db.execute('SELECT DISTINCT visit FROM captures WHERE collection_id=? AND person_id=? ORDER BY visit', (collection, person))]
        prior = db.execute('SELECT split FROM captures WHERE collection_id=? AND person_id=? AND visit=? LIMIT 1', (collection, person, visit)).fetchone()
        split = prior[0] if prior else 'calibration' if len(visits) < 2 else 'validation'
        if observation_only and prior and prior[0] == 'calibration':
            raise FaceError('INDEPENDENT_VISIT_REQUIRED', 'Collect validation on a later independent visit')
        expires = self.clock() + self.config['retention_days'] * 86400
        conflicts = [] if observation_only else [row for row in self.gallery(db, collection)
            if row['person_id'] != person and cosine(template, row['vector']) >= policy['duplicate_threshold']]
        purpose = 'LABEL_CONFLICT' if conflicts else 'VALIDATION' if observation_only else 'ENROLL'
        evidence = dict(model_version=self.model['model_version'], preprocessing_version=self.model['preprocessing_version'],
            source=self.config.get('capture_source', 'unvalidated-camera'), consent=True, consented_at=self.clock(),
            device='unreported', lighting='unreported', evaluation_context={'collection_id':collection,
                'eligible_identities':[row[0] for row in db.execute('SELECT id FROM people WHERE collection_id=? ORDER BY id', (collection,))],
                'label_source':'AUTHORIZED_MEMBER_SELECTION'})
        for field in ('device', 'lighting'):
            value = payload.get(field, 'unreported')
            if not isinstance(value, str) or not 1 <= len(value) <= 100:
                raise FaceError('INVALID_CAPTURE_METADATA', 'Device and lighting labels must be bounded strings')
            evidence[field] = value
        db.execute('INSERT INTO captures VALUES(?,?,?,?,?,?,?,?,?)',
            (payload['capture_session'], collection, person, purpose, split, visit, self.repository.encrypt(values), expires, json.dumps(evidence)))
        if observation_only:
            return dict(state='OBSERVATION_SAVED', person_id=person, split=split)
        preserve = current['encrypted_template'] is not None and current['state'] in ('PENDING', 'ACTIVE') and current['expires_at'] > self.clock()
        if conflicts:
            if not preserve:
                db.execute("UPDATE people SET state='REJECTED_DUPLICATE',encrypted_template=NULL WHERE collection_id=? AND id=?", (collection, person))
            duplicate = conflicts[0] if len(conflicts) == 1 else None
            return dict(state='REJECTED_DUPLICATE', person_id=person, previous_reference_preserved=preserve,
                duplicate_person_id=duplicate['person_id'] if duplicate else None, ambiguous=len(conflicts) > 1, verified=policy['ready'])
        if not preserve:
            db.execute("UPDATE people SET encrypted_template=?,state='PENDING',model_version=?,preprocessing_version=?,consented_at=?,expires_at=?,reference_capture_id=? WHERE collection_id=? AND id=?",
                (self.repository.encrypt(template), self.model['model_version'], self.model['preprocessing_version'], self.clock(), expires, payload['capture_session'], collection, person))
        self.activate(db, collection, policy)
        return dict(state=self.person(db, collection, person)['state'], person_id=person, reference_preserved=preserve,
            verified=policy['ready'], evaluation_only=not policy['ready'])

    def recognize(self, db, collection, payload, values, policy):
        if policy['mode'] == 'COLLECT':
            return dict(state='COLLECTION_ONLY', person_id=None, verified=False, business_action=False)
        self.activate(db, collection, policy)
        gallery = self.gallery(db, collection, active_only=policy['ready'])
        eligible = payload.get('eligible_ids')
        if eligible is not None:
            if not isinstance(eligible, list) or len(eligible) > self.config['max_people']:
                raise FaceError('INVALID_ELIGIBILITY', 'Eligible IDs must be a bounded list')
            eligible = {identifier(person) for person in eligible}
            for person in eligible:
                self.person(db, collection, person)
            gallery = [row for row in gallery if row['person_id'] in eligible]
        winner, reason = identify(values, gallery, policy['recognition_threshold'])
        found = next((row for row in gallery if row['person_id'] == winner), None)
        return dict(state=('MATCHED' if policy['ready'] else 'SUGGESTED') if found else 'UNKNOWN',
            person_id=winner, person_name=found['name'] if found else None, reason=reason,
            verified=bool(found and policy['ready']), business_action=False, observation_count=len(values),
            model_version=self.model['model_version'], preprocessing_version=self.model['preprocessing_version'])

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
