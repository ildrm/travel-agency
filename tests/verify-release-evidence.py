#!/usr/bin/env python3
"""Match local test and installation evidence to the available release bytes."""
from datetime import datetime, timezone
from pathlib import Path
import hashlib
import json
import zipfile
from build_inputs import build_hash, browser_hash, integration_hash

ROOT = Path(__file__).resolve().parents[1]
EVIDENCE = ROOT / 'implementation/evidence'
DIST = ROOT / 'dist'


def load(path):
    return json.loads(path.read_text())


def sha(data):
    return hashlib.sha256(data).hexdigest()


def verify():
    static = load(EVIDENCE / 'static-results.json')
    integration = load(EVIDENCE / 'integration-results.json')
    browser = load(EVIDENCE / 'browser-build.json')
    browser_stats = load(EVIDENCE / 'browser-results.json')['stats']
    rebuild = load(EVIDENCE / 'package-reproducibility.json')
    install = load(EVIDENCE / 'clean-install-results.json')
    recovery = load(EVIDENCE / 'recovery-results.json')
    manifest = load(DIST / 'manifest.json')
    current = build_hash()
    artifact_hashes = {item['file']: item['sha256'] for item in manifest['artifacts']}

    checks = {
        'same_product_hash': current == static['source_sha256'] == integration['product_sha256'] == browser['source_sha256'],
        'static_all_passed': static['failed'] == 0,
        'same_test_inputs': integration['source_sha256']==integration_hash()
        and browser['browser_inputs_sha256']==browser_hash()
        and browser['results_sha256']==sha((EVIDENCE/'browser-results.json').read_bytes()),
        'each_integration_suite_passed': integration['source_unchanged_during_run'] and integration['database'] == 'atlas_integration'
        and bool(integration['results']) and all(item['status'] == 'PASSED' for item in integration['results']),
        'full_headless_browser_run_passed': browser['source_unchanged_during_run'] and browser['headless'] and browser['workers'] == 1
        and browser['full_run'] and browser['exit_code'] == 0 and browser_stats['expected'] >= 13
        and not any(browser_stats.get(key, 0) for key in ('unexpected', 'flaky', 'skipped')),
        'reproducible_archive_set': rebuild['passed'] and rebuild['files_compared'] == len(artifact_hashes) + 3
        and all(rebuild['second_sha256'].get(name) == digest for name, digest in artifact_hashes.items()),
        'actual_archive_install': install['passed'] and install['artifacts_sha256'] == artifact_hashes,
        'isolated_database_recovery': recovery['passed'] and recovery['restored_counts_match'] and recovery['restored_logical_checksums_match']
        and recovery['package_manifest_sha256']==sha((DIST/'manifest.json').read_bytes()),
    }

    source_files_match = True
    for item in manifest['artifacts']:
        path = DIST / item['file']
        source_files_match &= sha(path.read_bytes()) == item['sha256']
        with zipfile.ZipFile(path) as archive:
            source_files_match &= sorted(archive.namelist()) == sorted(file['path'] for file in item['source_files'])
            for file in item['source_files']:
                source = (ROOT / file['source']).read_bytes()
                source_files_match &= file['sha256'] == sha(source) == sha(archive.read(file['path']))
    checks['archive_contents_match_workspace'] = source_files_match
    return {
        'verified_at_utc': datetime.now(timezone.utc).isoformat(),
        'release_version': manifest['version'],
        'product_sha256': current,
        'integration_sha256': integration['source_sha256'],
        'browser_inputs_sha256': browser['browser_inputs_sha256'],
        'checks': checks,
        'passed': all(checks.values()),
        'static_checks': static['passed'],
        'integration_suites': len(integration['results']),
        'browser_tests': browser_stats['expected'],
        'zip_archives': artifact_hashes,
        'restored_tables': recovery['table_count'],
        'restored_rows': recovery['row_count'],
        'scope': 'local development release evidence',
    }


if __name__ == '__main__':
    report = verify()
    output = json.dumps(report, indent=2) + '\n'
    (EVIDENCE / 'development-release.json').write_text(output)
    print(output, end='')
    raise SystemExit(0 if report['passed'] else 1)
