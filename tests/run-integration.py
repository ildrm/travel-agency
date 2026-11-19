#!/usr/bin/env python3
"""Run actual WP-CLI suites sequentially; retain all output and exit codes."""
from pathlib import Path
import json
import os
import subprocess
import sys
import re
from datetime import datetime, timezone
from build_inputs import build_hash, integration_hash
ROOT=Path(__file__).resolve().parents[1]
env=dict(os.environ,WP_CLI_CACHE_DIR=str(ROOT/'.runtime/cache'),ATLAS_TEST_DB='atlas_integration')
prepared=subprocess.run([sys.executable,str(ROOT/'deploy/prepare-test-runtime.py')],cwd=ROOT)
if prepared.returncode:sys.exit('Could not prepare the isolated integration database.')
build_before=integration_hash()
product_before=build_hash()
results=[]
baseline=['core-integration','core-woo-integration','domain-integration','gateway-integration','frontend-integration','inventory-integration']
extensions=sorted(path.stem for path in (ROOT/'tests').glob('*-integration.php') if path.stem not in baseline)
for name in baseline+extensions:
    script=ROOT/'tests'/(name+'.php')
    if not script.exists():
        results.append({'suite':name,'exit_code':None,'status':'MISSING'})
        continue
    args=['nice','-n','19','wp','--path='+str(ROOT/'.runtime/wordpress'),'eval-file',str(script)]
    with (ROOT/'implementation/evidence'/(name+'.log')).open('w') as log:
        result=subprocess.run(args,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
    summary={'suite':name,'exit_code':result.returncode,'status':'PASSED' if result.returncode==0 else 'FAILED',
             'command':' '.join(args),'log':'implementation/evidence/'+name+'.log'}
    explicit=re.findall(r'\b(\d+) passed, (\d+) failed\b',(ROOT/summary['log']).read_text())
    if explicit:
        summary['assertions_passed'],summary['assertions_failed']=map(int,explicit[-1])
    results.append(summary)
for script in sorted((ROOT/'tests').glob('*.mjs')):
    args=['nice','-n','19','node',str(script)]
    log_path=ROOT/'implementation/evidence'/(script.stem+'.log')
    with log_path.open('w') as log:
        result=subprocess.run(args,cwd=ROOT,env=env,stdout=log,stderr=subprocess.STDOUT)
    summary={'suite':script.stem,'exit_code':result.returncode,'status':'PASSED' if result.returncode==0 else 'FAILED',
             'command':' '.join(args),'log':log_path.relative_to(ROOT).as_posix()}
    explicit=re.findall(r'\b(\d+) passed, (\d+) failed\b',log_path.read_text())
    if explicit:
        summary['assertions_passed'],summary['assertions_failed']=map(int,explicit[-1])
    results.append(summary)
build_after=integration_hash()
report={'executed_at':datetime.now(timezone.utc).isoformat(),'source_sha256':build_before,
        'product_sha256':product_before,'database':'atlas_integration','source_unchanged_during_run':build_before==build_after and product_before==build_hash(),'results':results}
(ROOT/'implementation/evidence/integration-results.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
sys.exit(not report['source_unchanged_during_run'] or any(r['status']!='PASSED' for r in results))
