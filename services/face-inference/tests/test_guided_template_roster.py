"""Six-template recognition transport; mocked matcher, unchanged policy."""
import io
import sys
import unittest
from pathlib import Path
from unittest.mock import Mock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'app'))
from matcher import MatchResult, QualityResult
from safe_logging import SafeFaceLogger
from service import InferenceService, InferenceError
from settings import Settings

class GuidedTemplateRosterTest(unittest.TestCase):
    def service(self):
        matcher=Mock(spec=['match'])
        matcher.match.return_value=MatchResult(decision='ACCEPT',candidate_ref='child',top1_score=.99,
            quality=QualityResult(face_count=1,sharpness_ok=True,exposure_ok=True,pose_ok=True,size_ok=True,centred_ok=True),
            liveness='LIVE',model_version='test')
        service=InferenceService(matcher,Settings(calibration_approved=True),logger=SafeFaceLogger(io.StringIO()))
        return service,matcher

    def test_six_templates_reach_matcher_without_averaging(self):
        service,matcher=self.service()
        vectors=[[.1,i/100,.3] for i in range(6)]
        try:
            service.start_scan('guided-scan',[{'candidate_ref':'child','embeddings':vectors}])
        except InferenceError as error:
            self.fail('six-template roster must be accepted: '+str(error))
        service.recognize('guided-scan',[b'one',b'two',b'three'])
        self.assertEqual(tuple(tuple(v) for v in vectors),matcher.match.call_args.args[1]['child'])

    def test_legacy_three_still_reach_matcher(self):
        service,matcher=self.service()
        vectors=[[.1,i/100,.3] for i in range(3)]
        service.start_scan('legacy-scan',[{'candidate_ref':'child','embeddings':vectors}])
        service.recognize('legacy-scan',[b'one',b'two',b'three'])
        self.assertEqual(tuple(tuple(v) for v in vectors),matcher.match.call_args.args[1]['child'])

    def test_more_than_six_and_invalid_sixth_are_rejected(self):
        for vectors in [[[.1,.2,.3]]*7, [[.1,.2,.3]]*5+[[float('nan'),.2,.3]], [[.1,.2,.3]]*5+[[.1,.2]]]:
            service,matcher=self.service()
            with self.assertRaises(InferenceError):
                service.start_scan('invalid-scan',[{'candidate_ref':'child','embeddings':vectors}])
            self.assertEqual({},service._scans)
            matcher.match.assert_not_called()

if __name__=='__main__': unittest.main()
