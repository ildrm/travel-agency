#!/usr/bin/env python3
"""Run actual WP-CLI suites sequentially; retain all output and exit codes."""
from pathlib import Path
import json
import os
import subprocess
import sys
import hashlib
from datetime import datetime, timezone
ROOT=Path(__file__).resolve().parents[1]
env=dict(os.environ,WP_CLI_CACHE_DIR=str(ROOT/'.runtime/cache'),ATLAS_TEST_DB='atlas_integration')
def source_hash():
    digest=hashlib.sha256()
    paths=list((ROOT/'wp-content').rglob('*'))+list((ROOT/'tests').rglob('*.php'))
    for path in sorted(set(paths)):
        if path.is_file():
            digest.update(str(path.relative_to(ROOT)).encode()+b'\0'+path.read_bytes()+b'\0')
    return digest.hexdigest()
prepared=subprocess.run([sys.executable,str(ROOT/'deploy/prepare-test-runtime.py')],cwd=ROOT)
if prepared.returncode:sys.exit('Could not prepare the isolated integration database.')
build_before=source_hash()
results=[]
for name in ['core-integration','core-woo-integration','domain-integration','gateway-integration','frontend-integration','inventory-integration']:
    script=ROOT/'tests'/(name+'.php')
    if not script.exists():
        results.append({'suite':name,'exit_code':None,'status':'MISSING'})
        continue
    args=['nice','-n','19','wp','--path='+str(ROOT/'.runtime/wordpress'),'eval-file',str(script)]
    with (ROOT/'implementation/evidence'/(name+'.log')).open('w') as log:
        result=subprocess.run(args,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
    results.append({'suite':name,'exit_code':result.returncode,'status':'PASSED' if result.returncode==0 else 'FAILED',
                    'command':' '.join(args),'log':'implementation/evidence/'+name+'.log'})
build_after=source_hash()
report={'executed_at':datetime.now(timezone.utc).isoformat(),'source_sha256':build_before,
        'database':'atlas_integration','source_unchanged_during_run':build_before==build_after,'results':results}
(ROOT/'implementation/evidence/integration-results.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
sys.exit(build_before!=build_after or any(r['status']!='PASSED' for r in results))
