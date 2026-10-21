# Immutable Goal

Build a model-independent, server-authoritative face-attendance platform for
web and mobile clients. The current milestone is a supervised production pilot: an accepted roster-scoped match selects the child's row as Present; uncertain or rejected faces remain Absent and scanning automatically continues. The separate existing Punch Attendance action commits the completed roster.

The existing manual attendance flow remains authoritative and must always work.

# Core Motive: Non-Technical User Experience (UI/UX)

The system is built for non-technical users (teachers, coaches, studio staff).
Making sure the actual user understands and effortlessly navigates the system is a
primary goal of this project, equal in priority to accuracy and safety:

- **Intuitive Self-Guiding Flows**: Every screen, state, and step must make it
  obvious what the user needs to do next without requiring technical knowledge.
- **Plain-Language Explanations**: Never display raw error codes, machine learning
  jargon, or internal system parameters (e.g., `FACE_ALREADY_ASSIGNED`, 8 frames,
  centroids, threshold scores).
- **Clear Mistake & Correction Guidance**: When a user takes a wrong step or an
  issue occurs, the UI must clearly explain in plain human language:
  1. Exactly what happened (e.g., "This face is already registered to FaceTest01 Child").
  2. Why it happened (e.g., the wrong child is in front of the camera).
  3. The exact, simple action to fix it (e.g., "Bring FaceTest02 Child to the camera").
- **Proactive Early Guidance**: Prevent wrong steps *before* they happen (e.g.,
  detecting duplicate faces during live preview and disabling capture before clicking)
  so users never waste time on actions that will fail.
- **User-Focused Terminology**: All buttons, labels, and prompts must reflect the
  user's mental model (e.g., "Capture" instead of "Capture 8 Mapping Frames").

# Existing Flow

- pf/js/controllers/batchAttendanceController.js
- pf/attendance.php
- controllers/member.php
- classes/routes/attendanceRoute.php
- controllers/attendance.php
# Enrollment Goal

A teacher performs one guided capture for a selected member. The application
automatically uses the active, approved inference calibration for that capture
and saves the member template when all identity, quality, liveness, and
duplicate checks pass. No version-selection, calibration catch-up task, or
manual model approval is shown to the teacher.

The inference service is the runtime source for provider, model, and
calibration-policy metadata. PHP validates that exact runtime tuple against the
active registry row before saving a template, so incompatible versions still
fail closed. Calibration operating points remain independently evaluated on
held-out evidence; they are never recalculated from the member currently being
enrolled.


# Non-Negotiable Rules

- Face recognition only searches the selected session roster.
- Face recognition cannot write membership balances directly.
- Attendance writes go through the shared attendance domain service.
- Duplicate attendance must be prevented by the database.
- sessions = 0 means unlimited.
- session_id must participate in duplicate detection.
- Server-side date restrictions cannot be bypassed.
- Uncertain matches go to manual confirmation.
- Manual attendance remains available.
- No raw camera frames in logs or permanent storage.
- Recognition cannot commit attendance or deduct membership during the
  supervised pilot; the existing teacher commit remains the transaction
  boundary.
- Recognition providers and calibration policies are versioned independently,
  and templates from incompatible model versions are never compared.
- PAD is deferred, not passed. Every client response must report that status
  without presenting it as validated.

# Explicit Non-Goals

- No group recognition initially.
- No organization-wide face search.
- No offline biometric matching.
- No automatic absence without teacher confirmation.
- No global PHP-FPM configuration changes.
- No immediate face-triggered database punch-in until a separate live-write
  decision and production/PAD approval.
- Punch-out is a separate later release.

Agents must read this file before every task.
