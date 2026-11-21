# Compliance & Privacy Starter Templates

> **Legal Disclaimer:**
> These templates and drafting starters are operational frameworks provided solely to assist deploying organizations in establishing documentation for local legal counsel and compliance review. They do **not** constitute legal advice, nor do they represent a universal consent determination or guarantee regulatory compliance under the European Union General Data Protection Regulation (GDPR), the Illinois Biometric Information Privacy Act (BIPA), Texas CUBI, or any other global or regional privacy statute. Compliance requirements vary significantly across jurisdictions, operational contexts, and business sectors. Deploying organizations must engage qualified local legal counsel to evaluate their specific processing activities, determine lawful bases, and finalize all policies and notices prior to processing biometric data.
>
> **Technical Scope & Presentation-Attack Caveat:**
> As recorded in the system policy ([service.py](src/face_inference/service.py#L34-L38)) and documented in [PRIVACY.md:80-84](PRIVACY.md#L80-L84), this software does **not** provide Presentation Attack Detection (PAD) (`liveness_validated=False`). The underlying model pipeline does not differentiate between a live physical face and a 2D photograph, video replay, or physical mask. Deployers must not rely on this software as an unattended single-factor physical security boundary.
>
> **Storage & Erasure Limitations:**
> Application purge and deletion commands execute SQL `DELETE` and `UPDATE` operations within an SQLite database file. These operations update internal database indices and free database pages for reuse; they do **not** provide forensic, bit-level physical overwriting or zeroization of underlying storage hardware. Additionally, database backups, snapshots, and disaster recovery archives retain historical states until expired in accordance with backup retention schedules. Deployers must avoid asserting guaranteed physical media erasure to data subjects.
>
> **Usage Instructions:**
> Every field bracketed as `[FILL-IN: ...]` represents an organization-specific placeholder that must be completed by the deploying organization in consultation with its legal and compliance teams. No placeholder value should be assumed or presented as law.

---

## 1. Biometric Information Notice and Consent Starter Template

*This starter template is designed for organizations that require individuals to review and acknowledge biometric processing prior to enrollment.*

### Biometric Processing Notice & Consent Form

**Document Version:** `[FILL-IN: e.g., 1.0]`  
**Effective Date:** `[FILL-IN: DATE]`  

#### 1. Identity of Data Controller / System Operator
- **Operating Entity:** `[FILL-IN: FULL LEGAL ENTITY NAME OF OPERATOR]`
- **Operating Address:** `[FILL-IN: BUSINESS / MAILING ADDRESS]`
- **Privacy Officer / DPO Contact:** `[FILL-IN: NAME, TITLE, EMAIL, PHONE NUMBER]`

#### 2. Specific Purpose of Processing
Biometric information processed through the Face Inference Service is collected and used strictly for the following defined purpose(s):
- `[FILL-IN: SPECIFY OPERATIONAL PURPOSE, e.g., voluntary identity verification for authenticated workstation access / voluntary entry verification at designated facilities]`
- **Scope Restriction:** Biometric data will not be processed, analyzed, or shared for secondary purposes, marketing, surveillance, or commercial sale without prior affirmative written authorization.

#### 3. Scope of Data Subjects
This notice applies to:
- `[FILL-IN: TARGET POPULATION, e.g., enrolled employees / contractors / voluntary system users within specified department or location]`

#### 4. Categories of Data Processed & Architectural Safeguards
- **Client-Side Feature Extraction (No Raw Images Stored):**
  Raw camera frames and photographs are processed locally within the user's web browser using WebAssembly client modules (YuNet face detection and SFace feature extraction). Raw facial images and video feeds are **not** transmitted to, received by, or stored on the server ([schema.sql](src/face_inference/schema.sql#L4-L16), [PRIVACY.md](PRIVACY.md#L7-L19)).
- **Biometric Templates & Observations:**
  The browser extracts a 128-dimensional floating-point mathematical vector representing facial geometry. When submitted for enrollment, this numerical vector is encrypted at rest using Fernet authenticated symmetric encryption ([storage.py](src/face_inference/storage.py#L12-L13), [storage.py](src/face_inference/storage.py#L54-L61)) and persisted in an access-restricted database with file permissions locked to `0600` ([storage.py](src/face_inference/storage.py#L34)).
- **Associated Metadata:**
  The server persists an administrative subject identifier (`person_id`), display name, enrollment state, affirmative consent status, consent timestamp (`consented_at`), and scheduled expiration timestamp (`expires_at`).

#### 5. Recipients and Third-Party Disclosures
- **Internal Access Roles:** `[FILL-IN: SPECIFY AUTHORIZED INTERNAL ROLES, e.g., system administrators, security officers]`
- **Hosting / Infrastructure Providers:** `[FILL-IN: SPECIFY ON-PREMISES SERVER OR THIRD-PARTY CLOUD HOSTING PROVIDER, e.g., self-hosted on company infrastructure / cloud provider name and region]`
- **Prohibition on Commercial Transfer:** The operator does not sell, lease, trade, or otherwise profit from data subjects' biometric identifiers or information.

#### 6. Lawful Basis for Processing
- `[FILL-IN: LAWFUL BASIS TO BE DETERMINED BY OPERATOR AND LEGAL COUNSEL, e.g., GDPR Article 6(1)(a) and Article 9(2)(a) explicit consent, Illinois BIPA 740 ILCS 14/15(b) written informed release, or applicable jurisdictional basis]`

#### 7. Voluntary Participation & Non-Punitive Alternatives
- **Affirmative Consent Requirement:**
  Enrollment requires an affirmative consent flag, enforced technically by the system software ([service.py](src/face_inference/service.py#L183-L187)):
  ```python
  if payload.get('consent') is not True:
      raise FaceError('CONSENT_REQUIRED', 'Confirm consent before capturing a face')
  ```
- **Voluntary Choice:** Providing biometric data is entirely voluntary. Individuals may decline or withdraw consent at any time without adverse employment consequences, disciplinary action, denial of service, or punitive impact ([PRIVACY.md](PRIVACY.md#L75-L76)).
- **Alternative Verification Options:**
  Individuals who decline or withdraw consent will be provided with non-biometric alternative verification paths of equivalent convenience:
  - `[FILL-IN: LIST ALTERNATIVES, e.g., standard RFID badge / physical keycard / alphanumeric password or PIN / manual identity verification by staff]`
- **Withdrawal Procedure:**
  Individuals may withdraw consent at any time by contacting:
  - `[FILL-IN: WITHDRAWAL INSTRUCTIONS, e.g., email privacy@example.com or submit form via internal portal]`
  Upon receipt of withdrawal, the operator will invoke the deletion mutation API ([service.py](src/face_inference/service.py#L121-L126)) to purge enrolled templates.

#### 8. Presentation Attack Detection (Liveness) Caveat
- **Absence of Anti-Spoofing:**
  Notice is hereby given that the software operates with `liveness_validated=False` ([service.py](src/face_inference/service.py#L34-L38), [PRIVACY.md](PRIVACY.md#L80-L84)). The system does not verify physical liveness and cannot detect presentation attacks such as printed photos, screen playback, or masks. The system is deployed in a supervised context and is not used as an unattended security boundary.

#### 9. Acknowledgment & Consent Sign-Off
I confirm that I have read and understood this Biometric Information Notice and Consent Form. I voluntarily authorize `[FILL-IN: OPERATOR NAME]` to collect, encrypt, and store my facial mathematical biometric template for the purposes described above.

- **Data Subject Name:** `[FILL-IN: PRINTED NAME]`
- **Subject Identifier / Employee ID:** `[FILL-IN: ID NUMBER]`
- **Signature / Electronic Affirmation:** `[FILL-IN: SIGNATURE OR AFFIRMATIVE CHECKBOX TIMESTAMP]`
- **Date:** `[FILL-IN: DATE]`

---

## 2. Biometric Data Retention and Deletion Schedule Starter Template

*This starter schedule defines retention periods, automated purge routines, subject-directed erasure workflows, and backup lifecycle limits.*

**Organization:** `[FILL-IN: OPERATOR LEGAL NAME]`  
**System:** Face Inference Service (`[FILL-IN: ENVIRONMENT / COLLECTION ID]`)  
**Effective Date:** `[FILL-IN: DATE]`  
**Next Review Date:** `[FILL-IN: SCHEDULED ANNUAL REVIEW DATE]`  

### 1. Retention Triggers & Established Periods
In accordance with internal governance policies and applicable privacy statutes, biometric data is retained only for the duration necessary to satisfy the initial operational purpose:

| Data Category | Storage Location | Retention Trigger | Maximum Retention Period |
|---|---|---|---|
| Enrolled Biometric Template | SQLite `people` table (`encrypted_template BLOB`) | Active enrollment status | `[FILL-IN: RETENTION PERIOD DETERMINED BY OPERATOR / COUNSEL, e.g., until employment termination or maximum 365 days]` |
| Observation Vectors | SQLite `captures` table (`encrypted_observations BLOB`) | Enrollment verification capture | `[FILL-IN: RETENTION PERIOD, e.g., X days from capture date]` |
| Enrollment Metadata & Consent Log | SQLite `people` table (`consented_at`, `expires_at`, `metadata`) | Maintained alongside template | Purged upon template deletion or retained for statutory audit period: `[FILL-IN: AUDIT RETENTION WINDOW]` |

#### Technical Configuration:
The software enforces an automated expiration timestamp at enrollment ([service.py](src/face_inference/service.py#L196-L201)):
```python
expires = self.clock() + self.config['retention_days'] * 86400
```
Operators configure `retention_days` within the server configuration file (`config.json`) to reflect the legally approved retention timeframe.

### 2. Automated Purge Implementation
- **Purge Mechanism:**
  The server implements an automated purge method ([service.py](src/face_inference/service.py#L57-L65)):
  ```python
  def purge(self, db=None):
      now = self.clock()
      count = db.execute('DELETE FROM captures WHERE expires_at<=?', (now,)).rowcount
      count += db.execute("UPDATE people SET encrypted_template=NULL,state='RECAPTURE_REQUIRED' WHERE encrypted_template IS NOT NULL AND expires_at<=?", (now,)).rowcount
      return count
  ```
- **Operational Execution:**
  Operators must establish scheduled execution of the purge routine (e.g., daily cron job or periodic automation script). When triggered:
  - Expired capture observation records are deleted from active tables.
  - Expired templates are cleared (`encrypted_template=NULL`) and the subject state transitions to `RECAPTURE_REQUIRED`.

### 3. Subject-Initiated Erasure (Right to Erasure / Deletion)
Upon receipt of a verified deletion or consent withdrawal request, an authorized administrator invokes the subject deletion mutation API (`action == 'delete'`) ([service.py](src/face_inference/service.py#L121-L126)):
- Captures are immediately deleted:
  `DELETE FROM captures WHERE collection_id=? AND person_id=?`
- Template and consent timestamps are reset:
  `UPDATE people SET encrypted_template=NULL,state='NOT_ENROLLED',consented_at=NULL,expires_at=NULL,reference_capture_id=NULL WHERE collection_id=? AND id=?`

### 4. Deletion and Backup Limits
- **Logical Deletion vs. Physical Storage Media Overwriting:**
  Deployers must maintain technical transparency: database `DELETE` and `UPDATE` statements remove records from active database indexing structures and mark database pages as free. They do **not** perform hardware-level forensic erasure or overwrite physical magnetic/flash storage blocks. Periodic database vacuuming (`VACUUM`) defragments database files, but operating system-level storage reallocations determine eventual bit overwriting.
- **Backup and Archive Lifecycle:**
  Routine disaster recovery backups, storage snapshots, and filesystem mirrors retain historical database states:
  - Backup Rotation Window: `[FILL-IN: OPERATOR BACKUP LIFECYCLE, e.g., rolling 30-day snapshot rotation]`.
  - Backup Overwriting: Biometric records stored within offline backup archives are not selectively excised on demand; they are overwritten automatically as backup cycles expire.
  - Backup Protection: All backup archives must remain encrypted at rest using cryptographic controls matching or exceeding production storage encryption standards.

### 5. Access Security & Storage Controls
During the active retention period:
- Biometric vectors remain encrypted at rest via Fernet authenticated cryptography ([storage.py](src/face_inference/storage.py#L12-L13), [storage.py](src/face_inference/storage.py#L54-L61)).
- The SQLite database file permissions are strictly restricted to mode `0600` (read/write by owner only) ([storage.py](src/face_inference/storage.py#L34)).
- Administrative endpoints and data queries require valid scoped bearer session tokens ([server.py](src/face_inference/server.py), [tokens.py](src/face_inference/tokens.py)).
- All external network transport must be protected with TLS encryption via an approved reverse proxy ([docs/deployment.md](docs/deployment.md#L9-L22)).

---

## 3. Data Protection Impact Assessment (DPIA) Starter Template

*This DPIA starter helps organizations systematically evaluate privacy risks, architectural safeguards, necessity, proportionality, and residual risks.*

### Data Protection Impact Assessment (DPIA)

#### Section 1: Administrative Information & Document Control
- **Organization Name:** `[FILL-IN: OPERATOR LEGAL NAME]`
- **Project / System Name:** Biometric Face Inference Service
- **Lead Assessor:** `[FILL-IN: NAME, TITLE, DEPARTMENT]`
- **Data Protection Officer / Counsel:** `[FILL-IN: NAME, TITLE, CONTACT]`
- **Assessment Date:** `[FILL-IN: DATE]`
- **Scheduled Re-assessment Date:** `[FILL-IN: ANNUAL REVIEW DATE]`

#### Section 2: Systematic Description of Processing
1. **Operational Context & Necessity:**
   - Describe why biometric processing is being introduced and what operational problem it addresses:
   - `[FILL-IN: DESCRIBE OPERATIONAL CONTEXT, e.g., streamline voluntary facility access / reduce unauthorized credential sharing for high-risk workstations]`
2. **Categories of Data Subjects:**
   - `[FILL-IN: SPECIFY POPULATIONS, e.g., voluntary employees, registered visitors, contractors]`
3. **Data Categories Processed:**
   - **Facial Video / Frames:** Transiently processed in client web browser memory via WASM; never saved to disk or transmitted across network.
   - **Biometric Vectors:** 128-dimensional mathematical feature vectors, encrypted at rest via Fernet symmetric encryption.
   - **Administrative Metadata:** `person_id`, display name, `consented_at` timestamp, `expires_at` timestamp, enrollment lifecycle state.
   - **Explicit Non-Collection:** No raw photographs, video recordings, government identifiers, or biometric raw imagery are stored on the server.
4. **Data Flows & Infrastructure Topology:**
   - Client browser webcam -> In-browser WebAssembly (YuNet + SFace) -> Vector extraction.
   - Client transmits vector over TLS (HTTPS) -> Reverse Proxy (Caddy/Nginx) -> Local loopback `127.0.0.1:6122` -> `FaceHTTPServer` -> SQLite `0600` encrypted store.
5. **Recipients & Third-Party Disclosures:**
   - Internal Access: `[FILL-IN: LIST AUTHORIZED OPERATOR ROLES]`
   - Third-Party Processors: `[FILL-IN: CONFIRM ON-PREMISES DEPLOYMENT OR NAME AUTHORIZED CLOUD HOSTING PROVIDER]`
   - Cross-Border Transfers: `[FILL-IN: SPECIFY JURISDICTIONAL DATA LOCALIZATION / CROSS-BORDER STATUS]`

#### Section 3: Assessment of Necessity, Proportionality & Subject Choices
1. **Proportionality Evaluation:**
   - Evaluate whether less intrusive non-biometric methods (e.g., standard RFID, passwords) were considered, and document why biometric verification is proportionate:
   - `[FILL-IN: PROPORTIONALITY JUSTIFICATION TO BE DOCUMENTED BY OPERATOR]`
2. **Lawful Basis:**
   - `[FILL-IN: LAWFUL BASIS DETERMINATION IN CONSULTATION WITH COUNSEL, e.g., GDPR Art. 6(1)(a) + Art. 9(2)(a) explicit consent / statutory authority]`
3. **Affirmative Consent Enforcement:**
   - Enforced technically at enrollment ([service.py](src/face_inference/service.py#L183-L187)); requests without `consent=True` fail with HTTP 400 `CONSENT_REQUIRED`.
4. **Non-Punitive Alternatives & Human-in-the-Loop Fallback:**
   - In accordance with [PRIVACY.md](PRIVACY.md#L75-L76), automated biometric matching must **not** serve as the sole automated arbiter for access or adverse decisions.
   - Accessible, non-punitive alternatives: `[FILL-IN: SPECIFY FALLBACK ALTERNATIVES, e.g., badge, physical escort, manual ID verification]`.
   - Human review process for contested or failed matches: `[FILL-IN: DESCRIBE HUMAN ESCALATION PATH]`.
5. **Data Subject Rights Workflow:**
   - Data Access & Export: Fulfilled via `export_records` API endpoint ([service.py](src/face_inference/service.py#L246-L258)).
   - Erasure: Fulfilled via deletion mutation API ([service.py](src/face_inference/service.py#L121-L126)).
   - Rectification / Recapture: Controlled via `RECAPTURE_REQUIRED` lifecycle state.

#### Section 4: Risk Identification, Mitigations & Residual Risk Ownership

| # | Risk Description & Impact | Technical & Operational Mitigations | Residual Risk Level | Designated Residual Risk Owner |
|---|---|---|---|---|
| **1** | **Demographic Disparities & Biased Verification**<br>Model error rates (false reject / false match) may vary across demographic groups (skin tone, gender, age, lighting) ([PRIVACY.md](PRIVACY.md#L73-L76)). | - Calibration and validation independent visit splits ([service.py](src/face_inference/service.py#L190-L194)).<br>- Mandatory human review fallback for failed matches ([PRIVACY.md](PRIVACY.md#L75-L76)).<br>- Non-punitive alternative verification pathways.<br>- Regular auditing of match thresholds. | `[FILL-IN: e.g., LOW / MEDIUM]` | `[FILL-IN: NAME & TITLE OF BUSINESS / PRODUCT OWNER]` |
| **2** | **Presentation Attacks / Spoofing (Lack of Liveness)**<br>System does not detect presentation attacks (`liveness_validated=False`, [service.py](src/face_inference/service.py#L34-L38), [PRIVACY.md](PRIVACY.md#L80-L84)). A photo or video could impersonate an enrolled subject. | - System deployed only in supervised or monitored environments.<br>- Prohibited for use as unattended single-factor access control to high-security areas.<br>- Explicit caveat disclosed in notice and operator guidelines. | `[FILL-IN: e.g., LOW / MEDIUM]` | `[FILL-IN: NAME & TITLE OF PHYSICAL SECURITY LEAD]` |
| **3** | **Biometric Data Breach / Exfiltration**<br>Interception of biometric vectors in transit or unauthorized database access. | - Raw photos never transmitted or saved ([schema.sql](src/face_inference/schema.sql#L4-L16)).<br>- Fernet encryption at rest for templates and captures ([storage.py](src/face_inference/storage.py#L54-L61)).<br>- Database file mode `0600` ([storage.py](src/face_inference/storage.py#L34)).<br>- Scoped session bearer tokens required for API access.<br>- Mandatory TLS reverse proxy in production ([docs/deployment.md](docs/deployment.md#L9-L22)). | `[FILL-IN: e.g., LOW]` | `[FILL-IN: NAME & TITLE OF CISO / SECURITY OFFICER]` |
| **4** | **Data Retention Creep / Zombie Records**<br>Biometric templates retained longer than necessary or after purpose fulfilled. | - Configurable `retention_days` and automated purge routine ([service.py](src/face_inference/service.py#L57-L65), [service.py](src/face_inference/service.py#L196-L201)).<br>- Immediate deletion API upon subject request ([service.py](src/face_inference/service.py#L121-L126)).<br>- Documented backup rotation schedule and limits. | `[FILL-IN: e.g., LOW]` | `[FILL-IN: NAME & TITLE OF DATA GOVERNANCE LEAD]` |
| **5** | **Statutory Non-Compliance & Legal Exposure**<br>Deployment violates regional statutes (GDPR Art. 9, BIPA, CUBI) due to missing notice, invalid consent, or lack of retention policy. | - Fill-in compliance starter templates provided.<br>- Mandatory review by local legal counsel prior to deployment.<br>- Strict prohibition against commercialization or transfer.<br>- Regular compliance auditing. | `[FILL-IN: e.g., LOW / MEDIUM]` | `[FILL-IN: NAME & TITLE OF LEGAL COUNSEL / DPO]` |

#### Section 5: Review Schedule, Sign-Off & Approvals
- **Scheduled Review Interval:** `[FILL-IN: e.g., Annually, or prior to any material system upgrade / algorithm change]`
- **Next Formal Review Date:** `[FILL-IN: DATE]`

#### Formal Sign-Off:

- **Lead Assessor:**
  - Name & Title: `[FILL-IN: NAME, TITLE]`
  - Signature: `[FILL-IN: SIGNATURE]`
  - Date: `[FILL-IN: DATE]`

- **Data Protection Officer / Legal Counsel:**
  - Name & Title: `[FILL-IN: NAME, TITLE]`
  - Recommendation: `[FILL-IN: APPROVED / APPROVED SUBJECT TO MITIGATIONS / NOT APPROVED]`
  - Signature: `[FILL-IN: SIGNATURE]`
  - Date: `[FILL-IN: DATE]`

- **Business / Operational System Owner:**
  - Name & Title: `[FILL-IN: NAME, TITLE]`
  - Residual Risk Acceptance: `[FILL-IN: ACCEPTED / REJECTED]`
  - Signature: `[FILL-IN: SIGNATURE]`
  - Date: `[FILL-IN: DATE]`
