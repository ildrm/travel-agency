#!/usr/bin/env python3
"""Restore the isolated local DB to a new disposable DB and compare row counts.

No existing database is overwritten. This is a local rehearsal, not evidence of
reconciling actual provider operations after a production disaster.
"""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import re
import subprocess
import uuid

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=ROOT/'.runtime'
SOCKET=RUNTIME/'db.sock'
SOURCE='atlas_local'
TARGET='atlas_restore_'+uuid.uuid4().hex[:12]
EVIDENCE=ROOT/'implementation/evidence/recovery-results.json'

def sql(query, database=None):
    args=['nice','-n','19','mariadb','--socket='+str(SOCKET),'-u','root','-N','-B']
    if database: args.append(database)
    return subprocess.run(args,input=query,text=True,capture_output=True,check=True).stdout

def counts(database):
    if not re.fullmatch(r'atlas_(?:local|restore_[a-f0-9]{12})',database):
        raise RuntimeError('Unexpected database name')
    tables=sql("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='"+database+"' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME;").splitlines()
    for table in tables:
        if not re.fullmatch(r'[a-zA-Z0-9_]+',table): raise RuntimeError('Unexpected table name')
    queries='\n'.join("SELECT '"+table+"',COUNT(*) FROM `"+database+"`.`"+table+"`;" for table in tables)
    return dict((row.split('\t')[0],int(row.split('\t')[1])) for row in sql(queries).splitlines())

def checksums(database, tables):
    # MariaDB's logical row checksums compare restored content without exporting
    # personal fields into evidence. Table names were validated by counts().
    query='CHECKSUM TABLE '+','.join('`'+database+'`.`'+table+'`' for table in tables)+' EXTENDED;'
    rows=sql(query).splitlines()
    result={row.split('\t')[0].split('.')[-1]:row.split('\t')[1] for row in rows}
    if set(result)!=set(tables) or any(value=='NULL' for value in result.values()):
        raise RuntimeError('Logical checksum unavailable for one or more tables')
    return result

started=datetime.now(timezone.utc)
report={'started_at':started.isoformat(),'source':SOURCE,'restore_target':TARGET,
        'scope':'isolated local restore; no production/provider reconciliation performed','passed':False}
manifest=ROOT/'dist/manifest.json'
dump=RUNTIME/'restore-rehearsal.sql'
created=False
try:
    build=hashlib.sha256(manifest.read_bytes()).hexdigest()
    report['package_manifest_sha256']=build
    before=counts(SOURCE)
    if not before: raise RuntimeError('Local source database is empty or unavailable')
    checksum_before=checksums(SOURCE, before)
    with dump.open('w') as out:
        subprocess.run(['nice','-n','19','mariadb-dump','--socket='+str(SOCKET),'-u','root',
                        '--single-transaction','--skip-comments','--skip-add-drop-table',SOURCE],stdout=out,check=True)
    dump.chmod(0o600)
    sql('CREATE DATABASE `'+TARGET+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    created=True
    with dump.open() as source:
        subprocess.run(['nice','-n','19','mariadb','--socket='+str(SOCKET),'-u','root',TARGET],stdin=source,check=True)
    restored=counts(TARGET)
    checksum_restored=checksums(TARGET, restored)
    after=counts(SOURCE)
    checksum_after=checksums(SOURCE, after)
    report.update({'table_count':len(before),'row_count':sum(before.values()),
                   'source_unchanged_during_rehearsal':before==after and checksum_before==checksum_after,
                   'restored_counts_match':before==restored,
                   'restored_logical_checksums_match':checksum_before==checksum_restored,
                   'dump_sha256':hashlib.sha256(dump.read_bytes()).hexdigest(),
                   'dump_bytes':dump.stat().st_size,
                   'passed':before==after==restored and checksum_before==checksum_after==checksum_restored
                   and build==hashlib.sha256(manifest.read_bytes()).hexdigest()})
except Exception as error:
    report['error']=str(error)
finally:
    # Only the unpredictable DB created by this invocation is removed.
    if created:
        sql('DROP DATABASE `'+TARGET+'`;')
    report['finished_at']=datetime.now(timezone.utc).isoformat()
    report['elapsed_seconds']=(datetime.now(timezone.utc)-started).total_seconds()
    EVIDENCE.write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report,indent=2))
raise SystemExit(0 if report['passed'] else 1)
