import pytest

from face_inference.pad import PADResult, validate_pad


class Provider:
    def __init__(self, result):
        self.result = result

    def validate(self, evidence):
        assert evidence == b'evidence'
        return self.result


def test_absent_provider_is_unvalidated_or_rejected_when_required():
    assert validate_pad(None, b'evidence') is PADResult.UNVALIDATED
    assert validate_pad(None, b'evidence', required=True) is PADResult.REJECTED


@pytest.mark.parametrize('required', [False, True])
def test_provider_acceptance_and_rejection(required):
    assert validate_pad(Provider(True), b'evidence', required=required) is PADResult.ACCEPTED
    assert validate_pad(Provider(False), b'evidence', required=required) is PADResult.REJECTED


@pytest.mark.parametrize('result', [None, 1, 'true', 'yes', {}, []])
@pytest.mark.parametrize('required', [False, True])
def test_malformed_provider_result_fails_closed(result, required):
    assert validate_pad(Provider(result), b'evidence', required=required) is PADResult.REJECTED


@pytest.mark.parametrize('required', [False, True])
def test_provider_exception_is_rejected(required):
    class BrokenProvider:
        def validate(self, evidence):
            raise RuntimeError('provider diagnostic')

    result = validate_pad(BrokenProvider(), b'evidence', required=required)
    assert result is PADResult.REJECTED
    assert 'provider diagnostic' not in str(result)


def test_provider_without_validate_is_rejected():
    assert validate_pad(object(), b'evidence') is PADResult.REJECTED
    non_callable = type('Provider', (), {'validate': None})()
    assert validate_pad(non_callable, b'evidence') is PADResult.REJECTED


@pytest.mark.parametrize('evidence', [b'', bytearray(), memoryview(b''), None, 'evidence'])
def test_invalid_evidence_is_rejected_without_calling_provider(evidence):
    class UncalledProvider:
        def validate(self, evidence):
            pytest.fail('provider must not receive invalid evidence')

    assert validate_pad(UncalledProvider(), evidence) is PADResult.REJECTED


@pytest.mark.parametrize('evidence', [bytearray(b'evidence'), memoryview(b'evidence')])
def test_bytes_like_evidence_is_accepted(evidence):
    assert validate_pad(Provider(True), evidence) is PADResult.ACCEPTED


def test_base_exception_propagates():
    class InterruptedProvider:
        def validate(self, evidence):
            raise KeyboardInterrupt

    with pytest.raises(KeyboardInterrupt):
        validate_pad(InterruptedProvider(), b'evidence')
