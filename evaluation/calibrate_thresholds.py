"""Evaluate consented, labeled browser embeddings. Never auto-approve a policy.

JSONL: identity, embedding, visit, device, lighting, consent=true,
source='consented-live-cameras', split='calibration' or 'validation',
model_version, preprocessing_version. Do not commit the input dataset.
"""
import argparse
import hashlib
import itertools
import json
import math
import statistics
from pathlib import Path
from decision_evaluation import aggregate, cosine, decision_evaluation, provisional_pilot

CONTRACT = Path(__file__).resolve().parents[1] / 'pf/js/browser-face/contract.json'


def load(path):
    contract = json.loads(CONTRACT.read_text())
    rows = []
    for line_number, line in enumerate(Path(path).read_text().splitlines(), 1):
        if not line.strip():
            continue
        row = json.loads(line)
        vector = row.get('embedding')
        if (not isinstance(vector, list) or len(vector) != contract['dimension']
                or not all(type(v) in (int, float) and math.isfinite(v) for v in vector)
                or abs(math.sqrt(sum(v*v for v in vector))-1) > .001):
            raise ValueError(f'Invalid unit embedding at line {line_number}')
        if not row.get('identity') or row.get('consent') is not True:
            raise ValueError(f'Identity and informed consent required at line {line_number}')
        for name in ('model_version', 'preprocessing_version'):
            if row.get(name) != contract[name]:
                raise ValueError(f'Incompatible {name} at line {line_number}')
        if 'observations' in row:
            batch = row['observations']
            if (not isinstance(batch, list) or not 3 <= len(batch) <= 5
                    or any(not isinstance(v, list) or len(v) != contract['dimension']
                           or not all(type(x) in (int, float) and math.isfinite(x) for x in v)
                           or abs(math.sqrt(sum(x*x for x in v))-1) > .001 for v in batch)):
                raise ValueError(f'Invalid observation batch at line {line_number}')
            if cosine(aggregate(batch), vector) < 1-1e-6:
                raise ValueError(f'Aggregate mismatch at line {line_number}')
            if not row.get('capture_session') or row.get('purpose') not in ('ENROLL', 'VALIDATION'):
                raise ValueError(f'Capture identity/session metadata required at line {line_number}')
        if row.get('split') not in ('calibration', 'validation'):
            raise ValueError(f'Explicit dataset split required at line {line_number}')
        rows.append(row)
    sessions = [r['capture_session'] for r in rows if r.get('capture_session')]
    if len(sessions) != len(set(sessions)):
        raise ValueError('Repeated capture sessions are not independent observations')
    return rows, contract


def pairs(rows, predicate=lambda a, b: True):
    genuine, impostor = [], []
    for a, b in itertools.combinations(rows, 2):
        if not predicate(a, b):
            continue
        if a['identity'] == b['identity']:
            # Consecutive observations from one visit are not independent trials.
            if not a.get('visit') or a.get('visit') == b.get('visit'):
                continue
            destination = genuine
        else:
            destination = impostor
        destination.append(cosine(a['embedding'], b['embedding']))
    return genuine, impostor


def distribution(scores):
    if not scores:
        return {'count': 0}
    ordered = sorted(scores)
    return {'count': len(scores), 'min': ordered[0], 'max': ordered[-1],
            'mean': statistics.mean(scores), 'median': statistics.median(scores),
            'p05': ordered[int((len(scores)-1)*.05)], 'p95': ordered[int((len(scores)-1)*.95)]}


def rates(genuine, impostor, threshold):
    fa = sum(score >= threshold for score in impostor)
    fr = sum(score < threshold for score in genuine)
    return {'threshold': threshold, 'false_accepts': fa, 'false_rejects': fr,
            'false_acceptance_rate': fa/len(impostor) if impostor else None,
            'false_rejection_rate': fr/len(genuine) if genuine else None}


def evaluate(rows, contract, duplicate_far=None, attendance_far=None):
    identities = {row['identity'] for row in rows}
    calibration = [row for row in rows if row.get('split') == 'calibration']
    validation = [row for row in rows if row.get('split') == 'validation']
    genuine, impostor = pairs(calibration)
    vg, vi = pairs(validation)
    reasons = []
    if len(identities) < 20 or len(rows) < 100:
        reasons.append('Starting coverage requires at least 20 identities and 100 observations; this does not prove accuracy')
    if not genuine or not impostor or not vg or not vi:
        reasons.append('Both same-person and different-person pairs required in separate calibration and validation sets')
    for label in ('visit', 'device', 'lighting'):
        if any(not row.get(label) for row in rows):
            reasons.append(f'Missing {label} labels')
    if any(row.get('source') != 'consented-live-cameras' for row in rows):
        reasons.append('Representative consenting live camera inputs required')
    if len({row.get('device') for row in rows}) < 2:
        reasons.append('Cross-device observations unavailable')
    if len({row.get('lighting') for row in rows}) < 2:
        reasons.append('Different-lighting observations unavailable')
    visits = {identity: {r.get('visit') for r in rows if r['identity'] == identity} for identity in identities}
    if any(len(values) < 2 for values in visits.values()):
        reasons.append('Independent repeat visits required for each identity')
    for split, subset in [('calibration', calibration), ('validation', validation)]:
        if {r['identity'] for r in subset} != identities:
            reasons.append(f'Every identity requires {split} observations')
        if any(len({r.get('visit') for r in subset if r['identity'] == identity}) < 2 for identity in identities):
            reasons.append(f'Each identity requires two independent visits in {split}')
    # A visit cannot be used to choose and independently validate thresholds.
    cv = {(row['identity'], row.get('visit')) for row in calibration}
    vv = {(row['identity'], row.get('visit')) for row in validation}
    if cv & vv:
        reasons.append('Calibration and validation reuse the same identity/visit; independent validation is required')
    all_scores = sorted(set(genuine+impostor))
    # Candidate points come from observations, not remembered model thresholds.
    candidates = sorted(set(all_scores + [math.nextafter(v, 1.0) for v in all_scores if v < 1]))
    curve = [rates(genuine, impostor, threshold) for threshold in candidates]
    recommendations = {}
    for name, target in [('DUPLICATE_THRESHOLD', duplicate_far), ('ATTENDANCE_THRESHOLD', attendance_far)]:
        if target is None:
            recommendations[name] = None
            reasons.append(f'Operator must specify acceptable false acceptance rate for {name}')
            continue
        if not 0 <= target <= 1:
            raise ValueError('False acceptance target must be between zero and one')
        passing = [point for point in curve if point['false_acceptance_rate'] is not None
                   and point['false_rejection_rate'] is not None and point['false_acceptance_rate'] <= target]
        chosen = min(passing, key=lambda point: (point['false_rejection_rate'], point['threshold'])) if passing else None
        tested = rates(vg, vi, chosen['threshold']) if chosen else None
        if chosen and tested['false_acceptance_rate'] is not None and tested['false_acceptance_rate'] <= target and not reasons:
            recommendations[name] = {'threshold': chosen['threshold'], 'calibration': chosen, 'validation': tested}
        else:
            recommendations[name] = None
            if not reasons:
                reasons.append(f'No operating point meets independent validation requirements for {name}')
    decisions = decision_evaluation(rows, duplicate_far, attendance_far)
    reasons.extend(decisions['blockers'])
    recommendations = {name: decisions['recommendations'].get(name) for name in
                       ('DUPLICATE_THRESHOLD', 'ATTENDANCE_THRESHOLD')}
    # Pair coverage is preliminary; release recommendations use complete decisions.
    if reasons:
        recommendations = {name: None for name in recommendations}
    groups = {}
    for name, predicate in [
        ('cross_device', lambda a, b: a.get('device') != b.get('device')),
        ('cross_lighting', lambda a, b: a.get('lighting') != b.get('lighting')),
        ('similar_looking', lambda a, b: bool(a.get('similar_looking_group')) and a.get('similar_looking_group') == b.get('similar_looking_group')),
    ]:
        gs, im = pairs(rows, predicate)
        groups[name] = {'same_person': distribution(gs), 'different_person': distribution(im)}
    for dimension in ('device', 'lighting'):
        groups[dimension] = {}
        for label in sorted({str(r.get(dimension, 'unlabeled')) for r in rows}):
            subset = [r for r in rows if str(r.get(dimension, 'unlabeled')) == label]
            gs, im = pairs(subset)
            groups[dimension][label] = {'samples': len(subset), 'same_person': distribution(gs), 'different_person': distribution(im)}
    return {'model_version': contract['model_version'], 'preprocessing_version': contract['preprocessing_version'],
            'source': 'consented-live-cameras' if rows and all(r.get('source') == 'consented-live-cameras' for r in rows) else 'insufficient-live-evidence',
            'identity_ids': sorted(map(str, identities)), 'dataset_databases': sorted({r.get('origin_database', 'unrecorded') for r in rows}), 'dataset_branches': sorted({r.get('branch_id', 0) for r in rows}), 'identities': len(identities), 'samples': len(rows), 'calibration_samples': len(calibration),
            'validation_samples': len(validation), 'raw_observation_samples':sum(len(r.get('observations',[])) for r in rows), 'same_person': distribution(genuine), 'different_person': distribution(impostor),
            'validation_same_person': distribution(vg), 'validation_different_person': distribution(vi),
            'conditions': groups, 'candidate_thresholds': curve, 'recommendations': recommendations,
            'decision_evaluation': decisions, 'provisional_pilot': provisional_pilot(rows),
            'blockers': reasons, 'approved': False,
            'limitations': ['Pairs are correlated; zero observed false accepts is not proof of zero risk',
                           'Review similar-looking people and held-out identities explicitly',
                           'Pair scores do not alone validate multi-observation attendance decisions',
                           'No validated presentation-attack defense']}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('dataset', type=Path)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--duplicate-max-far', type=float)
    parser.add_argument('--attendance-max-far', type=float)
    args = parser.parse_args()
    rows, contract = load(args.dataset)
    report = evaluate(rows, contract, args.duplicate_max_far, args.attendance_max_far)
    report['dataset_sha256'] = hashlib.sha256(args.dataset.read_bytes()).hexdigest()
    args.output.parent.mkdir(parents=True, exist_ok=True)
    args.output.write_text(json.dumps(report, indent=2)+'\n')
    lines = ['# Face threshold evaluation', '', f"Identities: {report['identities']}; observations: {report['samples']}",
             f"Calibration pairs: {report['same_person']['count']} same-person, {report['different_person']['count']} different-person",
             f"Validation pairs: {report['validation_same_person']['count']} same-person, {report['validation_different_person']['count']} different-person", '',
             '## Readiness', '', 'Not automatically approved. '+('; '.join(report['blockers']) or 'Review recommendations and independent workflow validation.'), '',
             '## Candidate error rates', '', '| Cosine threshold | False accepts | False rejects | FAR | FRR |', '|---|---:|---:|---:|---:|']
    # Keep readable report bounded; complete curve remains in the JSON report.
    curve = report['candidate_thresholds']
    step = max(1, len(curve)//100)
    for point in curve[::step]:
        lines.append(f"| {point['threshold']:.7f} | {point['false_accepts']} | {point['false_rejects']} | {point['false_acceptance_rate']} | {point['false_rejection_rate']} |")
    lines += ['', 'Recommendations: '+json.dumps(report['recommendations']), '', *report['limitations']]
    decisions = report['decision_evaluation']
    lines += ['', '## Complete recognition decisions', '',
              f"Enrollment gallery identities: {decisions['gallery_identities']}; full calibration batches: {decisions['full_calibration_batches']}; full independent validation batches: {decisions['full_validation_batches']}",
              'Independent validation metrics (Wilson 95% intervals; correlated captures limit interpretation):',
              '```json', json.dumps(decisions['validation'], indent=2), '```',
              'Decision blockers: '+('; '.join(decisions['blockers']) or 'None'), '',
              'Isolated exploratory pilot only: '+json.dumps(report['provisional_pilot']), '', *decisions['limitations']]
    args.output.with_suffix('.md').write_text('\n'.join(lines)+'\n')
    print(f"Evaluation written: {args.output}; identity acceptance remains disabled until approved evidence is installed")


if __name__ == '__main__':
    main()
