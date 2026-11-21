import pytest

from face_inference.matching import FaceError, cosine, identify, normalize


def test_matching_boundaries():
    vector = [1.0] + [0.0] * 127
    gallery = [{'person_id': 'p', 'vector': vector}]
    assert identify([vector] * 3, gallery, 1.0) == ('p', 'MATCHED')
    assert identify([vector] * 3, gallery, 1.0001) == (None, 'BELOW_CUTOFF')
    with pytest.raises(FaceError, match='dimension mismatch'):
        cosine([1], [1, 2])
    with pytest.raises(FaceError, match='capture'):
        normalize([0, 0])
