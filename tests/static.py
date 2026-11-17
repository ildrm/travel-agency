#!/usr/bin/env python3
"""Sequential syntax, source schema and packaging safety checks."""
from pathlib import Path
import json
import subprocess
import sys
import re

ROOT=Path(__file__).resolve().parents[1]
checks=[]
def record(name,ok,detail=''):
    checks.append({'name':name,'passed':bool(ok),'detail':detail})
for path in sorted((ROOT/'wp-content').rglob('*.php')):
    result=subprocess.run(['php','-l',str(path)],capture_output=True,text=True)
    record('php-lint:'+str(path.relative_to(ROOT)),result.returncode==0,result.stdout+result.stderr)
for path in sorted((ROOT/'wp-content').rglob('*.json')):
    try:
        json.loads(path.read_text())
        record('json:'+str(path.relative_to(ROOT)),True)
    except Exception as error:
        record('json:'+str(path.relative_to(ROOT)),False,str(error))
for path in sorted((ROOT/'wp-content').rglob('*.js')):
    result=subprocess.run(['node','--check',str(path)],capture_output=True,text=True)
    record('js-syntax:'+str(path.relative_to(ROOT)),result.returncode==0,result.stdout+result.stderr)
for path in sorted((ROOT/'wp-content').rglob('*.svg')):
    import xml.etree.ElementTree as ET
    try:
        tree=ET.parse(path)
        unsafe=any('script'==e.tag.split('}')[-1].lower() or any(k.lower().startswith('on') for k in e.attrib) for e in tree.iter())
        record('svg:'+str(path.relative_to(ROOT)),not unsafe)
    except Exception as error:
        record('svg:'+str(path.relative_to(ROOT)),False,str(error))
for path in sorted((ROOT/'wp-content').rglob('*')):
    if path.is_file() and path.suffix in {'.php','.js','.json','.css','.html'}:
        data=path.read_text()
        record('secret-pattern:'+str(path.relative_to(ROOT)),not bool(re.search(r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----|AKIA[0-9A-Z]{16}',data)))
theme=ROOT/'wp-content/themes/atlas-leisure'
record('block-theme-required-files',all((theme/x).exists() for x in ['style.css','theme.json','templates/index.html']))
result={'suite':'static','executed_at':'2026-11-17','checks':checks,'passed':sum(c['passed'] for c in checks),'failed':sum(not c['passed'] for c in checks)}
out=ROOT/'implementation/evidence/static-results.json'
out.write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps({k:v for k,v in result.items() if k!='checks'},indent=2))
for check in checks:
    if not check['passed']: print(check)
sys.exit(bool(result['failed']))
