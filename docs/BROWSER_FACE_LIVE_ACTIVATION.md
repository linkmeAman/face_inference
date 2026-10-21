# Browser face attendance: how a batch moves from SHADOW to LIVE

Status on 2026-10-13: **LIVE is not enabled anywhere and cannot be.** `FACE_WEB_CALIBRATION_FILE` is
empty in `.env`, so `BrowserFacePolicy::load()` returns `ready=false, reason=CALIBRATION_REQUIRED`. The last
evaluation run (4 identities, 20 in-contract observations) returned `approved=False`. All 24 current batches
are in SHADOW (Treon 8, Charni Road 12) or TEST (Kurla 4). There are 0 ACTIVE enrollments and no ACTIVE
face templates.

This document describes the mechanisms that already exist. It adds no new path and no bypass.

## 1. The gates, in the order the code checks them

LIVE attendance needs **all** of the following at request time. Each gate is checked on the server for
every request; the browser cannot satisfy any of them.

| # | Gate | Where | What it requires |
|---|------|-------|------------------|
| 1 | Approved matching | `BrowserFacePolicy::load()` / `validate()` (`classes/services/BrowserFacePolicy.php` 81–128) | See section 2. If this fails, `ready=false` and nothing below can produce LIVE. |
| 2 | Exact LIVE scope | `BrowserFacePolicy::rollout()` line 49 | A scope in the rollout file for the exact tenant + branch + batch (never branch-wide), `mode: LIVE`, non-empty `approved_by`/`approved_at`, unexpired `expires_at`, and `matching_report_sha256` equal to the installed report's SHA-256. Otherwise the batch falls back to its SHADOW/TEST default (`requested_mode: LIVE` is reported). |
| 3 | No overriding stop | same function | Global `enabled:false`, a branch-wide DISABLED scope, or a batch DISABLED scope all win over LIVE. |
| 4 | Staff permission | `controllers/browserFace.php` 31–35 | Logged-in session, same-origin + CSRF header, and the existing Attendance module permission for the session's branch (`session->access(actor, Attendance, 1)`). There are no face-specific roles. |
| 5 | Enrollment activation | `BrowserFaceService::activationAllowed()` (line 37) and `activatePending()` (line 327) | PENDING enrollments become ACTIVE only when gates 1–3 hold. Activation re-checks consent, expiry, model/preprocessing version and tenant-wide duplicates using the approved `DUPLICATE_THRESHOLD`. Conflicts stay inactive. It is blocked entirely while any ACTIVE template from another model exists (none exist today). |
| 6 | Recognition decision | `BrowserFaceService` lines 514 and 623 | At least `minimum_observations` (3) observations are matched against ACTIVE templates of the eligible roster using the approved `ATTENDANCE_THRESHOLD`. Attendance is written through the unchanged `AttendanceDomainService` (idempotency and deduction are unchanged). |
| 7 | Absence finalization (optional, separate) | `rollout()` line 52, `scripts/browser_face_admin.php finalize-due` | Only if the LIVE scope also has `finalization: {cutoff: SCHEDULED_SESSION_END, approved_by, approved_at}` and the scheduler is installed. Not installed today. |

## 2. What "approved matching" means in code (gate 1)

`FACE_WEB_CALIBRATION_FILE` must point to a JSON evidence file that references an evaluation report.
`load()` accepts it only if **all** of these hold:

* the report file exists and its SHA-256 equals `report_sha256` in the evidence;
* the report has **no blockers**, `source: consented-live-cameras`, and a complete decision evaluation
  (`browser-face-decisions-v1`, `complete:true`, at least one full validation batch, and known **and**
  unknown validation counts above zero);
* `model_version`, `preprocessing_version`, `identities` and `samples` match between evidence and report;
* `DUPLICATE_THRESHOLD` and `ATTENDANCE_THRESHOLD` in the evidence equal the report's recommendations
  exactly, so a person cannot type in a different threshold;
* the evidence has `approved:true`, non-empty `approved_by` and `approved_at`, `independent_validation:true`,
  `independent_workflow_validation:true`, current contract versions, and **`identities >= 20` and
  `samples >= 100`**.

`evaluation/calibrate_thresholds.py` **never writes `approved:true`** (line 172). The report is the output
of the evaluation; approval is a separate, deliberate step in which a person writes the evidence file.

## 3. Step-by-step: SHADOW → LIVE for one batch

1. **Collect evidence** with the normal Enroll Face / Update Face buttons in SHADOW. This needs consenting
   people, two independent visits each for calibration and two different later visits for validation, at
   least two devices and two lighting conditions. It also needs people who are **not** enrolled (unknown)
   tested against a real, non-empty eligible roster. Identity labels must be confirmed by staff;
   predictions are not labels.
2. **Export** (root, CLI only; the output must be outside the web root and is created 0600):
   `php scripts/browser_face_admin.php export-calibration --database ccc_database --branch B --output /private/path.jsonl`
3. **Evaluate** with the two risk budgets chosen by the approver (there are no defaults):
   `python3 evaluation/calibrate_thresholds.py /private/path.jsonl --output /private/report.json --duplicate-max-far X --attendance-max-far Y`
   Then delete the plaintext export.
4. **Review and approve.** Continue only if the report lists no blockers. The reviewer must also check the
   uncertainty intervals (section 5) against the budgets.
5. **Install the evidence file** (root-owned, readable by www-data, outside the web root) containing the
   fields from section 2 and the reviewer's name/time. Set `FACE_WEB_CALIBRATION_FILE` in `.env`. The
   settings page then shows matching as approved.
6. **Authorize LIVE for exactly one batch.**
   * **First batch:** write the LIVE scope into the rollout file directly, as `docs/BROWSER_FACE_SETUP.md`
     ("First approved batch activation", step 3) describes. Include `matching_report_sha256`,
     `approved_by`, `approved_at` and a short `expires_at`; the page itself uses 90 days. The Settings
     page **cannot** do this for the first batch. It refuses LIVE for a batch with no ACTIVE enrollment
     (`NO_ACTIVE_ENROLLMENTS`), and PENDING enrollments only become ACTIVE once the batch is already
     LIVE (gate 5).
   * **Later batches:** once a batch has ACTIVE enrollments, an administrator (position 1) can choose
     Live on the Settings page. The page re-checks the policy after saving and reverts the change if the
     backend does not confirm LIVE.
7. **Activate pending enrollments** for that batch:
   `php scripts/browser_face_admin.php activate-pending --database ccc_database --branch B --batch N --actor <existing Attendance operator id>`
   This is safe to retry and refuses unless the batch is LIVE. It also runs automatically when a LIVE
   batch's roster is opened.
8. **Verify on the backend.** The config endpoint for that batch must report `operating_mode: LIVE` and
   `acceptance_ready: true`. If it does not, LIVE is **not** enabled, whatever the page shows.
9. **Absence finalization** stays off unless it is approved separately in the scope (gate 7) and the
   scheduler is installed.

**Rollback** at any time: on the Settings page use Stop selected batches, Disable selected branches or
Stop all face attendance. From the CLI use `disable-field`, or remove the evidence file (`ready` becomes
false and every LIVE scope falls back to SHADOW). Committed attendance is not undone.

## 4. Approval authority today

* The code records **who** approved (`approved_by` in the evidence file, the LIVE scope and the
  finalization block) but does **not** define who is allowed to approve. `approved_by` is free text.
* In practice two kinds of access decide it:
  * **Server root.** Only root can install the evidence file and set `FACE_WEB_CALIBRATION_FILE`, so
    whoever controls the server decides whether matching is approved.
  * **Administrators (position 1).** On the Settings page they can choose which batches go LIVE, but only
    after root has installed an approved report.
* No named business owner (for example, management or the safeguarding lead for children's biometric
  data) is recorded in code or documentation. **This needs to be decided and written down before the
  first approval.** The approver also has to choose the two false-acceptance budgets, because the
  evaluator has no defaults.

## 5. Minimum sample counts are not evidence of accuracy

| | Hard-coded minimum (coverage floor) | Evidence of actual recognition accuracy |
|---|---|---|
| What it is | `identities >= 20`, `samples >= 100` (`BrowserFacePolicy::validate()`, `calibrate_thresholds.py` line 96); 2 devices; 2 lighting conditions; 2 visits per identity per split; `minimum_observations` = 3 per decision | Error rates measured on **held-out** validation visits at the chosen thresholds: unknown-person false acceptance, wrong-person identification, genuine-person failures, false duplicate rejection, missed duplicates |
| What it proves | Only that enough varied data exists to start measuring. The tool says so: *"this does not prove accuracy"* | How often the system would mark the wrong child or an unknown person, within a confidence interval |
| Where it is reported | `blockers` | `decision_evaluation.validation` (each metric has `errors`, `trials`, `rate` and `wilson_95`) |

The automatic check compares only the observed **rate** with the budget (`decision_evaluation.py`
161–179), not the upper confidence bound. Zero errors in a small number of trials therefore passes any
budget, even though it shows very little. The 95% upper bound with zero observed errors is about
`3.84 / (n + 3.84)`:

| Error-free unknown-person trials (n) | 95% upper bound on false acceptance |
|---:|---:|
| 10 | about 28% |
| 50 | about 7% |
| 100 | about 3.7% |
| 380 | about 1% |

The reviewer should approve only if the **upper bound** (`wilson_95[1]`) for unknown false acceptance and
wrong-person identification is within the chosen budget. The evaluator does not enforce this today;
whether it should is a decision for the approver and is not changed here. The report also states that
pairs are correlated, that similar-looking people need explicit review, and that there is **no
presentation-attack (photo/screen) defence**.

## 6. Current evidence against these criteria

| Requirement | Current |
|---|---|
| Identities (consented, confirmed distinct) | 4 in contract (616, 617, 619, 623); need >= 20 |
| In-contract observations | 20; need >= 100 |
| Two calibration and two validation visits per identity | Not met (617 and 619 have one observation each) |
| Unknown/unenrolled people tested against a real roster | None; need complete known and unknown batches |
| False-acceptance budgets | Not chosen |
| Evidence file installed | No (`FACE_WEB_CALIBRATION_FILE` empty) |
| ACTIVE enrollments | 0 (4 PENDING, 1 duplicate conflict) |
| Absence-finalization approval and scheduler | Not given / not installed |

Next actions: name the approver and the budgets, choose the pilot batch, then collect about 16 or more
additional consenting people with repeat visits, plus unknown-person trials. Re-run steps 2–4. Proceed to
step 5 only if the report has no blockers and the upper bounds are within budget.
