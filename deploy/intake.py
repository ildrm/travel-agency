#!/usr/bin/env python3
"""Reproducible document inventory/extraction; never equates a mention with implementation."""
from pathlib import Path
import ast
import csv
import hashlib
import json
import re
ROOT=Path(__file__).resolve().parents[1]
OUT=ROOT/'implementation'
rows=[]
evidence=[]
for path in sorted((ROOT/'docs').rglob('*')):
    if not path.is_file(): continue
    raw=path.read_bytes()
    name=path.relative_to(ROOT).as_posix()
    status='READ'
    try:
        text=raw.decode('utf-8')
        if path.suffix=='.json': json.loads(text)
        elif path.suffix=='.py': ast.parse(text)
    except Exception as error:
        status='UNREADABLE'; text=''; print(name,error)
    title=next((line.lstrip('# ').strip() for line in text.splitlines() if line.startswith('# ')),path.name)
    purpose=re.search(r'(?im)^\*?\*?Purpose[:* ]+([^\n]+)',text)
    if name.startswith('docs/DOC-'):
        n=int(path.name[4:6]); reader='root' if n<=6 or n>=41 else 'frontend' if n<=18 else 'backend' if n<=28 else 'domains'
        method='full-text team review (see intake/'+reader+'.md)' if reader!='root' else 'root full-text review'
    else:
        method='supporting text/source review' if path.suffix=='.md' else 'full machine parse; supporting registry/code'
    refs=sorted(set(re.findall(r'DOC-\d{2}',text)))
    rows.append([name,hashlib.sha256(raw).hexdigest(),len(raw),path.suffix,title,
                 (purpose.group(1) if purpose else 'Supporting source/registry/audit'),status,method,','.join(refs)])
    heading=''
    for line_no,line in enumerate(text.splitlines(),1):
        if line.startswith('#'): heading=line.lstrip('# ')
        identifiers=sorted(set(re.findall(r'\b(?:FR|NFR|UI|API|WF|ENT|TC|ADR|RISK)-[A-Z0-9-]+',line)))
        if identifiers:
            evidence.append({'path':name,'section':heading,'line':line_no,'IDs':identifiers,'source_text':line})
inventory='# Recursive documentation inventory\n\nBaseline: 2026-11-17. '+str(len(rows))+' files; '+str(sum(r[6]=='UNREADABLE' for r in rows))+' unreadable. All 49 DOC specifications received full-text team intake; supporting registries were machine-parsed. Human approval is not inferred from READ. No PDF/spreadsheet/image attachment exists under docs. SHA-256 covers exact bytes, including archived source. Team evidence: intake/backend.md, frontend.md, domains.md; root reviewed DOC-00–06 and DOC-41–48, authoring/source/validation context.\n\n| Path | SHA-256 | Bytes | Format | Title | Purpose | Status | Review method | Cross references |\n|---|---|---:|---|---|---|---|---|---|\n'
inventory+=''.join('| '+' | '.join(str(c).replace('|','/').replace('\n',' ') for c in r)+' |\n' for r in rows)
(OUT/'00-docs-inventory.md').write_text(inventory)
(OUT/'documentation-evidence-index.json').write_text(json.dumps(evidence,ensure_ascii=False,indent=2)+'\n')
headers=['requirement_id','document_path','section/cell','verbatim_or_paraphrased_requirement','priority','owner','module','UI_screen','data_entity','service/API','code_path','tests','implementation_status','test_status','acceptance_evidence','open_issue']
trs=[]
srs=(ROOT/'docs/DOC-07_BRD-SRS.md').read_text()
trace=(ROOT/'docs/DOC-46_TRACEABILITY-MATRIX.md').read_text()
mapping={}
for line in trace.splitlines():
    if re.match(r'\| (?:FR|NFR)-',line):
        cells=[c.strip() for c in line.strip('|').split('|')]
        mapping[cells[0]]=cells
for line_no,line in enumerate(srs.splitlines(),1):
    if not re.match(r'\| (?:FR|NFR)-',line): continue
    cells=[c.strip() for c in line.strip('|').split('|')]
    rid=cells[0]
    if any(r[0]==rid for r in trs): continue
    m=mapping.get(rid,[])
    family=rid.split('-')[1]
    code='wp-content/plugins/atlas-core' if family in {'ID','COM','PAY','DATA','SEC','CONC','PRIV','OBS','REL'} else 'wp-content/themes/atlas-leisure' if family in {'THEME','ART','I18N','LOC','A11Y','PERF','MAINT','CMS'} else 'wp-content/plugins/atlas-operations'
    if family in {'PAY','CRYPTO'}: code+=';wp-content/plugins/atlas-gateways'
    if family in {'STAY','RENT'}: code+=';wp-content/plugins/atlas-inventory'
    trs.append([rid,'docs/DOC-07_BRD-SRS.md',f'line {line_no}',cells[-1],cells[1] if rid.startswith('FR-') else 'release quality gate',m[-1] if m else 'Assigned reviewer',family,m[1] if len(m)>1 else '',m[2] if len(m)>2 else '',m[3] if len(m)>3 else '',code,m[4] if len(m)>4 else '', 'IN_PROGRESS','NOT_RUN','', 'Detailed feature coverage/review pending; do not infer completion from code path'])
with (OUT/'01-requirements-traceability.csv').open('w',newline='') as out:
    writer=csv.writer(out);writer.writerow(headers);writer.writerows(trs)
coverage='# Feature coverage and implementation ownership\n\nThis baseline is IN_PROGRESS. A module is complete only after its full fields/workflows/ACL/UI/persistence/negative tests pass. The evidence-index JSON preserves per-document sections and IDs, including field/edge-case source rows, for detailed follow-up. Proposed test IDs are not executed test evidence.\n\n| Requirement | Source | Ownership/code | Planned verification | Status |\n|---|---|---|---|---|\n'
coverage+=''.join(f'| {r[0]} | {r[1]}:{r[2]} | {r[10]} | {r[11]} | IN_PROGRESS |\n' for r in trs)
coverage+='\nMini-RACI: module implementer owns code; Product Owner reviews scope and customer behavior; security reviewer reviews ACL/data; QA reviews actual evidence. Payments/finance require actual provider+finance+legal signoff; local inventory requires agency/supplier/operations signoff; art/locale requires brand/native editor; no human approvals are claimed. All requested disciplines are mapped in DOC-04 and must be represented in the final review.\n'
(OUT/'03-feature-coverage-matrix.md').write_text(coverage)
print(json.dumps({'files':len(rows),'unreadable':sum(r[6]=='UNREADABLE' for r in rows),'requirements':len(trs),'evidence_rows':len(evidence)},indent=2))
