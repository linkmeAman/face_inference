import json
import math
import tempfile
import unittest
from pathlib import Path
from calibrate_thresholds import evaluate, load, pairs, rates, CONTRACT
from decision_evaluation import identify, provisional_pilot, uncertainty


class CalibrationTests(unittest.TestCase):
    def test_pair_counts_and_cross_visit(self):
        rows = [{'identity': 'A', 'visit': '1', 'embedding': [1,0]},
                {'identity': 'A', 'visit': '2', 'embedding': [1,0]},
                {'identity': 'B', 'visit': '1', 'embedding': [0,1]}]
        genuine, impostor = pairs(rows)
        self.assertEqual(genuine, [1])
        self.assertEqual(impostor, [0,0])
        self.assertEqual(rates(genuine, impostor, .5)['false_accepts'], 0)
        self.assertEqual(rates(genuine, impostor, 1)['false_rejects'], 0)

    def test_insufficient_evidence_never_recommends(self):
        result = evaluate([], json.loads(CONTRACT.read_text()), 0, 0)
        self.assertFalse(result['approved'])
        self.assertTrue(result['blockers'])
        self.assertTrue(all(value is None for value in result['recommendations'].values()))

    def test_representative_evidence_and_held_out_visits(self):
        contract=json.loads(CONTRACT.read_text())
        rows=[]
        for identity in range(20):
            vector=[0.0]*128;vector[identity]=1.0
            for visit in range(6):
                rows.append({'identity':str(identity),'visit':str(visit),'device':str(visit%2),'lighting':str(visit%2),
                             'embedding':vector,'consent':True,'source':'consented-live-cameras',
                             'split':'calibration' if visit<3 else 'validation', **{key:contract[key] for key in ('model_version','preprocessing_version')}})
        # Synthetic test data exercises math only, never produces a deployed policy.
        result=evaluate(rows,contract,0,0)
        self.assertFalse(result['approved'])
        self.assertIsNone(result['recommendations']['ATTENDANCE_THRESHOLD'])
        for r in rows:
            r.update(observations=[r['embedding']]*5, capture_session=r['identity']+'_'+r['visit'], purpose='VALIDATION' if r['identity']=='19' else 'ENROLL',
                     enrollment_candidate=r['visit']=='0' and r['identity']!='19',
                     evaluation_context={'label_source':'AUTHORIZED_MEMBER_SELECTION','eligible_identities':[str(i) for i in range(20)]})
        result=evaluate(rows,contract,0,0)
        self.assertIsNotNone(result['recommendations']['ATTENDANCE_THRESHOLD'])
        self.assertEqual(result['recommendations']['ATTENDANCE_THRESHOLD']['validation']['counts']['unknown_accepted'],0)
        missing=[r for r in rows if not (r['identity']=='19' and r['split']=='validation')]
        self.assertTrue(any('Every identity' in reason for reason in evaluate(missing,contract,0,0)['blockers']))
        rows[-1]['visit']='0'
        result=evaluate(rows,contract,0,0)
        self.assertIsNone(result['recommendations']['ATTENDANCE_THRESHOLD'])

    def test_invalid_and_unconsented_input(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'invalid.jsonl';path.write_text(json.dumps({'embedding':[math.nan]*128}))
            with self.assertRaises(ValueError):load(path)

    def test_conflicting_or_ambiguous_attendance_has_no_identity(self):
        self.assertIsNone(identify([{'a':1,'b':1}]*3,.5))
        self.assertIsNone(identify([{'a':1},{'b':1},{'a':1}],.5))
        self.assertEqual(identify([{'a':1,'b':0}]*3,.5),'a')

    def test_pilot_data_separation_and_uncertainty(self):
        rows=[{'identity':i,'embedding':v} for i,v in [('a',[1,0]),('a',[1,0]),('b',[0,1]),('b',[0,1])]]
        result=provisional_pilot(rows)
        self.assertTrue(result['ready']);self.assertFalse(result['production_approved'])
        self.assertGreater(uncertainty(0,10)['wilson_95'][1],0)
        for row in rows:row['embedding']=[1,0]
        self.assertFalse(provisional_pilot(rows)['ready'])


if __name__ == '__main__':
    unittest.main()
