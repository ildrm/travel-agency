#!/usr/bin/env python3
"""Deterministic, CPU-light ZIP packaging of owned WordPress source only."""
from pathlib import Path
import hashlib
import json
import zipfile

ROOT=Path(__file__).resolve().parents[1]
DIST=ROOT/'dist'
DIST.mkdir(exist_ok=True)
artifacts=[]
for group in ['themes','plugins']:
    for package in sorted((ROOT/'wp-content'/group).glob('*')):
        if not package.is_dir():
            continue
        filename=package.name+'-0.1.0.zip'
        files=[]
        with zipfile.ZipFile(DIST/filename,'w',compression=zipfile.ZIP_STORED) as archive:
            for path in sorted(package.rglob('*')):
                if not path.is_file() or path.is_symlink() or any(p in {'node_modules','vendor','.git','.env'} for p in path.parts):
                    continue
                relative=path.relative_to(package.parent).as_posix()
                data=path.read_bytes()
                info=zipfile.ZipInfo(relative,date_time=(2026,11,17,0,0,0))
                info.external_attr=0o100644<<16
                archive.writestr(info,data)
                files.append({'path':relative,'sha256':hashlib.sha256(data).hexdigest()})
            if not (package/'LICENSE').exists():
                info=zipfile.ZipInfo(package.name+'/LICENSE',(2026,11,17,0,0,0))
                info.external_attr=0o100644<<16
                archive.writestr(info,(ROOT/'LICENSE').read_bytes())
        digest=hashlib.sha256((DIST/filename).read_bytes()).hexdigest()
        artifacts.append({'file':filename,'sha256':digest,'bytes':(DIST/filename).stat().st_size,'source_files':files})
(DIST/'manifest.json').write_text(json.dumps({'version':'0.1.0','status':'development_artifacts_not_production_approved','artifacts':artifacts},indent=2)+'\n')
(DIST/'SHA256SUMS').write_text(''.join(a['sha256']+'  '+a['file']+'\n' for a in artifacts))
print(json.dumps({'packages':len(artifacts),'files':[a['file'] for a in artifacts]},indent=2))
