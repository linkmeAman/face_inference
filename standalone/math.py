"""Ports of BrowserFaceMath and BrowserFaceService's pure matching operations."""
import math
from statistics import median


class FaceError(Exception):
    def __init__(self, code, message, status=400):
        super().__init__(message)
        self.code, self.status = code, status


def vector(values):
    if not isinstance(values, list) or len(values) != 128 or any(
        isinstance(v, bool) or not isinstance(v, (int, float)) or not math.isfinite(v) for v in values
    ):
        raise FaceError('INVALID_VECTOR', 'Expected a finite 128-dimensional face vector')
    norm = math.sqrt(sum_sequential(v * v for v in values))
    if abs(norm - 1) > 0.001:
        raise FaceError('INVALID_VECTOR', 'Face vectors must have unit norm')
    return [float(v) for v in values]


def sum_sequential(values):
    # Python 3.12 sum() has different rounding at exact PHP/JavaScript boundaries.
    result = 0.0
    for value in values:
        result += value
    return result


def cosine(a, b):
    if len(a) != len(b):
        raise FaceError('INVALID_VECTOR', 'Embedding dimension mismatch')
    return max(-1.0, min(1.0, sum_sequential(x * y for x, y in zip(a, b))))


def observations(values):
    if not isinstance(values, list) or not 3 <= len(values) <= 5:
        raise FaceError('INVALID_OBSERVATIONS', 'Three to five observations required')
    return [vector(v) for v in values]


def normalize(values):
    norm = math.sqrt(sum_sequential(v * v for v in values))
    if norm < 1e-8:
        raise FaceError('UNSTABLE_CAPTURE', 'Please capture this face again')
    return [v / norm for v in values]


def aggregate(values):
    vectors = [normalize(v) for v in observations(values)]
    distances = [median(1 - cosine(a, b) for b in vectors) for a in vectors]
    medoid = vectors[distances.index(min(distances))]
    deviation = [max(0, 1 - cosine(v, medoid)) for v in vectors]
    centre = median(deviation)
    mad = median(abs(v - centre) for v in deviation)
    retained = [v for i, v in enumerate(vectors) if deviation[i] <= centre + 3 * max(mad, 1e-6)]
    if len(retained) < 3:
        raise FaceError('UNSTABLE_CAPTURE', 'Please capture this face again')
    return normalize([sum_sequential(v[i] / len(retained) for v in retained) for i in range(128)])


def identify(values, gallery, threshold):
    winner = None
    for value in observations(values):
        scores = {}
        for row in gallery:
            person = row['person_id']
            scores[person] = max(scores.get(person, -1), cosine(value, row['vector']))
        accepted = [person for person, score in scores.items() if score >= threshold]
        if len(accepted) != 1 or (winner is not None and winner != accepted[0]):
            reason = ('NO_ELIGIBLE_REFERENCE' if not scores else 'BELOW_CUTOFF' if not accepted
                      else 'AMBIGUOUS' if len(accepted) > 1 else 'CONFLICTING_OBSERVATIONS')
            return None, reason
        winner = accepted[0]
    return winner, 'MATCHED'
