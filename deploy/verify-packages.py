#!/usr/bin/env python3
"""Build twice, comparing all delivery bytes without claiming installability."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
report={'started_at':datetime.now(timezone.utc).isoformat(),'passed':False,
        'scope':'two sequential deterministic package generations; install checked separately'}

def build():
    result=subprocess.run([sys.executable,str(ROOT/'deploy/package.py')],cwd=ROOT,capture_output=True,text=True)
    if result.returncode: raise RuntimeError(result.stderr or result.stdout)
    manifest=json.loads((ROOT/'dist/manifest.json').read_text())
    names=[a['file'] for a in manifest['artifacts']]+['SBOM.json','manifest.json','SHA256SUMS']
    return {name:hashlib.sha256((ROOT/'dist'/name).read_bytes()).hexdigest() for name in names}

try:
    first=build()
    second=build()
    report.update({'first_sha256':first,'second_sha256':second,'passed':first==second,
                   'files_compared':len(first)})
except Exception as error:
    report['error']=str(error)
report['finished_at']=datetime.now(timezone.utc).isoformat()
(ROOT/'implementation/evidence/package-reproducibility.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
raise SystemExit(0 if report['passed'] else 1)
