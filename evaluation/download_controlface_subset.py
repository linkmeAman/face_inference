"""Bounded range download from the author's pinned CC BY 4.0 ZIP; no full archive."""
import argparse, collections, hashlib, json, os, struct, urllib.request, zlib
from pathlib import Path
URL='https://huggingface.co/datasets/HuMInGameLab/ControlFace10K/resolve/a03589de1a9e028b2d16fa1eb0e019a6930e817c/controlface10k.zip'
SIZE=3137641968
REVISION='a03589de1a9e028b2d16fa1eb0e019a6930e817c'

def fetch(start,end):
    req=urllib.request.Request(URL+f'?range_start={start}',headers={'Range':f'bytes={start}-{end}'})
    with urllib.request.urlopen(req,timeout=60) as response:
        expected=f'bytes {start}-{end}/{SIZE}'
        if response.status!=206 or response.headers.get('Content-Range')!=expected:
            raise ValueError('Server ignored range; refusing full download')
        data=response.read(end-start+2)
        if len(data)!=end-start+1:raise ValueError('Truncated or excessive range')
        return data

def index(data):
    entries=[];offset=0
    while offset<len(data):
        v=struct.unpack_from('<4s6H3I5H2I',data,offset)
        if v[0]!=b'PK\x01\x02':raise ValueError('Invalid central directory')
        name=data[offset+46:offset+46+v[10]].decode('utf8')
        entries.append(dict(name=name,method=v[4],crc=v[7],compressed=v[8],size=v[9],offset=v[16]))
        offset+=46+v[10]+v[11]+v[12]
    return entries

def select(entries):
    groups=collections.defaultdict(list)
    for row in entries:
        if row['name'].endswith('.png'):
            identity='/'.join(row['name'].split('/')[:-1]);groups[identity].append(row)
    strata=collections.defaultdict(list)
    for identity,rows in groups.items():
        if len(rows)==3:strata['/'.join(identity.split('/')[:-1])].append((identity,rows))
    selected=[]
    for stratum,identities in sorted(strata.items()):
        for position,(identity,rows) in enumerate(sorted(identities)[:2]):
            for image,row in enumerate(sorted(rows,key=lambda r:r['name'])):
                selected.append(dict(row,identity=identity,stratum=stratum,partition='development' if position==0 else 'heldout',view=image))
    return selected

def main():
    parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);parser.add_argument('--index');args=parser.parse_args()
    out=Path(args.output).resolve();root=Path(__file__).resolve().parents[1]
    if out==root or root in out.parents:raise ValueError('Data must be outside web repository')
    os.umask(0o077);out.mkdir(mode=0o700,exist_ok=True)
    tail=fetch(SIZE-100000,SIZE-1);end=tail.rfind(b'PK\x05\x06');record=struct.unpack_from('<4s4H2IH',tail,end)
    directory=Path(args.index).read_bytes() if args.index else fetch(record[6],record[6]+record[5]-1)
    selected=select(index(directory))
    if len(selected)>200 or sum(r['compressed']+1024 for r in selected)>80*1024*1024:raise ValueError('Subset exceeds bounded budget')
    downloaded=0;manifest=[]
    for number,row in enumerate(selected):
        path=out/f'{number:03d}.png'
        if not path.exists():
            header=fetch(row['offset'],row['offset']+29)
            v=struct.unpack('<4s5H3I2H',header)
            if v[0]!=b'PK\x03\x04':raise ValueError('Invalid local header')
            start=row['offset']+30+v[9]+v[10];data=fetch(start,start+row['compressed']-1);downloaded+=len(data)+30
            raw=zlib.decompress(data,-15) if row['method']==8 else data if row['method']==0 else None
            if raw is None or len(raw)!=row['size'] or zlib.crc32(raw)!=row['crc']:raise ValueError('Archive integrity mismatch')
            path.write_bytes(raw)
        raw=path.read_bytes()
        if len(raw)!=row['size'] or zlib.crc32(raw)!=row['crc']:raise ValueError('Cached file changed')
        manifest.append({k:row[k] for k in ('identity','stratum','partition','view','name')}|{'file':path.name,'sha256':hashlib.sha256(raw).hexdigest(),'bytes':len(raw)})
        print(f'Fetched {number+1}/{len(selected)}',flush=True)
    (out/'manifest.json').write_text(json.dumps({'dataset':'HuMInGameLab/ControlFace10K','revision':REVISION,'license':'CC-BY-4.0','source':URL,'attribution':'Kassi Nzalasse, Rishav Raj, Eli Laird, Corey Clark — SIG (2024), arXiv:2409.08345; subset selection only, images unchanged. https://creativecommons.org/licenses/by/4.0/','synthetic':True,'downloaded_image_bytes_this_run':downloaded,'images':manifest},indent=2))
if __name__=='__main__':main()
