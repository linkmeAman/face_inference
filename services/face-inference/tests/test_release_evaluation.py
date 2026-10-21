import sys
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "tools"))
from release_evaluation import exact_upper, validate, evaluate, PAD_CLASSES
from partition_study import partition


def row(subject="a" * 16, session="s" * 16, kind="genuine", **changes):
    value = dict(subject_id=subject, session_id=session, kind=kind, device="phone-a",
                 venue="hall-a", lighting="day", model_generation="g" * 64,
                 policy_sha256="a" * 64, latency_ms=50, camera_latency_ms=200,
                 memory_mb=120, gallery_size=15, gallery_kind="study", gallery_session_id="e" * 16,
                 accepted=True, selected_subject_id=subject, within_retry=True, comparison_complete=False)
    value.update(changes)
    return value


class ReleaseEvaluationTests(unittest.TestCase):
    def test_zero_error_exact_bounds(self):
        self.assertAlmostEqual(1 - .05 ** (1 / 49000), exact_upper(0, 49000))
        self.assertAlmostEqual(1 - .05 ** (1 / 50), exact_upper(0, 50))

    def test_subject_and_session_leakage(self):
        partitions = {"tuning": [row()], "calibration": [row(session="t" * 16)],
                      "held_out": [row(subject="b" * 16, session="u" * 16)]}
        with self.assertRaisesRegex(ValueError, "subject leaked"):
            validate(partitions)
        partitions["calibration"][0]["subject_id"] = "c" * 16
        partitions["calibration"][0]["session_id"] = "s" * 16
        with self.assertRaisesRegex(ValueError, "session leaked"):
            validate(partitions)

    def test_score_only_and_pad_coverage(self):
        partitions = {"tuning": [row()], "calibration": [row(subject="b" * 16, session="t" * 16)],
                      "held_out": [row(subject="c" * 16, session="u" * 16)]}
        partitions["held_out"][0]["image"] = "bytes"
        with self.assertRaisesRegex(ValueError, "raw images"):
            validate(partitions)
        del partitions["held_out"][0]["image"]
        report = evaluate(partitions, ["phone-a", "phone-b", "phone-c"])
        self.assertFalse(report["passes"])
        self.assertFalse(report["coverage_passes"])
        self.assertEqual(8, len(PAD_CLASSES))

    def test_partition_keeps_subjects_and_gallery_disjoint(self):
        records = [row(subject=f"{i:032x}", session=f"{i+100:032x}") for i in range(10)]
        records.append(row(subject=f"{0:032x}", session=f"{200:032x}", kind="impostor",
                           gallery_subject_id=f"{9:032x}"))
        partitions, excluded = partition(records)
        self.assertIn(excluded, (0, 1))
        self.assertEqual(11 - excluded, sum(map(len, partitions.values())))
        validate(partitions)

    def test_complete_gallery_requires_every_cross_identity_score(self):
        partitions = {"tuning": [row()], "calibration": [row(subject="b" * 16, session="t" * 16)],
                      "held_out": [row(subject="c" * 16, session="u" * 16, comparison_complete=True)]}
        with self.assertRaisesRegex(ValueError, "gallery comparison count"):
            validate(partitions)

    def test_full_synthetic_release_gate(self):
        held = []
        subjects = [f"{i+1000:032x}" for i in range(50)]
        for i, subject in enumerate(subjects):
            for session_index in range(22):
                session = f"{i*22+session_index+10000:032x}"
                common = dict(subject=subject, session=session, device=f"phone-{i % 3}",
                              venue=f"hall-{i % 2}", lighting=f"light-{i % 4}")
                class_gallery = session_index == 0
                held.append(row(gallery_kind="class" if class_gallery else "study", gallery_size=15 if class_gallery else 50,
                                comparison_complete=True, **common))
                gallery_subjects = [subjects[(i+j)%50] for j in range(15)] if class_gallery else subjects
                for gallery in gallery_subjects:
                    if gallery != subject:
                        held.append(row(kind="impostor", gallery_subject_id=gallery,
                                        gallery_kind="class" if class_gallery else "study", gallery_size=15 if class_gallery else 50,
                                        accepted=False, **common))
        for j, attack in enumerate(sorted(PAD_CLASSES)):
            for i in range(300):
                held.append(row(subject=subjects[i % 50], session=f"{50000+j*300+i:032x}",
                                kind="pad", attack_class=attack, pad_missed=False))
        partitions = {"tuning": [row(subject="a" * 16, session="x" * 16)],
                      "calibration": [row(subject="b" * 16, session="y" * 16)],
                      "held_out": held}
        report = evaluate(partitions, ["phone-0", "phone-1", "phone-2"])
        self.assertTrue(report["passes"])
        self.assertGreaterEqual(report["cross_identity_comparisons"], 49000)
        self.assertEqual(0, report["wrong_person_accepts"])


if __name__ == "__main__":
    unittest.main()
