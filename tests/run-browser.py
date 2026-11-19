#!/usr/bin/env python3
"""Single-worker headless browser checks with runtime-ownership/build evidence."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import os
import re
import subprocess
import sys
import urllib.request
from build_inputs import build_hash, browser_hash

ROOT=Path(__file__).resolve().parents[1]
response=urllib.request.urlopen('http://127.0.0.1:8840/?lang=en',timeout=10)
if response.headers.get('X-Atlas-Local-Runtime')!=hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]:
    sys.exit('Refusing browser tests: port 8840 is not the owned Atlas runtime.')
product=build_hash()
before=browser_hash()
started=datetime.now(timezone.utc).isoformat()
env=dict(os.environ,WP_CLI_CACHE_DIR=str(ROOT/'.runtime/cache'))
env.pop('ATLAS_TEST_DB',None)
with (ROOT/'implementation/evidence/resource-browser-seed.log').open('w') as log:
    prepare=subprocess.run(['nice','-n','19','wp','--path='+str(ROOT/'.runtime/wordpress'),'eval-file',str(ROOT/'deploy/seed-resource-browser.php')],cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
if prepare.returncode:
    sys.exit('Could not prepare the fictional dated-stay browser fixture. See implementation/evidence/resource-browser-seed.log.')
command=['nice','-n','19','node',str(ROOT/'node_modules/@playwright/test/cli.js'),'test','--workers=1',*sys.argv[1:]]
result=subprocess.run(command,cwd=ROOT,env=env)
after=browser_hash()
results=ROOT/'implementation/evidence/browser-results.json'
full_run=all(re.fullmatch(r'--timeout=[1-9][0-9]*', value) for value in sys.argv[1:])
results_digest=hashlib.sha256(results.read_bytes()).hexdigest() if results.is_file() else None
report={'started_at':started,'finished_at':datetime.now(timezone.utc).isoformat(),
        'headless':True,'workers':1,'source_sha256':product,'browser_inputs_sha256':before,'source_unchanged_during_run':before==after,
        'results_sha256':results_digest,'full_run':full_run,
        'exit_code':result.returncode,'production_or_live_provider_test':False}
(ROOT/'implementation/evidence/browser-build.json').write_text(json.dumps(report,indent=2)+'\n')
sys.exit(result.returncode if before==after else 2)
