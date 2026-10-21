#!/usr/bin/env python3
"""Fake-camera video whose LEFT half is white and RIGHT half is black.

Used only to prove, from rendered pixels, which way the preview is flipped while the
frames handed to the model stay unflipped. Not a face image and not biometric evidence.
Usage: prepare_asymmetric_fixture.py [/tmp/ccc-public-asym.y4m]
"""
import sys
path=sys.argv[1] if len(sys.argv)>1 else '/tmp/ccc-public-asym.y4m'
w,h,frames=640,480,10
y=bytearray()
for _ in range(h):
    y+=bytes([235])*(w//2)+bytes([16])*(w//2)
chroma=bytes([128])*((w//2)*(h//2))
with open(path,'wb') as out:
    out.write(f'YUV4MPEG2 W{w} H{h} F10:1 Ip A1:1 C420jpeg\n'.encode())
    for _ in range(frames):
        out.write(b'FRAME\n');out.write(y);out.write(chroma);out.write(chroma)
print('wrote',path)
