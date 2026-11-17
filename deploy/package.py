#!/usr/bin/env python3
"""Deterministic source ZIPs and scoped dependency inventory, not certification."""
from pathlib import Path
import base64
import hashlib
import json
import re
import uuid
from urllib.parse import quote
import zipfile

ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / 'dist'
INPUTS = ('package.json', 'package-lock.json', 'composer.json', 'composer.lock', 'deploy/runtime.lock.json', 'LICENSE')
SPDX_IDS = {'GPL-2.0-only', 'GPL-2.0-or-later', 'Apache-2.0', 'MPL-2.0', 'MIT', 'ISC', 'BSD-2-Clause', 'BSD-3-Clause', 'CC0-1.0'}
LICENSE_ALIASES = {'GPLv2 or later': 'GPL-2.0-or-later', 'GPL v2 or later': 'GPL-2.0-or-later'}


def digest(data):
    return hashlib.sha256(data).hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + '\n').encode('utf-8')


def read_json(relative):
    path = ROOT / relative
    return json.loads(path.read_text()) if path.exists() else {}


def props(**values):
    return [{'name': 'atlas:' + key, 'value': str(value)} for key, value in values.items()]


def license_ids(declaration):
    declarations = declaration if isinstance(declaration, list) else [declaration]
    known = set()
    for value in declarations:
        value = LICENSE_ALIASES.get(value, value) if isinstance(value, str) else None
        if value in SPDX_IDS:
            known.add(value)
    return [{'license': {'id': value}} for value in sorted(known)]


def collect_packages():
    """Snapshot owned package bytes, including the actual copied LICENSE."""
    release = str(read_json('package.json').get('version', '0.1.0'))
    packages = []
    for group in ('themes', 'plugins'):
        for directory in sorted((ROOT / 'wp-content' / group).glob('*')):
            if not directory.is_dir() or directory.is_symlink():
                continue
            main = directory / ('style.css' if group == 'themes' else directory.name + '.php')
            if not main.is_file():
                raise ValueError('Missing package entry point: ' + str(main.relative_to(ROOT)))
            header = main.read_text()[:12000]
            version = re.search(r'^\s*(?:\*\s*)?Version:\s*(\S+)', header, re.M)
            if not version or version.group(1) != release:
                raise ValueError('Package version differs from package.json: ' + str(main.relative_to(ROOT)))
            declaration = re.search(r'^\s*(?:\*\s*)?License:\s*([^\r\n]+)', header, re.M)
            files = []
            for path in sorted(directory.rglob('*')):
                if not path.is_file() or path.is_symlink():
                    continue
                if any(part in {'node_modules', 'vendor', '.git', '.DS_Store'} or part.startswith('.env') for part in path.relative_to(directory).parts):
                    continue
                files.append({'path': path.relative_to(directory.parent).as_posix(), 'source': path.relative_to(ROOT).as_posix(),
                              'origin': 'package-source', 'data': path.read_bytes()})
            if not any(file['path'] == directory.name + '/LICENSE' for file in files):
                files.append({'path': directory.name + '/LICENSE', 'source': 'LICENSE',
                              'origin': 'repository-license-copy', 'data': (ROOT / 'LICENSE').read_bytes()})
            packages.append({'name': directory.name, 'group': group, 'version': release, 'source': directory.relative_to(ROOT).as_posix(),
                             'license': declaration.group(1).strip() if declaration else '', 'files': sorted(files, key=lambda item: item['path'])})
    return packages


def build_sbom(packages):
    manifest = read_json('package.json')
    runtime = read_json('deploy/runtime.lock.json')
    locks = [{'path': relative, 'status': 'read', 'sha256': digest((ROOT / relative).read_bytes())}
             if (ROOT / relative).exists() else {'path': relative, 'status': 'missing'} for relative in INPUTS]
    components = []
    for package in packages:
        component = {'type': 'application', 'name': package['name'], 'version': package['version'], 'bom-ref': 'source:' + package['source'],
                     'properties': props(source_path=package['source'], delivery='owned-source-zip', license_declaration=package['license'] or 'not recorded in header')}
        licenses = license_ids(package['license'])
        if licenses:
            component['licenses'] = licenses
        components.append(component)
        for file in package['files']:
            components.append({'type': 'file', 'name': file['path'], 'bom-ref': 'file:' + package['group'] + '/' + file['path'],
                               'hashes': [{'alg': 'SHA-256', 'content': digest(file['data'])}],
                               'properties': props(source_path=file['source'], origin=file['origin'])})
    for package_path, item in sorted(read_json('package-lock.json').get('packages', {}).items()):
        if not package_path or 'node_modules/' not in package_path:
            continue
        name = item.get('name') or package_path.rsplit('node_modules/', 1)[1]
        version = str(item.get('version', 'unknown'))
        component = {'type': 'library', 'name': name, 'version': version, 'bom-ref': 'npm:' + package_path + '@' + version,
                     'purl': 'pkg:npm/' + quote(name, safe='/') + '@' + quote(version, safe=''),
                     'properties': props(source_lock='package-lock.json', package_path=package_path, role='development-tooling' if item.get('dev') else 'locked-dependency',
                                         bundled_in_wordpress_zips='false', license_declaration=item.get('license', 'not recorded'),
                                         hash_provenance='integrity declared in lock; generator did not fetch or verify upstream archive')}
        licenses = license_ids(item.get('license'))
        if licenses:
            component['licenses'] = licenses
        if item.get('resolved'):
            component['externalReferences'] = [{'type': 'distribution', 'url': item['resolved']}]
        for integrity in str(item.get('integrity', '')).split():
            algorithm, separator, value = integrity.partition('-')
            if separator and algorithm in {'sha256', 'sha384', 'sha512'}:
                try:
                    value = base64.b64decode(value, validate=True).hex()
                    if len(value) == {'sha256': 64, 'sha384': 96, 'sha512': 128}[algorithm]:
                        component.setdefault('hashes', []).append({'alg': 'SHA-' + algorithm[3:], 'content': value})
                except ValueError:
                    pass
        components.append(component)
    composer = read_json('composer.lock')
    for item in composer.get('packages', []) + composer.get('packages-dev', []):
        component = {'type': 'library', 'name': item['name'], 'version': str(item.get('version', 'unknown')),
                     'bom-ref': 'composer:' + item['name'] + '@' + str(item.get('version', 'unknown')),
                     'properties': props(source_lock='composer.lock', license_declaration=json.dumps(item.get('license', [])), bundled_in_wordpress_zips='not bundled by this generator')}
        licenses = license_ids(item.get('license', []))
        if licenses:
            component['licenses'] = licenses
        components.append(component)
    observed = runtime.get('observed_runtime', {})
    for name, version, url in [('wordpress', runtime.get('wordpress'), runtime.get('source_wordpress')),
                               ('woocommerce', runtime.get('woocommerce'), runtime.get('source_woocommerce')),
                               ('php', observed.get('php') or runtime.get('php_test_host'), None),
                               ('mariadb', observed.get('database') or runtime.get('database_test_host'), None)]:
        if not version:
            continue
        component = {'type': 'framework' if name in {'wordpress', 'woocommerce'} else 'platform', 'name': name, 'version': str(version), 'bom-ref': 'runtime:' + name + '@' + str(version),
                     'properties': props(source_lock='deploy/runtime.lock.json', delivery='external runtime, not bundled in ZIPs', license_status='not recorded in runtime lock; no SPDX ID guessed',
                                         vendor_transitive_dependencies='not inventoried', compatibility_status=runtime.get('status', 'not recorded'))}
        if url:
            component['externalReferences'] = [{'type': 'distribution', 'url': url}]
        components.append(component)
    fingerprint = digest(encoded({'locks': locks, 'components': components}))
    root_component = {'type': 'application', 'name': manifest.get('name', 'atlas-leisure-platform'), 'version': str(manifest.get('version', '0.1.0')), 'bom-ref': 'atlas-root'}
    licenses = license_ids(manifest.get('license'))
    if licenses:
        root_component['licenses'] = licenses
    return {'bomFormat': 'CycloneDX', 'specVersion': '1.6', 'version': 1,
            'serialNumber': 'urn:uuid:' + str(uuid.uuid5(uuid.NAMESPACE_URL, 'atlas-source-inventory:' + fingerprint)),
            'metadata': {'component': root_component, 'properties': props(status='development_artifacts_not_production_approved',
                purpose='scoped source/lock inventory, not certified SBOM or vulnerability/rights assessment', source_fingerprint_sha256=fingerprint,
                coverage='all package-lock entries, composer lock packages, owned source files and injected LICENSE',
                exclusions='full WP/Woo vendor dependency trees, browser binaries, OS/Homebrew packages, external services and complete dependency relationship graph not inventoried',
                vulnerability_status='not scanned by this generator; see separate executed audit evidence',
                license_status='known SPDX identifiers are declarations only, not legal/asset-rights clearance',
                input_manifest=json.dumps(locks, sort_keys=True), php_platform_constraint=json.dumps(composer.get('platform', {}), sort_keys=True))},
            'components': sorted(components, key=lambda item: item['bom-ref'])}


def write_sbom(packages):
    data = encoded(build_sbom(packages))
    target = ROOT / 'implementation/SBOM.json'
    target.parent.mkdir(parents=True, exist_ok=True)
    target.write_bytes(data)
    return data


def main():
    packages = collect_packages()
    sbom_data = write_sbom(packages)
    DIST.mkdir(exist_ok=True)
    (DIST / 'SBOM.json').write_bytes(sbom_data)
    artifacts = []
    for package in packages:
        filename = package['name'] + '-' + package['version'] + '.zip'
        files = []
        with zipfile.ZipFile(DIST / filename, 'w', compression=zipfile.ZIP_STORED) as archive:
            for source in package['files']:
                info = zipfile.ZipInfo(source['path'], date_time=(2026, 11, 17, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                archive.writestr(info, source['data'])
                files.append({'path': source['path'], 'source': source['source'], 'origin': source['origin'], 'sha256': digest(source['data']), 'bytes': len(source['data'])})
        artifacts.append({'file': filename, 'sha256': digest((DIST / filename).read_bytes()), 'bytes': (DIST / filename).stat().st_size, 'source_files': files})
    manifest = {'version': str(read_json('package.json').get('version', '0.1.0')), 'status': 'development_artifacts_not_production_approved',
                'sbom': {'file': 'SBOM.json', 'source': 'implementation/SBOM.json', 'sha256': digest(sbom_data), 'scope': 'scoped owned-source/lock inventory, not complete external vendor inventory'}, 'artifacts': artifacts}
    manifest_data = encoded(manifest)
    (DIST / 'manifest.json').write_bytes(manifest_data)
    sums = [(item['sha256'], item['file']) for item in artifacts] + [(digest(sbom_data), 'SBOM.json'), (digest(manifest_data), 'manifest.json')]
    (DIST / 'SHA256SUMS').write_text(''.join(value + '  ' + name + '\n' for value, name in sums))
    result = {'command': 'python3 deploy/package.py', 'status': 'archives_created_not_installed_or_production_approved', 'packages': len(artifacts),
              'source_files': sum(len(item['source_files']) for item in artifacts), 'files': [item['file'] for item in artifacts],
              'sbom_sha256': digest(sbom_data), 'manifest_sha256': digest(manifest_data),
              'limitations': 'One generator run does not establish reproducibility, installability, vulnerability status, legal rights or product completeness.'}
    evidence = ROOT / 'implementation/evidence/packaging-generation.json'
    evidence.parent.mkdir(parents=True, exist_ok=True)
    evidence.write_bytes(encoded(result))
    print(json.dumps(result, indent=2))


if __name__ == '__main__':
    main()
