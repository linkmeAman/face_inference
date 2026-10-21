"""Only use a public fixture, never personal enrollment images. Outputs stay in /tmp."""
import json
import sys
from pathlib import Path
import cv2
import numpy as np
ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0,str(ROOT/'services/face-inference'))
from app.encoder import SFaceEncoder
from app.detector import YuNetDetector
path = Path(sys.argv[1])
image = cv2.imread(str(path))
if image is None:
    raise SystemExit('Missing public image')
scale = min(640/image.shape[1],640/image.shape[0])
resized = cv2.resize(image,(round(image.shape[1]*scale),round(image.shape[0]*scale)))
padded = np.zeros((640,640,3),dtype=np.uint8)
padded[:resized.shape[0],:resized.shape[1]]=resized
detector=YuNetDetector(ROOT/'services/face-inference/models/face_detection_yunet_2023mar_int8.onnx')
faces=detector.detect(padded)
assert len(faces)==1
encoder=SFaceEncoder(ROOT/'services/face-inference/models/face_recognition_sface_2021dec_int8bq.onnx')
Path('/tmp/ccc-public-reference.json').write_text(json.dumps(encoder.encode(padded,faces[0]).tolist()))
cv2.imwrite('/tmp/ccc-public-blank.jpg',np.zeros_like(image))
cv2.imwrite('/tmp/ccc-public-multiple.jpg',np.concatenate([image,image],axis=1))
portrait=np.zeros((image.shape[0]+200,image.shape[1],3),dtype=np.uint8)
portrait[100:100+image.shape[0]]=image
cv2.imwrite('/tmp/ccc-public-portrait.jpg',portrait)
print('Prepared public reference, blank, multiple, portrait fixtures in /tmp')
def guided_frame(img):
    """Position public fixture faces as a user would within the camera guide.
    This changes test video framing only, never the model contract/evidence.
    """
    scale=min(640/img.shape[1],640/img.shape[0])
    scaled=cv2.resize(img,(round(img.shape[1]*scale),round(img.shape[0]*scale)))
    padded=np.zeros((640,640,3),dtype=np.uint8);padded[:scaled.shape[0],:scaled.shape[1]]=scaled
    detected=detector.detect(padded);assert len(detected)==1
    face=detected[0];box=face[:4]/scale;points=face[4:14].reshape(5,2)/scale
    target=np.array(json.loads((ROOT/'pf/js/browser-face/contract.json').read_text())['target_landmarks'])
    # Deterministic same similarity transform for fixture placement boundaries.
    s=points.mean(axis=0);t=target.mean(axis=0);q=points-s;r=target-t
    den=(q*q).sum();a=(q*r).sum()/den;b=(q[:,0]*r[:,1]-q[:,1]*r[:,0]).sum()/den
    matrix=np.array([[a,-b,t[0]-a*s[0]+b*s[1]],[b,a,t[1]-b*s[0]-a*s[1]]])
    inverse=cv2.invertAffineTransform(matrix)
    corners=cv2.transform(np.array([[[0.,0.],[111.,0.],[111.,111.],[0.,111.]]]),inverse)[0]
    cx=box[0]+box[2]/2;cy=box[1]+box[3]/2
    points=np.concatenate([points,corners,[[box[0],box[1]],[box[0]+box[2],box[1]+box[3]]]])
    fit=min(1.,(192*.85)/np.linalg.norm(points-[cx,cy],axis=1).max())
    assert min(box[2:])*fit>=80,'Fixture face must retain existing minimum resolution'
    return cv2.warpAffine(img,np.array([[fit,0,320-fit*cx],[0,fit,240-fit*cy]]),(640,480))
camera_image=guided_frame(image)
cv2.imwrite('/tmp/ccc-public-guided.png',camera_image)
height,width=camera_image.shape[:2]
with Path('/tmp/ccc-public-camera.y4m').open('wb') as stream:
    stream.write(f'YUV4MPEG2 W{width} H{height} F20:1 Ip A1:1 C420jpeg\n'.encode())
    pixels=cv2.cvtColor(camera_image,cv2.COLOR_BGR2YUV_I420).tobytes()
    import os
    static_frames=int(os.environ.get('FACE_FIXTURE_STATIC_FRAMES','20'));assert 1<=static_frames<=20
    for _ in range(static_frames):
        stream.write(b'FRAME\n'+pixels)
# Sequential identities exercise one uninterrupted camera with real inference.
public_paths=[Path('/tmp/ccc-public-'+name+'.jpg') for name in ('lena','obama','biden')]
if all(path.is_file() for path in public_paths):
    import os
    fps=int(os.environ.get('FACE_FIXTURE_FPS','5'));assert fps in [1,5]
    with Path('/tmp/ccc-public-mixed-camera.y4m').open('wb') as stream:
        stream.write(f'YUV4MPEG2 W640 H480 F{fps}:1 Ip A1:1 C420jpeg\n'.encode())
        for path in public_paths:
            img=cv2.imread(str(path))
            if path.stem.endswith('biden'):
                scale=min(640/img.shape[1],640/img.shape[0]);small=cv2.resize(img,(round(img.shape[1]*scale),round(img.shape[0]*scale)));detected=detector.detect(small);assert len(detected)==1
                bx,by,bw,bh=detected[0][:4]/scale;side=max(bw,bh)*3;cx=bx+bw/2;cy=by+bh/2
                img=img[max(0,int(cy-side/2)):min(img.shape[0],int(cy+side/2)),max(0,int(cx-side/2)):min(img.shape[1],int(cx+side/2))]
            frame=guided_frame(img)
            cv2.imwrite('/tmp/ccc-public-mixed-'+path.stem.removeprefix('ccc-public-')+'.png',frame)
            pixels=cv2.cvtColor(frame,cv2.COLOR_BGR2YUV_I420).tobytes()
            for _ in range(13*fps):stream.write(b'FRAME\n'+pixels)
            # A tracked arrival leaves before the next child enters. Abrupt
            # same-position image cuts do not exercise departure/rearming.
            blank=cv2.cvtColor(np.zeros_like(frame),cv2.COLOR_BGR2YUV_I420).tobytes()
            for _ in range(2*fps):stream.write(b'FRAME\n'+blank)
    print('Prepared sequential public-photo camera; not live calibration')
