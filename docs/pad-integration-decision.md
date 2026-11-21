# PAD recognition integration decision (S20)

Status: deferred. This records why presentation-attack detection (PAD) is not
wired into recognition, and what a future, separately reviewed design must
specify before that changes. It makes no spoof-resistance or accuracy claim.

## Current, verified state

- `src/face_inference/pad.py:19-30` defines the optional provider contract:
  `validate_pad(provider, evidence, *, required=False)` accepts provider evidence
  bytes, returns `UNVALIDATED` when no provider is configured and PAD is not
  required, and fails closed (`REJECTED`) when a provider is required but absent,
  evidence is invalid, or the provider rejects or raises. Nothing in the
  recognition path calls it.
- `src/face_inference/service.py:98` lists the recognized request fields
  (`capture_session`, `observations`, `model_version`, `preprocessing_version`,
  `eligible_ids`). There is no evidence field, and unknown fields are rejected
  (`src/face_inference/service.py:101`), so adding one is a contract change.
- `src/face_inference/service.py:34-38` is `safe_config`, which reports
  `liveness_validated=False`. The runtime still reports liveness as unvalidated.
- `docs/architecture.md:3-10` states the browser sends only embeddings, not media;
  there is no upload route for a PAD analyzer to consume raw frames.
- `docs/pad.md:11` states the provenance problem: evidence produced by an
  attacker-controlled client can be forged, and a provider that trusts only
  client-supplied signals is not proof of liveness. `docs/pad.md:34` already
  records that recognition integration is a later task (S20).
- The project decision on liveness requires PAD to be optional and
  fail-closed when an operator enables a provider, with no provider leaving
  results marked unvalidated, and no PAD model implemented.

## Why integration is deferred

The shipped recognition flow has no defined trusted evidence source, no provider
provisioning path and no attestation contract. An optional client `pad_evidence`
string would be attacker-controlled and cannot honestly establish liveness:
client assertions cannot prove liveness against a malicious client. Wiring it in
would let a caller assert a positive PAD result that the server cannot verify.

Therefore this task adds no `pad_evidence` field, no `pad_required` flag, no
provider, model, config, service or API change, and does not change any existing
assertion. S05's contract (`tests/test_pad_contract.py:15-69`) and `docs/pad.md`
remain as they are: the extension point exists but is not invoked at runtime.

## What a future reviewed design must specify

1. **Trusted capture or independently verified evidence and provenance.** The
   evidence must originate from a trusted capture path or be independently
   verified, not asserted by the untrusted browser; client-supplied signals
   alone are insufficient (`docs/pad.md:11`).
2. **Provider lifecycle and bounded failure/timeouts.** Configure, initialize,
   and tear down the provider explicitly; bound every call with a timeout and
   fail closed on hang, error or uncertain result, since a hanging provider
   hangs the request (`docs/pad.md:34`).
3. **Data/privacy handling.** Define what evidence is retained, encrypted and
   logged; do not log or expose biometric evidence or provider exception text
   (`src/face_inference/pad.py:19-30`).
4. **Fail-closed behavior when enabled.** A required provider that is absent,
   rejects, returns a malformed result or raises must block the protected
   action before it is taken (`src/face_inference/pad.py:19-30`,
   the project decision).
5. **Explicit unvalidated behavior otherwise.** With no provider configured,
   results stay marked unvalidated and no liveness claim is made
   (`src/face_inference/service.py:34-38`).

## Scope of this decision

This decision defers integration only. It does not approve a particular evidence
format or provider, and it does not authorize any runtime, config or API change.
