import sys,json,collections
from pathlib import Path
import numpy as np,cv2
root=Path('/var/www/admin.creativeculturalcentre.in');sys.path.insert(0,str(root/'services/face-inference'))
from app.encoder import SFaceEncoder
source=Path('/tmp/ccc-recognition-development-diagnostics-20261009.json');data=json.loads(source.read_text());encoder=SFaceEncoder(root/'services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx')
folder=Path(str(source)+'.diagnostics');out=[]
try: official=cv2.FaceRecognizerSF_create(str(root/'pf/vendor/browser-face/sface.onnx'),'')
except Exception: official=None
try: detector=cv2.FaceDetectorYN_create(str(root/'pf/vendor/browser-face/yunet.onnx'),'',(640,640),.85,.3,5000)
except Exception: detector=None
for row in data['images']:
 raster=cv2.imread(str(folder/(row['file']+'.raster.png')));aligned=cv2.imread(str(folder/(row['file']+'.aligned.png')))
 landmarks=np.asarray(row['landmarks'],np.float32);face=np.asarray([0,0,1,1]+landmarks.flatten().tolist()+[1],np.float32)
 ref=encoder.encode(raster,face);crop=encoder._align(raster,face);diff=np.abs(crop.astype(float)-aligned.astype(float));entry={k:row[k] for k in ['file','identity','partition','view','quality_metrics','alignment_transform','landmarks','sha256']}
 entry.update(crop_pixel_mean_difference=float(diff.mean()),crop_pixel_max_difference=float(diff.max()),reference_embedding=ref.tolist(),browser_reference_cosine=float(np.dot(ref,row['embedding'])) if row['embedding'] else None)
 if official is not None:
  official_crop=official.alignCrop(raster,face);entry['official_crop_pixel_mean_difference']=float(np.abs(official_crop.astype(float)-aligned).mean())
  feature=np.asarray(official.feature(official_crop),np.float32).reshape(-1);feature/=np.linalg.norm(feature);entry['official_feature_dimension']=len(feature);entry['official_browser_cosine']=float(np.dot(feature,row['embedding'])) if row['embedding'] else None
 if detector is not None:
  faces=detector.detect(raster)[1];entry['opencv_face_count']=0 if faces is None else len(faces)
  if faces is not None and len(faces)==1:entry['detector_landmark_max_difference']=float(np.abs(faces[0][4:14].reshape(5,2)-landmarks).max())
 cv2.imwrite(str(folder/(row['file']+'.opencv-aligned.png')),crop)
 out.append(entry)
report={'reference':'Existing encoder with cv2.warpAffine and CPU ONNX Runtime; same browser raster and landmarks','opencv_version':cv2.__version__,'official_alignment_available':official is not None,'official_detector_available':detector is not None,'recognizer_input':[(x.name,x.shape,x.type) for x in encoder._session.get_inputs()],'recognizer_outputs':[(x.name,x.shape,x.type) for x in encoder._session.get_outputs()],'contract':data['contract'],'images':out}
p=Path('/tmp/ccc-recognition-development-reference-20261009.json');p.write_text(json.dumps(report,indent=2));p.chmod(0o600)
valid=[r['browser_reference_cosine'] for r in out if r['browser_reference_cosine'] is not None]
print(json.dumps({'images':len(out),'reference_parity_min':min(valid),'reference_parity_mean':sum(valid)/len(valid),'crop_pixel_mean_difference_max':max(r['crop_pixel_mean_difference'] for r in out),'official_alignment_available':official is not None,'opencv_detector_available':detector is not None},indent=2))
