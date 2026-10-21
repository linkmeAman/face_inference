# Face Attendance Task Contract

Before starting any face-attendance task, the agent must:

1. Read `docs/ai/face-attendance-goal.md`.
2. Read the relevant entries in `docs/ai/decision-log.md`.
3. Read `docs/ai/repo-facts.md`, `docs/ai/progress.md`, the applicable section of
   `docs/ai/face-attendance-implementation-plan.md`, and
   `docs/ai/face-attendance-production-mobile-roadmap.md` for any deployment,
   calibration, liveness, live-write, punch-out, or mobile task.
4. Confirm that the requested work is documentation-only or explicitly
   authorized implementation work.
5. Create a focused test case for the requested behavior before changing
   production code. Run it against the pre-change implementation and preserve
   the result as the baseline for post-change verification.

Before completion, the agent must:

1. Re-check the immutable goal against the changes.
2. Update the decision log if a decision, scope, or rule changed.
3. Update repository facts when verified implementation or runtime evidence
   changed.
4. Update progress with what was completed, what was not started, and any
   blockers.
5. State clearly whether implementation was performed.
6. Run the pre-change test case again after implementation and report the
   exact pass/fail output.

Documentation-only synchronization does not require inventing a production
test. It must instead verify internal consistency, re-read the immutable goal,
and run formatting/diff checks appropriate to the edited documents.

The existing manual attendance flow must remain authoritative and available.
