"""Optional browser-test fixture generator; uses the preserved model Python venv.

This is test tooling only. The standalone application does not import cv2/numpy.
"""
import argparse
from pathlib import Path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('output', help='New external .y4m fixture file')
    args = parser.parse_args()
    import cv2
    import numpy as np
    root = Path(__file__).resolve().parents[2]
    image = cv2.imread(str(root / 'datasets/controlface10k-subset/000.png'))
    if image is None:
        raise SystemExit('Bundled synthetic fixture could not be read')
    image = cv2.resize(image, (480, 480), interpolation=cv2.INTER_AREA)
    frame = np.zeros((480, 640, 3), dtype=np.uint8)
    frame[:, 80:560] = image
    encoded = cv2.cvtColor(frame, cv2.COLOR_BGR2YUV_I420).tobytes()
    with Path(args.output).open('xb') as stream:
        stream.write(b'YUV4MPEG2 W640 H480 F10:1 Ip A1:1 C420jpeg\n')
        for _ in range(10):
            stream.write(b'FRAME\n' + encoded)
    print('Created test-only synthetic camera fixture')


if __name__ == '__main__':
    main()
