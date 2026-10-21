"""Public image benchmark: no policy installation or member/database access."""
import argparse, hashlib, itertools, json, math, statistics, subprocess
from collections import defaultdict
from pathlib import Path
from decision_evaluation import cosine, identify, score_distribution, uncertainty
ROOT=Path(__file__).resolve().parents[1]

def prepare(images):
    grouped=defaultdict(list)
    for row in images:grouped[row['identity']].append(row)
    # Partition membership was fixed in the download manifest before inference.
    labels=sorted(grouped,key=lambda label:hashlib.sha256(label.encode()).hexdigest());known=labels[:(len(labels)*2)//3];unknown=labels[len(known):]
    gallery={identity:sorted(grouped[identity],key=lambda r:r['view'])[0] for identity in known}
    cases=[]
    for identity,rows in sorted(grouped.items()):
        for row in sorted(rows,key=lambda r:r['view']):
            if identity in gallery and row['sha256']==gallery[identity]['sha256']:continue
            scores={key:cosine(row['embedding'],value['embedding']) for key,value in gallery.items()}
            ranked=sorted(scores.items(),key=lambda item:item[1],reverse=True)
            cases.append(dict(file=row['file'],identity=identity,known=identity in gallery,scores=scores,
                              top=ranked[0][0] if ranked else None,margin=ranked[0][1]-ranked[1][1] if len(ranked)>1 else None,
                              embedding=row['embedding'],sha256=row['sha256']))
    return gallery,cases

def decision_trace(case,duplicate,attendance):
    """Private score diagnostics, using the same frozen decisions as metrics/PHP."""
    scores=case['scores'];ranked=sorted(scores,key=scores.get,reverse=True)
    winner=identify([scores],attendance);accepted=sum(score>=attendance for score in scores.values())
    if case['known']:
        category=('KNOWN_CORRECT' if winner==case['identity'] else 'KNOWN_WRONG_ACCEPTED' if winner is not None
                  else 'KNOWN_AMBIGUOUS' if accepted>1 else 'KNOWN_CORRECT_TOP_BELOW_THRESHOLD'
                  if case['top']==case['identity'] else 'KNOWN_WRONG_TOP_BELOW_THRESHOLD')
    else:
        category='UNKNOWN_ACCEPTED' if winner is not None else 'UNKNOWN_AMBIGUOUS' if accepted>1 else 'UNKNOWN_REJECTED'
    highest=max(scores.values(),default=-1)
    return dict(file=case['file'],identity=case['identity'],known=case['known'],category=category,
                genuine_rank=ranked.index(case['identity'])+1 if case['known'] else None,
                genuine_score=scores.get(case['identity']),top_identity=case['top'],top_score=highest,
                runner_up_score=scores[ranked[1]] if len(ranked)>1 else None,
                accepted_candidates=accepted,attendance_winner=winner,
                duplicate_detected=highest>=duplicate,
                missed_duplicate=case['known'] and highest<duplicate,
                false_duplicate=not case['known'] and highest>=duplicate)

def metrics(cases,threshold):
    counts=dict(known=0,unknown=0,correct=0,false_positive=0,false_negative=0,unknown_accepted=0,ambiguous=0,
                duplicate_trials=0,duplicate_missed=0,distinct_trials=0,false_duplicate=0,wrong_duplicate_identity=0)
    for case in cases:
        scores=case['scores'];winner=identify([scores],threshold)
        accepted=sum(v>=threshold for v in scores.values());counts['ambiguous']+=accepted>1
        if case['known']:
            counts['known']+=1;counts['duplicate_trials']+=1
            counts['correct']+=winner==case['identity'];counts['false_positive']+=winner is not None and winner!=case['identity']
            counts['false_negative']+=winner is None;counts['duplicate_missed']+=max(scores.values(),default=-1)<threshold
            counts['wrong_duplicate_identity']+=bool(scores) and max(scores.values())>=threshold and max(scores,key=scores.get)!=case['identity']
        else:
            counts['unknown']+=1;counts['distinct_trials']+=1;counts['unknown_accepted']+=winner is not None
            counts['false_duplicate']+=max(scores.values(),default=-1)>=threshold
    return dict(counts=counts,attendance_false_positive=uncertainty(counts['false_positive'],counts['known']),
                attendance_false_negative=uncertainty(counts['false_negative'],counts['known']),
                unknown_acceptance=uncertainty(counts['unknown_accepted'],counts['unknown']),
                duplicate_miss=uncertainty(counts['duplicate_missed'],counts['duplicate_trials']),
                false_duplicate_rejection=uncertainty(counts['false_duplicate'],counts['distinct_trials']))

def evaluate(data):
    contract=json.loads((ROOT/'pf/js/browser-face/contract.json').read_text())
    if data.get('asset_manifest')!=json.loads((ROOT/'pf/vendor/browser-face/manifest.json').read_text()):raise ValueError('Model asset manifest mismatch')
    if data['contract']!=contract or data.get('source')!='public-synthetic-research':raise ValueError('Pipeline contract/source mismatch')
    good=[];failed=[];hashes=set()
    for row in data['images']:
        if row['sha256'] in hashes:raise ValueError('Repeated image cannot be an independent trial')
        hashes.add(row['sha256']);v=row.get('embedding')
        if v is None:failed.append({k:row[k] for k in ('identity','file','face_count','status')});continue
        if len(v)!=128 or not all(math.isfinite(x) for x in v) or abs(math.sqrt(sum(x*x for x in v))-1)>1e-6:raise ValueError('Invalid normalized embedding')
        good.append(row)
    # Missing views remain documented capture failures, never silently synthesized.
    groups={partition:[r for r in good if r['partition']==partition] for partition in ('development','heldout')}
    labels=[set(r['identity'] for r in rows) for rows in groups.values()]
    if labels[0]&labels[1]:raise ValueError('Identity leakage between partitions')
    prepared={partition:prepare(rows) for partition,rows in groups.items()}
    distributions={}
    for partition,rows in groups.items():
        genuine,impostor=[],[]
        for a,b in itertools.combinations(rows,2):(genuine if a['identity']==b['identity'] else impostor).append(cosine(a['embedding'],b['embedding']))
        distributions[partition]={'genuine':score_distribution(genuine),'impostor':score_distribution(impostor)}
    dev=prepared['development'][1]
    candidates=sorted({-1.,1.}|{s for case in dev for s in case['scores'].values()}|{math.nextafter(s,math.inf) for case in dev for s in case['scores'].values() if s<1})
    if not dev or not any(c['known'] for c in dev) or not any(not c['known'] for c in dev):raise ValueError('Insufficient distinct labeled queries')
    curve=[dict(threshold=t,**metrics(dev,t)) for t in candidates]
    # Descriptive equal-cost exploratory operating points; no claimed FAR target.
    def duplicate_loss(row):return row['duplicate_miss']['rate']+row['false_duplicate_rejection']['rate']
    def attendance_loss(row):return row['attendance_false_positive']['rate']+row['attendance_false_negative']['rate']+row['unknown_acceptance']['rate']
    duplicate=min(curve,key=lambda r:(duplicate_loss(r),-r['threshold']))['threshold']
    attendance=min(curve,key=lambda r:(attendance_loss(r),-r['threshold']))['threshold']
    results={};php_cases=[];expected=[]
    for partition,(gallery,cases) in prepared.items():
        numeric={identity:i+1 for i,identity in enumerate(gallery)}
        templates=[dict(member_id=numeric[key],vector=value['embedding']) for key,value in gallery.items()]
        for case in cases:
            # Shape probe only: repeated vector is one source image, not three observations.
            php_cases.append(dict(observations=[case['embedding']]*3,templates=templates,threshold=attendance,duplicate_threshold=duplicate))
            winner=identify([case['scores']],attendance);expected.append({'winner':numeric.get(winner),'duplicate':max(case['scores'].values(),default=-1)>=duplicate})
        genuine,impostor=[],[]
        for a,b in itertools.combinations(groups[partition],2):(genuine if a['identity']==b['identity'] else impostor).append(cosine(a['embedding'],b['embedding']))
        pair_rates={name:{'false_acceptance':uncertainty(sum(v>=threshold for v in impostor),len(impostor)),'false_rejection':uncertainty(sum(v<threshold for v in genuine),len(genuine))} for name,threshold in [('duplicate',duplicate),('attendance',attendance)]}
        results[partition]={'pair_verification':pair_rates,'gallery_identities':len(gallery),'query_images':len(cases),
                            'decision_traces':[decision_trace(case,duplicate,attendance) for case in cases],
                            'duplicate':metrics(cases,duplicate),'attendance':metrics(cases,attendance),
                            'top_candidate_error':uncertainty(sum(c['top']!=c['identity'] for c in cases if c['known']),sum(c['known'] for c in cases)),
                            'top_two_margin':score_distribution([c['margin'] for c in cases if c['margin'] is not None])}
    actual=json.loads(subprocess.run(['php',str(ROOT/'evaluation/backend_match_probe.php')],input=json.dumps(php_cases),capture_output=True,text=True,check=True).stdout)
    if actual!=expected:raise ValueError('Python evaluation disagrees with actual PHP matching')
    return dict(dataset=data['dataset'],revision=data['revision'],license=data['license'],attribution=data['attribution'],source=data['source'],production_approved=False,pilot_installable=False,
                model_version=contract['model_version'],preprocessing_version=contract['preprocessing_version'],asset_manifest=data['asset_manifest'],
                total_images=len(data['images']),usable_images=len(good),failed_images=failed,identities=len(set(r['identity'] for r in good)),
                distributions=distributions,exploratory_public_operating_points={'DUPLICATE_THRESHOLD':duplicate,'ATTENDANCE_THRESHOLD':attendance},
                threshold_selection='Development identities only; equal-cost error minimization, no release FAR budget. Frozen heldout identities.',
                development_threshold_tradeoffs=curve,decisions=results,backend_parity_cases=len(actual),backend_parity=True,repeat_cosine=data['repeat_cosine'],
                limitations=['Synthetic adult ages 25/50/65; no children or real camera visits.', 'Three generated pose views per identity are correlated, not independent live captures.', 'Gallery references use one image; this does not validate five-frame enrollment aggregation.', 'Identification metrics condition on quality-approved images; six excluded images remain separate capture failures.', 'Three identical query vectors test PHP API shape only; each query remains one image trial.', 'Wilson intervals are descriptive image-level intervals; within-identity correlation makes them optimistic.', 'No public threshold may enable pilot or production matching.', 'Top candidate is reported for research; ambiguous accepted candidates remain unmarked.'])

def readable(report):
    lines=['# Public face pipeline evaluation', '', 'Research only. Production and pilot installation are disabled.', '',
           f"Dataset: {report['dataset']} @ {report['revision']}; {report['license']}. {report['attribution']}", '',
           f"Usable images: {report['usable_images']}/{report['total_images']}; identities: {report['identities']}; PHP parity queries: {report['backend_parity_cases']}.", '',
           f"Model: {report['model_version']}; preprocessing: {report['preprocessing_version']}.", '',
           'Development-only exploratory points (not deployable): '+json.dumps(report['exploratory_public_operating_points']), '',
           '| Partition | Genuine pairs / mean | Impostor pairs / mean |', '|---|---|---|']
    for partition,distributions in report['distributions'].items():
        g,i=distributions['genuine'],distributions['impostor'];lines.append(f"| {partition} | {g['count']} / {g.get('mean',0):.6f} | {i['count']} / {i.get('mean',0):.6f} |")
    lines += ['', '| Partition | Gallery | Known correct / queries | Known unmarked | Known wrong | Unknown accepted / queries | Ambiguous |', '|---|---:|---|---:|---:|---|---:|']
    for partition,result in report['decisions'].items():
        c=result['attendance']['counts'];lines.append(f"| {partition} | {result['gallery_identities']} | {c['correct']}/{c['known']} | {c['false_negative']} | {c['false_positive']} | {c['unknown_accepted']}/{c['unknown']} | {c['ambiguous']} |")
    lines += ['', 'Detailed pair rates, duplicate errors, uncertainty, top-two margins and development tradeoffs are in the JSON report.', '', 'Limitations:', '']+[f'- {item}' for item in report['limitations']]
    return '\n'.join(lines)+'\n'

def main():
    p=argparse.ArgumentParser();p.add_argument('input');p.add_argument('--output',required=True);a=p.parse_args()
    out=Path(a.output).resolve()
    if ROOT in out.parents:raise ValueError('Private report required outside web root')
    report=evaluate(json.loads(Path(a.input).read_text()));out.write_text(json.dumps(report,indent=2));out.chmod(0o600)
    out.with_suffix('.md').write_text(readable(report));out.with_suffix('.md').chmod(0o600)
    print(json.dumps({k:report[k] for k in ('total_images','usable_images','identities','exploratory_public_operating_points','backend_parity_cases')},indent=2))
if __name__=='__main__':main()
