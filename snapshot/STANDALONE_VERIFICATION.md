# Standalone adapter verification — 2026-10-21

The new application uses Python 3.12, SQLite, cryptography 41.0.7 and plain
JavaScript. It does not start PHP or connect to MySQL/CCC databases.

* 22 Python tests pass: actual disposable SQLite storage and HTTP API, authentication,
  same-origin and explicit HTTPS-origin checks, malformed/oversized/non-finite input,
  blocked private/static paths, encryption/wrong-key rejection, deletion and expiry,
  collection isolation, duplicate races, idempotency and changed-key conflicts,
  immutable references, unknown/ambiguous/conflicting/ineligible observations,
  evaluation/collection/approval states, report revocation, exact approved scope,
  independent visit splits and private evidence export structure.
* Aggregation parity passes against the unchanged original browser JavaScript,
  including actual exported synthetic vectors and an outlier fixture. Cosine scores
  use the original sequential accumulation and [-1,1] clamping convention.
* JavaScript client tests pass: native browser fetch binding, identical payload/key
  retry after response loss, blocking a new mutation while a save is unresolved,
  manual recovery and terminal server rejection.
* Actual-model browser integration passes using isolated Playwright Chromium and a
  simulated camera derived from the bundled synthetic ControlFace image. The test
  drives the real UI, original AutomaticCapture/worker/ORT/YuNet/SFace, new Python
  API and encrypted SQLite. It verifies enrollment, cross-person duplicate rejection,
  unverified recognition, deliberately lost successful response recovery and
  recognition after a fresh Python process and browser document. The resulting
  disposable DB has exactly two capture records (one valid, one rejected duplicate)
  and one reference; retry did not duplicate the enrollment.
* The browser test uses no fabricated identity response and runs no PHP. Structural
  unit vectors in logic tests remain distinct from the browser's actual embeddings.
* Python syntax and JavaScript parse checks pass. Original face assets remain
  byte-identical and their model hashes are checked at standalone startup.

The initial browser test exposed an unbound native `fetch` call in the new client.
That was corrected, covered by a regression and the full browser flow passed.
No failure was addressed by changing model weights, preprocessing, quality gates or
provisional similarity thresholds.

Logs: `standalone-python-tests.log`, `standalone-browser-tests.log`,
`standalone-client-tests.log`, `standalone-browser-result.json`.
The optional workspace screenshot uses the synthetic fixture only.

These checks establish functional integration and recovery with simulated input.
They do not establish physical-camera accuracy, representative live identity
performance or liveness. The installed default remains EVALUATION; no approval
policy, live identities, shared CCC credentials or synthetic fixture database is
installed. Original source service/rollout/database state remains unchanged.
