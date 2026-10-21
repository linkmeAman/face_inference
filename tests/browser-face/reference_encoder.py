"""Existing OpenCV/CPU SFace reference, also for private development diagnostics."""
import json
import sys
from pathlib import Path
import cv2
import numpy as np
ROOT=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'services/face-inference'))
from app.encoder import SFaceEncoder

def development_diagnostics(source, output):
    """Compare identical browser rasters/landmarks; never inspect held-out images."""
    source, output = Path(source).resolve(), Path(output).resolve()
    if ROOT in source.parents or ROOT in output.parents or output.exists():
        raise ValueError('New private diagnostic output outside web root required')
    data = json.loads(source.read_text())
    if data['source'] != 'public-synthetic-research' or any(
            row['partition'] != 'development' for row in data['images']):
        raise ValueError('Development-only public diagnostics required')
    contract = json.loads((ROOT/'pf/js/browser-face/contract.json').read_text())
    if data['contract'] != contract:
        raise ValueError('Browser preprocessing contract mismatch')
    folder = Path(str(source)+'.diagnostics')
    encoder = SFaceEncoder(ROOT/'services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx')
    # Prepared asset has the same weights, without the source export's stale inputs.
    official = cv2.FaceRecognizerSF_create(str(ROOT/'pf/vendor/browser-face/sface.onnx'), '')
    detector = cv2.FaceDetectorYN_create(str(ROOT/'pf/vendor/browser-face/yunet.onnx'), '',
                                       (640, 640), contract['detector_score'], contract['nms_iou'], 5000)
    results = []
    for row in data['images']:
        name = row['file']
        if Path(name).name != name:
            raise ValueError('Unexpected diagnostic image path')
        raster = cv2.imread(str(folder/(name+'.raster.png')))
        aligned = cv2.imread(str(folder/(name+'.aligned.png')))
        if raster is None or aligned is None or aligned.shape != (112, 112, 3):
            raise ValueError('Missing browser raster/alignment diagnostic')
        landmarks = np.asarray(row['landmarks'], np.float32)
        face = np.asarray([0, 0, 1, 1]+landmarks.flatten().tolist()+[1], np.float32)
        reference = encoder.encode(raster, face)
        crop = encoder._align(raster, face)
        official_crop = official.alignCrop(raster, face)
        feature = np.asarray(official.feature(official_crop), np.float32).reshape(-1)
        feature /= np.linalg.norm(feature)
        faces = detector.detect(raster)[1]
        result = {key: row[key] for key in ('file', 'identity', 'partition', 'view', 'sha256',
                                          'landmarks', 'quality_metrics', 'alignment_transform')}
        result.update(
            reference_embedding=reference.tolist(),
            crop_pixel_mean_difference=float(np.abs(crop.astype(float)-aligned).mean()),
            official_crop_pixel_mean_difference=float(np.abs(official_crop.astype(float)-aligned).mean()),
            browser_reference_cosine=float(np.dot(reference, row['embedding'])) if row['embedding'] else None,
            official_browser_cosine=float(np.dot(feature, row['embedding'])) if row['embedding'] else None,
            official_feature_dimension=len(feature),
            opencv_face_count=0 if faces is None else len(faces))
        if faces is not None and len(faces) == 1:
            result['detector_landmark_max_difference'] = float(np.abs(faces[0][4:14].reshape(5, 2)-landmarks).max())
        if len(feature) != contract['dimension'] or abs(np.linalg.norm(reference)-1) > 1e-6:
            raise AssertionError('Reference embedding dimension/normalization mismatch')
        if row['embedding'] and (result['browser_reference_cosine'] < .99 or result['official_browser_cosine'] < .99):
            raise AssertionError('Browser and OpenCV recognition diverged')
        if result['official_crop_pixel_mean_difference'] > .2:
            raise AssertionError('Browser and OpenCV alignment diverged')
        results.append(result)
    report = dict(reference='Existing CPU encoder and official OpenCV FaceRecognizerSF/FaceDetectorYN',
                  opencv_version=cv2.__version__, contract=contract,
                  recognizer_input=[(x.name, x.shape, x.type) for x in encoder._session.get_inputs()],
                  recognizer_outputs=[(x.name, x.shape, x.type) for x in encoder._session.get_outputs()], images=results)
    with output.open('x') as handle:
        output.chmod(0o600)
        json.dump(report, handle, indent=2)
    usable = [row for row in results if row['browser_reference_cosine'] is not None]
    print(json.dumps(dict(images=len(results), usable=len(usable),
                         reference_cosine_min=min(row['browser_reference_cosine'] for row in usable),
                         official_cosine_min=min(row['official_browser_cosine'] for row in usable),
                         alignment_mean_pixel_difference_max=max(row['official_crop_pixel_mean_difference'] for row in results))))


if __name__ == '__main__':
    if len(sys.argv) == 4 and sys.argv[1] == '--development-diagnostics':
        development_diagnostics(sys.argv[2], sys.argv[3])
    else:
        image = cv2.imread(sys.argv[1])
        landmarks = json.loads(Path(sys.argv[2]).read_text())
        face = np.asarray([0, 0, 1, 1]+[v for pair in landmarks for v in pair]+[1], dtype=np.float32)
        encoder = SFaceEncoder(ROOT/'services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx')
        Path(sys.argv[3]).write_text(json.dumps(encoder.encode(image, face).tolist()))
