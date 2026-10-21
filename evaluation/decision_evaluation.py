"""Evaluate actual aggregate enrollment and multi-observation attendance decisions.

No predicted identity supplies a ground-truth label. Legacy aggregate-only rows
can inform an explicitly provisional isolated pilot, never release readiness.
"""
import math
import statistics

VERSION = 'browser-face-decisions-v1'


def cosine(a, b):
    # PHP and JavaScript accumulate left to right. Python 3.12 sum() uses
    # improved summation and can flip >= decisions at an exact score boundary.
    score = 0.
    for x, y in zip(a, b):
        score += x*y
    return max(-1., min(1., score))


def aggregate(samples):
    vectors = [[x/math.sqrt(sum(v*v for v in sample)) for x in sample] for sample in samples]
    distances = [statistics.median(1-cosine(a, b) for b in vectors) for a in vectors]
    medoid = vectors[distances.index(min(distances))]
    deviations = [max(0., 1-cosine(a, medoid)) for a in vectors]
    center = statistics.median(deviations)
    mad = statistics.median(abs(v-center) for v in deviations)
    retained = [v for v, d in zip(vectors, deviations) if d <= center+3*max(mad, 1e-6)]
    if len(retained) < 3:
        raise ValueError('Unstable observation batch')
    mean = [sum(v[i] for v in retained)/len(retained) for i in range(len(retained[0]))]
    norm = math.sqrt(sum(v*v for v in mean))
    if norm < 1e-8:
        raise ValueError('Empty aggregate')
    return [x/norm for x in mean]


def uncertainty(errors, n):
    if not n:
        return {'errors': errors, 'trials': n, 'rate': None, 'wilson_95': None}
    p, z = errors/n, 1.959963984540054
    center = (p+z*z/(2*n))/(1+z*z/n)
    half = z*math.sqrt(p*(1-p)/n+z*z/(4*n*n))/(1+z*z/n)
    return {'errors': errors, 'trials': n, 'rate': p, 'wilson_95': [max(0., center-half), min(1., center+half)]}


def identify(scores, threshold):
    winner = None
    for observation in scores:
        accepted = [identity for identity, score in observation.items() if score >= threshold]
        if len(accepted) != 1 or (winner is not None and winner != accepted[0]):
            return None
        winner = accepted[0]
    return winner


def score_distribution(values):
    if not values:
        return {'count': 0}
    ordered=sorted(values)
    return {'count':len(values),'min':ordered[0],'max':ordered[-1],
            'mean':statistics.mean(values),'median':statistics.median(values),
            'p05':ordered[int((len(values)-1)*.05)],'p95':ordered[int((len(values)-1)*.95)]}


def prepare(rows):
    gallery = {}
    marked = [r for r in rows if r.get('enrollment_candidate')]
    candidates = marked or [r for r in rows if r.get('purpose') == 'ENROLL' and r.get('split') == 'calibration']
    for row in candidates:
        gallery[str(row['identity'])] = row
    cases = {'calibration': [], 'validation': []}
    missing = 0
    for row in rows:
        batch = row.get('observations')
        context = row.get('evaluation_context')
        if not batch or not context or context.get('label_source') != 'AUTHORIZED_MEMBER_SELECTION':
            missing += 1
            continue
        if any(row.get('capture_session') == g.get('capture_session') for g in gallery.values()):
            continue
        eligible = set(map(str, context.get('eligible_identities', [])))
        active = {identity: g for identity, g in gallery.items() if identity in eligible}
        scores = [{identity: cosine(v, g['embedding']) for identity, g in active.items()} for v in batch]
        template_scores = {identity: cosine(row['embedding'], g['embedding']) for identity, g in gallery.items()}
        cases[row['split']].append({'identity': str(row['identity']), 'known': str(row['identity']) in active,
                                   'unknown_kind':'UNENROLLED' if str(row['identity']) not in gallery else 'INELIGIBLE',
                                   'scores': scores, 'template_scores': template_scores,
                                   'capture_session': row['capture_session'], 'visit': row['visit'],
                                   'device': row['device'], 'lighting': row['lighting']})
    return gallery, cases, missing


def measurements(cases, duplicate, attendance):
    counts = dict(known=0, unknown=0, correct=0, wrong=0, unmarked=0, unknown_accepted=0,
                  duplicate_trials=0, missed_duplicates=0, wrong_duplicate_identity=0,
                  distinct_trials=0, false_duplicate_rejections=0)
    for case in cases:
        result = identify(case['scores'], attendance)
        if case['known']:
            counts['known'] += 1
            counts['correct' if result == case['identity'] else 'unmarked' if result is None else 'wrong'] += 1
        else:
            counts['unknown'] += 1
            counts['unknown_accepted'] += result is not None
        scores = case['template_scores']
        if case['identity'] in scores:
            counts['duplicate_trials'] += 1
            winner = max(scores, key=scores.get) if scores else None
            accepted = winner is not None and scores[winner] >= duplicate
            counts['missed_duplicates'] += not accepted
            counts['wrong_duplicate_identity'] += accepted and winner != case['identity']
        # A genuinely new identity is tested against other enrolled identities.
        other = [score for identity, score in scores.items() if identity != case['identity']]
        if other:
            counts['distinct_trials'] += 1
            counts['false_duplicate_rejections'] += max(other) >= duplicate
    return {'counts': counts,
            'attendance_false_positive_identification': uncertainty(counts['wrong'], counts['known']),
            'attendance_false_negative_identification': uncertainty(counts['unmarked'], counts['known']),
            'attendance_total_known_failures': uncertainty(counts['wrong']+counts['unmarked'], counts['known']),
            'unknown_false_acceptance': uncertainty(counts['unknown_accepted'], counts['unknown']),
            'unknown_rejection': uncertainty(counts['unknown']-counts['unknown_accepted'], counts['unknown']),
            'duplicate_missed_detection': uncertainty(counts['missed_duplicates'], counts['duplicate_trials']),
            'duplicate_wrong_identity': uncertainty(counts['wrong_duplicate_identity'], counts['duplicate_trials']),
            'false_duplicate_rejection': uncertainty(counts['false_duplicate_rejections'], counts['distinct_trials'])}


def decision_evaluation(rows, duplicate_far, attendance_far):
    gallery, cases, missing = prepare(rows)
    scores = sorted({v for case in cases['calibration'] for v in case['template_scores'].values()} |
                    {v for case in cases['calibration'] for obs in case['scores'] for v in obs.values()})
    candidates = sorted(set(scores+[math.nextafter(v, 1.) for v in scores if v < 1.]))
    reasons = []
    if len(gallery) < 2:
        reasons.append('At least two actual enrollment templates required for identity competition')
    if any(r.get('enrollment_candidate') and r.get('split') == 'validation' for r in rows):
        reasons.append('Actual enrollment template reused held-out validation evidence')
    for split, subset in cases.items():
        if not any(c['known'] for c in subset) or not any(not c['known'] for c in subset):
            reasons.append(f'Complete known and unknown/ineligible attendance batches required in {split}')
        if not any(not c['known'] and c['unknown_kind']=='UNENROLLED' and any(c['scores']) for c in subset):
            reasons.append(f'Unenrolled people must be tested against a nonempty eligible gallery in {split}; an empty roster cannot verify unknown rejection')
        if len({c['capture_session'] for c in subset}) != len(subset):
            reasons.append(f'Reused capture session in {split}')
    curve = []
    for threshold in candidates:
        result = measurements(cases['calibration'], threshold, threshold)
        curve.append({'threshold': threshold, 'metrics': result})
    recommendations = {}
    chosen = {}
    for name, target in [('DUPLICATE_THRESHOLD', duplicate_far), ('ATTENDANCE_THRESHOLD', attendance_far)]:
        passing = []
        for point in curve:
            m = point['metrics']
            if target is None:
                continue
            if name == 'DUPLICATE_THRESHOLD':
                risk, rejection = m['false_duplicate_rejection'], m['duplicate_missed_detection']
                # FAR means a different person falsely identified as a duplicate.
                # Minimize missed same-person duplicates within that risk budget.
                ok = risk['rate'] is not None and rejection['rate'] is not None and risk['rate'] <= target and rejection['rate']<1
            else:
                risk, rejection = m['unknown_false_acceptance'], m['attendance_total_known_failures']
                wrong = m['attendance_false_positive_identification']
                ok = risk['rate'] is not None and wrong['rate'] is not None and rejection['rate'] is not None and max(risk['rate'], wrong['rate']) <= target and m['counts']['correct']>0
            if ok:
                passing.append((rejection['rate'], point['threshold']))
        chosen[name] = min(passing)[1] if passing else None
        if chosen[name] is None:
            reasons.append(f'No complete calibration decision operating point for {name}')
    if all(v is not None for v in chosen.values()):
        validation = measurements(cases['validation'], chosen['DUPLICATE_THRESHOLD'], chosen['ATTENDANCE_THRESHOLD'])
        if not validation['counts']['correct'] or validation['false_duplicate_rejection']['rate'] in (None,1):
            reasons.append('Independent decisions cannot reject every genuine attendance or every distinct enrollment')
        for key, target in [('false_duplicate_rejection', duplicate_far), ('unknown_false_acceptance', attendance_far), ('attendance_false_positive_identification', attendance_far)]:
            metric = validation[key]
            if metric['rate'] is None or metric['rate'] > target:
                reasons.append(f'Independent full-decision validation failed: {key}')
        if not reasons:
            for name, threshold in chosen.items():
                recommendations[name] = {'threshold': threshold, 'validation': validation}
    else:
        validation = None
    slices = {}
    if validation is not None:
        for field in ('visit', 'device', 'lighting', 'unknown_kind'):
            slices[field] = {label: measurements([c for c in cases['validation'] if c[field] == label], chosen['DUPLICATE_THRESHOLD'], chosen['ATTENDANCE_THRESHOLD']) for label in sorted({c[field] for c in cases['validation']})}
    distributions={}
    for split, subset in cases.items():
        genuine=[c['template_scores'][c['identity']] for c in subset if c['identity'] in c['template_scores']]
        impostor=[v for c in subset for identity,v in c['template_scores'].items() if identity!=c['identity']]
        attendance_genuine=[obs[c['identity']] for c in subset for obs in c['scores'] if c['identity'] in obs]
        attendance_impostor=[v for c in subset for obs in c['scores'] for identity,v in obs.items() if identity!=c['identity']]
        distributions[split]={'genuine_template':score_distribution(genuine),'impostor_template':score_distribution(impostor),
                              'genuine_attendance_observation':score_distribution(attendance_genuine),'impostor_attendance_observation':score_distribution(attendance_impostor)}
    return {'version': VERSION, 'complete': not reasons, 'blockers': reasons, 'gallery_identities': len(gallery), 'score_distributions':distributions,
            'full_calibration_batches': len(cases['calibration']), 'full_validation_batches': len(cases['validation']),
            'aggregate_only_or_unscoped_samples': missing, 'candidate_thresholds': curve,
            'recommendations': recommendations, 'validation': validation, 'separate_sessions': {split: len({c['capture_session'] for c in subset}) for split, subset in cases.items()},
            'condition_results': slices, 'limitations': ['Confidence intervals treat captures as independent; repeated identities/visits are correlated',
                'Unknown trials require explicitly selected people absent from the eligible enrolled gallery; labels are never predictions',
                'Different-person duplicate trials exclude the query identity from the enrolled gallery']}


def provisional_pilot(rows):
    """Exploratory within-session template operating point, isolated pilot only."""
    grouped = {}
    for row in rows:
        grouped.setdefault(str(row['identity']), []).append(row)
    if len(grouped) < 2 or any(len(values) < 2 for values in grouped.values()):
        return {'ready': False, 'reason': 'Two consenting identities with repeat samples required', 'production_approved': False}
    gallery = {identity: values[0]['embedding'] for identity, values in grouped.items()}
    genuine, impostor = [], []
    for identity, values in grouped.items():
        for row in values[1:]:
            genuine.append(cosine(row['embedding'], gallery[identity]))
            impostor.extend(cosine(row['embedding'], template) for other, template in gallery.items() if other != identity)
    scores = sorted(set(genuine+impostor))
    candidates = sorted(set(scores+[math.nextafter(v, 1.) for v in scores if v < 1.]))
    points = [(sum(v < t for v in genuine)/len(genuine), t) for t in candidates if not any(v >= t for v in impostor)]
    best = min(points) if points else None
    ready = best is not None and best[0] < 1
    return {'ready': ready, 'production_approved': False, 'threshold': best[1] if ready else None,
            'identities': len(grouped), 'genuine_trials': len(genuine), 'impostor_trials': len(impostor),
            'genuine_rejection': uncertainty(sum(v < best[1] for v in genuine), len(genuine)) if best else None,
            'impostor_acceptance': uncertainty(0, len(impostor)) if best else None,
            'reason': '' if ready else 'No useful observed separation for provisional pilot',
            'limitations': ['Exploratory aggregate-only/within-session fit, not independent validation', 'No production release or accuracy claim', 'Only the isolated test-only database may use this setting']}
