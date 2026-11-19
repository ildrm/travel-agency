"""Fingerprints for candidate code and the test inputs used to verify it."""
import hashlib
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def build_hash(extra=()):
    digest = hashlib.sha256()
    paths = set((ROOT / 'wp-content').rglob('*'))
    paths.update(extra)
    for path in sorted(paths):
        if path.is_file():
            digest.update(str(path.relative_to(ROOT)).encode() + b'\0' + path.read_bytes() + b'\0')
    return digest.hexdigest()


def integration_hash():
    tests = ROOT / 'tests'
    return build_hash([
        *tests.rglob('*.php'), *tests.rglob('*.mjs'),
        tests / 'build_inputs.py', tests / 'run-integration.py',
        ROOT / 'deploy/prepare-test-runtime.py', ROOT / 'deploy/runtime.lock.json',
    ])


def browser_hash():
    tests = ROOT / 'tests'
    return build_hash([
        *(tests / 'browser').rglob('*.cjs'), tests / 'build_inputs.py',
        tests / 'run-browser.py', ROOT / 'playwright.config.cjs',
        ROOT / 'package-lock.json', ROOT / 'deploy/seed-resource-browser.php',
        ROOT / 'deploy/seed.php', ROOT / 'deploy/runtime.lock.json',
    ])
