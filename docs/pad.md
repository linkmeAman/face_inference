# Presentation attack detection contract

PAD is only an optional extension point. This project ships no liveness model and makes no anti-spoofing claim; its current policy reports `liveness_validated=False` ([PRIVACY.md:80–84](../PRIVACY.md#L80-L84)).

`validate_pad(provider, evidence, *, required=False)` returns:

- `UNVALIDATED` when no provider is configured and PAD is not required.
- `ACCEPTED` only when a configured provider returns the literal boolean `True` for nonempty bytes, bytearray, or memoryview evidence.
- `REJECTED` when a provider is required but absent, or when a configured provider receives invalid or empty evidence, returns anything other than `True`, has no callable `validate`, or raises an `Exception`. Invalid evidence is not passed to the provider. Provider exception text and evidence are not returned or logged by this contract. `BaseException` subclasses such as `KeyboardInterrupt` propagate.

Evidence produced by an attacker-controlled client can be forged. A provider that trusts only client-supplied signals is not proof of liveness. Real PAD needs trusted capture or server-side analysis of raw media, which this server deliberately never receives ([architecture.md:3–10](architecture.md#L3-L10)).

The following 15-line provider skeleton is an illustration, not a recommendation of any vendor. It does not implement PAD:

```python
class ExampleProvider:
    def __init__(self, analyzer):
        self.analyzer = analyzer

    def validate(self, evidence: bytes) -> bool:
        # The analyzer must establish evidence provenance.
        # It must apply its own timeout.
        # It must not log biometric evidence.
        # Configure it for the deployment's threat model.
        # No analyzer or model is supplied here.
        # A real analyzer must return a bool.
        # It must fail closed on uncertain results.
        # Review its security and privacy properties.
        # Client assertions alone are insufficient.
        # This skeleton makes no accuracy claim.
        return self.analyzer.validate(evidence)
```

Providers must enforce their own timeouts: a hanging provider hangs the request. Recognition integration is a later task (S20) and is not implemented here; it must enforce rejection before the protected action and preserve the unvalidated state when no provider is configured.
