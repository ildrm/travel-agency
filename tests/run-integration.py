#!/usr/bin/env python3
"""Run actual WP-CLI suites sequentially; retain all output and exit codes."""
from pathlib import Path
import json
import os
import subprocess
import sys
ROOT=Path(__file__).resolve().parents[1]
env=dict(os.environ,WP_CLI_CACHE_DIR=str(ROOT/'.runtime/cache'))
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
report={'executed_at':'2026-11-17','results':results}
(ROOT/'implementation/evidence/integration-results.json').write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
sys.exit(any(r['status']!='PASSED' for r in results))
