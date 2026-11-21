# Privacy & Biometric Data Guidance

> **Disclaimer:** This document provides technical architectural guidance and regulatory context for deploying this software. It does **not** constitute legal advice. Organizations deploying biometric identification systems must consult qualified legal counsel to ensure compliance with applicable jurisdictional statutes and regulations.

---

## 1. Biometric Data Architecture & Storage

This system is designed with privacy-by-design principles:
- **No Photographs Stored by Default:**
  The server does **not** store or persist raw photographs, video streams, or facial imagery. In-browser WebAssembly (YuNet and SFace via `onnxruntime-web`) extracts numerical feature embeddings locally on the user's client machine.
  - As defined in the relational database schema ([schema.sql](src/face_inference/schema.sql#L4-L16)), the `people` table stores only `encrypted_template BLOB` and the `captures` table stores only `encrypted_observations BLOB NOT NULL`. There are no image or photo columns in the database.
  - In [service.py](src/face_inference/service.py#L208-L209), observation vectors submitted by the client are encrypted prior to insertion:
    `self.repository.encrypt(values)`
  - In [service.py](src/face_inference/service.py#L219-L221), aggregate biometric templates are encrypted before persistence:
    `self.repository.encrypt(template)`
- **Encryption at Rest:**
  Biometric vectors are encrypted at rest using Fernet authenticated symmetric encryption ([storage.py](src/face_inference/storage.py#L12-L13), [storage.py](src/face_inference/storage.py#L54-L61)) using keys managed in server configuration. SQLite database file permissions are strictly locked to `0600` ([storage.py](src/face_inference/storage.py#L34)).

---

## 2. Consent Verification

- **Enforced Consent Check:**
  Capturing or enrolling biometric templates requires explicit user consent.
  - In [service.py](src/face_inference/service.py#L186-L187), the enrollment routine enforces affirmative consent:
    ```python
    if payload.get('consent') is not True:
        raise FaceError('CONSENT_REQUIRED', 'Confirm consent before capturing a face')
    ```
  - Consent timestamps are recorded alongside the enrollment record in `consented_at` ([service.py](src/face_inference/service.py#L202), [service.py](src/face_inference/service.py#L220)).

---

## 3. Retention & Automatic Purge

- **Configurable Expiration:**
  Each enrollment and capture observation is assigned an expiration timestamp computed based on the configured retention policy ([service.py](src/face_inference/service.py#L196)):
  `expires = self.clock() + self.config['retention_days'] * 86400`
- **Data Purge:**
  The purge routine ([service.py](src/face_inference/service.py#L57-L65)) automatically removes expired observations and invalidates expired templates:
  - Deletes expired capture records: `DELETE FROM captures WHERE expires_at<=?`
  - Nullifies expired templates: `UPDATE people SET encrypted_template=NULL,state='RECAPTURE_REQUIRED' WHERE encrypted_template IS NOT NULL AND expires_at<=?`

---

## 4. Subject Rights: Deletion & Export

- **Right to Erasure (Deletion):**
  When a person is deleted via the mutation API (`action == 'delete'`), all stored biometric observations and template data are purged from the database ([service.py](src/face_inference/service.py#L121-L126)):
  - Captures are deleted: `DELETE FROM captures WHERE collection_id=? AND person_id=?`
  - Person template is cleared: `UPDATE people SET encrypted_template=NULL,state='NOT_ENROLLED',consented_at=NULL,expires_at=NULL,reference_capture_id=NULL WHERE collection_id=? AND id=?`
- **Data Portability / Export:**
  Authorized administrators can retrieve and export enrolled records via `export_records` ([service.py](src/face_inference/service.py#L246-L258)) to fulfill data subject access requests.

---

## 5. Regulatory Frameworks (Reference Pointers)

Deployers must assess their legal obligations under relevant privacy statutes, including but not limited to:

- **GDPR Article 9 (European Union):**
  Under Regulation (EU) 2016/679, biometric data processed for the purpose of uniquely identifying a natural person is classified as a "special category" of personal data. Processing is generally prohibited unless a specific exception under Article 9(2) applies—most commonly explicit, freely-given, specific, and informed consent (Art. 9(2)(a)). Organizations must implement a Data Protection Impact Assessment (DPIA) and ensure transparency and accountability.
- **Illinois BIPA (740 ILCS 14/):**
  The Illinois Biometric Information Privacy Act mandates that private entities possessing biometric identifiers or information must establish and make available a publicly accessible written policy setting forth a retention schedule and destruction guidelines. Entities must obtain written informed consent before collection and are prohibited from selling, leasing, or trading biometric data.
- **Texas CUBI (Tex. Bus. & Com. Code § 503.001):**
  The Capture or Use of Biometric Identifier Act prohibits capturing a biometric identifier of an individual for commercial purposes unless the individual is informed and consents before capture. It requires reasonable care in storage and mandates destruction not later than one year after the purpose for collection ends.

---

## 6. Demographic Bias & Human-Review Fallback

- **Demographic Disparities:**
  Computer vision models can exhibit disparate error rates (false acceptance, false rejection) across different demographic groups due to training dataset distributions, variations in skin tone, facial geometry, age, gender, ambient illumination, and camera sensor quality.
- **Human-in-the-Loop Fallback:**
  Biometric verification must **not** serve as the sole automated arbiter for consequential decisions (e.g., access denial, employment actions, disciplinary actions). Deployers must establish accessible, non-punitive alternative verification paths and provide a human review fallback whenever matching fails, encounters ambiguity, or is contested by the subject.

---

## 7. Liveness & Presentation-Attack Caveat

- **Absence of Presentation Attack Detection (PAD):**
  As recorded in the system policy ([service.py](src/face_inference/service.py#L34-L38)), `liveness_validated=False`.
- The embedded model pipeline does not distinguish between a live human face and a presentation attack (e.g., high-resolution photograph, screen playback, or mask). Deployers must not rely on this toolkit alone for unattended security boundaries or anti-spoofing verification.
