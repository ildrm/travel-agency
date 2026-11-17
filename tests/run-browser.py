#!/usr/bin/env python3
"""Single-worker headless browser checks with runtime-ownership/build evidence."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import os
import subprocess
import sys
import urllib.request

ROOT=Path(__file__).resolve().parents[1]
def fingerprint():
    digest=hashlib.sha256()
    for path in sorted((ROOT/'wp-content').rglob('*')):
        if path.is_file():digest.update(str(path.relative_to(ROOT)).encode()+b'\0'+path.read_bytes()+b'\0')
    return digest.hexdigest()
response=urllib.request.urlopen('http://127.0.0.1:8840/?lang=en',timeout=10)
if response.headers.get('X-Atlas-Local-Runtime')!=hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]:
    sys.exit('Refusing browser tests: port 8840 is not the owned Atlas runtime.')
before=fingerprint()
started=datetime.now(timezone.utc).isoformat()
command=['nice','-n','19','node',str(ROOT/'node_modules/@playwright/test/cli.js'),'test','--workers=1',*sys.argv[1:]]
result=subprocess.run(command,cwd=ROOT,env=os.environ)
after=fingerprint()
report={'started_at':started,'finished_at':datetime.now(timezone.utc).isoformat(),
        'headless':True,'workers':1,'source_sha256':before,'source_unchanged_during_run':before==after,
        'exit_code':result.returncode,'production_or_live_provider_test':False}
(ROOT/'implementation/evidence/browser-build.json').write_text(json.dumps(report,indent=2)+'\n')
sys.exit(result.returncode if before==after else 2)
