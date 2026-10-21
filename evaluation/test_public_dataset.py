"""Structural unit fixtures, never biometric calibration evidence."""
import copy, hashlib, json, unittest
from pathlib import Path
from public_dataset_evaluate import evaluate, prepare, decision_trace
from download_controlface_subset import select
from decision_evaluation import cosine
ROOT=Path(__file__).resolve().parents[1]

def fixture():
    contract=json.loads((ROOT/'pf/js/browser-face/contract.json').read_text())
    images=[]
    for partition in ('development','heldout'):
        for identity in range(3):
            vector=[0.]*128;vector[identity+(3 if partition=='heldout' else 0)]=1.
            for view in range(3):
                tag=f'unit-only-{partition}-{identity}-{view}'
                images.append(dict(identity=f'{partition}-{identity}',partition=partition,view=view,sha256=hashlib.sha256(tag.encode()).hexdigest(),embedding=vector,file=tag,face_count=1,status='unit only'))
    return dict(contract=contract,asset_manifest=json.loads((ROOT/'pf/vendor/browser-face/manifest.json').read_text()),source='public-synthetic-research',dataset='structural-unit-fixture',license='fixture-only',revision='fixture',attribution='fixture',repeat_cosine=1.,images=images)
class PublicEvaluationTest(unittest.TestCase):
    def test_failure_trace_distinguishes_ranking_threshold_and_ambiguity(self):
        def trace(scores,known=True):
            return decision_trace(dict(file='diagnostic-only',identity='a',known=known,
                                       scores=scores,top=max(scores,key=scores.get)),.75,.8)
        below=trace({'a':.7,'b':.1})
        self.assertEqual(below['category'],'KNOWN_CORRECT_TOP_BELOW_THRESHOLD')
        self.assertEqual(below['genuine_rank'],1);self.assertTrue(below['missed_duplicate'])
        ambiguous=trace({'a':.81,'b':.82})
        self.assertEqual(ambiguous['category'],'KNOWN_AMBIGUOUS');self.assertIsNone(ambiguous['attendance_winner'])
        self.assertEqual(ambiguous['genuine_rank'],2)
        self.assertEqual(trace({'a':.2,'b':.81})['category'],'KNOWN_WRONG_ACCEPTED')
        self.assertEqual(trace({'b':.81},False)['category'],'UNKNOWN_ACCEPTED')
        self.assertEqual(trace({'b':.81,'c':.83},False)['category'],'UNKNOWN_AMBIGUOUS')
        self.assertNotIn('embedding',below)

    def test_cosine_accumulation_matches_php_score_boundary(self):
        self.assertEqual(cosine([1.,1e-16,-1.],[1.,1.,1.]),0.)

    def test_php_parity_and_release_disabled(self):
        report=evaluate(fixture());self.assertTrue(report['backend_parity']);self.assertFalse(report['production_approved']);self.assertFalse(report['pilot_installable']);self.assertEqual(report['backend_parity_cases'],14)
    def test_duplicate_image_rejected(self):
        data=fixture();data['images'][1]['sha256']=data['images'][0]['sha256']
        with self.assertRaisesRegex(ValueError,'Repeated image'):evaluate(data)
    def test_identity_partition_leakage_rejected(self):
        data=fixture();data['images'][-1]['identity']=data['images'][0]['identity']
        with self.assertRaisesRegex(ValueError,'Identity leakage'):evaluate(data)
    def test_bad_unit_vector_rejected(self):
        data=fixture();data['images'][0]['embedding'][0]=2.
        with self.assertRaisesRegex(ValueError,'normalized'):evaluate(data)
    def test_contract_and_model_mismatch_rejected(self):
        for field in ('contract','asset_manifest'):
            data=fixture();data[field]={}
            with self.assertRaises(ValueError):evaluate(data)
    def test_quality_failure_preserved_not_synthesized(self):
        data=fixture();data['images'][0]['embedding']=None
        report=evaluate(data);self.assertEqual(len(report['failed_images']),1);self.assertEqual(report['usable_images'],17)
    def test_subset_partition_fixed_before_inference(self):
        entries=[dict(name=f'root/A/identity-{identity}/image-{view}.png') for identity in range(3) for view in range(3)]
        chosen=select(entries);self.assertEqual(len(chosen),6);self.assertEqual({r['identity'] for r in chosen if r['partition']=='development'},{'root/A/identity-0'});self.assertEqual({r['identity'] for r in chosen if r['partition']=='heldout'},{'root/A/identity-1'})
if __name__=='__main__':unittest.main()
