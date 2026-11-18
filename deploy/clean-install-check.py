#!/usr/bin/env python3
"""Install actual ZIPs into a separate WP path/database; no source symlinks for owned packages."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import os
import secrets
import shutil
import subprocess
import uuid

ROOT=Path(__file__).resolve().parents[1]
RUNTIME=ROOT/'.runtime'
SOCKET=RUNTIME/'db.sock'
SUFFIX=uuid.uuid4().hex[:10]
DATABASE='atlas_clean_'+SUFFIX
USER='atlas_clean_'+SUFFIX
PASSWORD=secrets.token_urlsafe(28)
TARGET=RUNTIME/('clean-install-'+SUFFIX)
LOG=ROOT/'implementation/evidence/clean-install.log'
ENV=dict(os.environ,WP_CLI_CACHE_DIR=str(RUNTIME/'cache'))
REPORT={'started_at':datetime.now(timezone.utc).isoformat(),'scope':'new local database and real ZIP installs',
        'passed':False,'checks':[],'path':str(TARGET.relative_to(ROOT))}

def sql(query):
    subprocess.run(['nice','-n','19','mariadb','--socket='+str(SOCKET),'-u','root'],
                   input=query,text=True,check=True,stdout=subprocess.DEVNULL)

def wp(*args, check=True):
    with LOG.open('a') as out:
        result=subprocess.run(['nice','-n','19','wp','--path='+str(TARGET),*map(str,args)],
                              cwd=ROOT,env=ENV,stdout=out,stderr=subprocess.STDOUT)
    if check and result.returncode: raise RuntimeError('WP-CLI clean-install command failed; see clean-install.log')
    return result.returncode

created=False
try:
    manifest=json.loads((ROOT/'dist/manifest.json').read_text())
    release=json.loads((ROOT/'package.json').read_text())['version']
    if manifest.get('version')!=release:
        raise RuntimeError('Build the current release before the clean-install check.')
    artifacts={a['file']:a for a in manifest['artifacts']}
    required=['atlas-leisure','atlas-core','atlas-operations','atlas-gateways','atlas-inventory']
    for name in required:
        filename=f'{name}-{release}.zip'
        artifact=artifacts[filename]
        if hashlib.sha256((ROOT/'dist'/filename).read_bytes()).hexdigest()!=artifact['sha256']:
            raise RuntimeError('Artifact checksum mismatch: '+filename)
    # Copy WordPress core only. Runtime credentials, plugins, themes, uploads and user data are excluded.
    shutil.copytree(RUNTIME/'wordpress',TARGET,ignore=shutil.ignore_patterns('wp-content','wp-config.php','.htaccess'))
    for kind in ['themes','plugins']:(TARGET/'wp-content'/kind).mkdir(parents=True,exist_ok=True)
    guards=TARGET/'wp-content/mu-plugins'
    guards.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'deploy/local-mail.php',guards/'atlas-local-mail.php')
    sql('CREATE DATABASE `'+DATABASE+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;')
    created=True
    sql("CREATE USER '"+USER+"'@'localhost' IDENTIFIED BY '"+PASSWORD+"'; GRANT ALL ON `"+DATABASE+"`.* TO '"+USER+"'@'localhost';")
    wp('config','create','--dbname='+DATABASE,'--dbuser='+USER,'--dbpass='+PASSWORD,
       '--dbhost=localhost:'+str(SOCKET),'--skip-check')
    for key,value,raw in [('WP_ENVIRONMENT_TYPE','local',False),('ATLAS_DEMO_MODE','true',True),
                          ('WP_DEBUG','true',True),('WP_DEBUG_LOG','true',True),
                          ('WP_DEBUG_DISPLAY','false',True),('DISABLE_WP_CRON','true',True)]:
        wp('config','set',key,value,*(['--raw'] if raw else []))
    wp('core','install','--url=http://127.0.0.1:8841','--title=Isolated ZIP installation check',
       '--admin_user=install_check','--admin_password='+secrets.token_urlsafe(28),
       '--admin_email=install@example.test','--skip-email')
    wp('theme','install',ROOT/'dist'/f'atlas-leisure-{release}.zip','--activate')
    wp('eval',"if (class_exists('WooCommerce') || !function_exists('atlas_theme_home') || strpos(atlas_theme_home(),'[atlas_')!==false) { WP_CLI::error('Theme-only fallback failed.'); } WP_CLI::success('Theme works without companions or Woo.');")
    REPORT['checks'].append('actual theme ZIP installed and rendered safely without Woo/companions')
    # Third-party Woo source stays external/read-only; owned packages below are extracted from real ZIPs.
    (TARGET/'wp-content/plugins/woocommerce').symlink_to(RUNTIME/'wordpress/wp-content/plugins/woocommerce',target_is_directory=True)
    wp('plugin','activate','woocommerce')
    for name in required[1:]:
        wp('plugin','install',ROOT/'dist'/f'{name}-{release}.zip','--activate')
        if name=='atlas-core':wp('atlas','migrate')
        if (TARGET/'wp-content/plugins'/name).is_symlink(): raise RuntimeError('Owned package is a symlink')
        REPORT['checks'].append(name+' actual ZIP extracted and activated')
    wp('atlas','migrate')
    # Use a literal PHP file for final schema/API assertions to avoid shell escaping.
    smoke=TARGET/'atlas-smoke.php'
    smoke.write_text(r'''<?php
foreach ([\Atlas\Core\DB::class, 'Atlas_Operations', \Atlas\Inventory\Local::class, 'Atlas_Editorial', 'Atlas_Private_Files', 'Atlas_ZarinPal', 'Atlas_Resource_Booking', 'Atlas_Academy', 'Atlas_Procurement', 'Atlas_Events_Advanced', 'Atlas_Tours'] as $class) {
    if (!class_exists($class)) { WP_CLI::error('Required package class missing: '.$class); }
}
if (is_wp_error(\Atlas\Core\DB::ready())) { WP_CLI::error('Schema unavailable'); }
foreach (['atlas_core_schema'=>'7','atlas_core_schema_validated'=>'7','atlas_local_inventory_schema'=>'3','atlas_operations_checkins_schema'=>'1'] as $key=>$version) {
    if (get_option($key)!==$version) { WP_CLI::error('Missing or unexpected installed schema: '.$key); }
}
if (is_wp_error(\Atlas\Inventory\Local::migrate())) { WP_CLI::error('Inventory migration is not repeatable'); }
$r=rest_do_request(new WP_REST_Request('GET','/agency/v1/catalog'));
if ($r->get_status()!==200) { WP_CLI::error('Catalog route unavailable'); }
$private=rest_do_request(new WP_REST_Request('GET','/agency/v1/private-files'));
if ($private->get_status()!==403 || Atlas_ZarinPal::ready()) { WP_CLI::error('Private or payment routes are enabled without configuration.'); }
WP_CLI::success('Clean schema and catalog route verified');
''')
    wp('eval-file',smoke)
    REPORT['checks'].append('core7/inventory3/checkins1 schemas, repeatable migrations and registered public catalog route')
    REPORT['passed']=True
except Exception as error:
    REPORT['error']=str(error)
finally:
    if created:
        sql('DROP DATABASE `'+DATABASE+'`;')
        sql("DROP USER IF EXISTS '"+USER+"'@'localhost';")
    REPORT['finished_at']=datetime.now(timezone.utc).isoformat()
    REPORT['artifacts_sha256']={name:a['sha256'] for name,a in artifacts.items()} if 'artifacts' in locals() else {}
    (ROOT/'implementation/evidence/clean-install-results.json').write_text(json.dumps(REPORT,indent=2)+'\n')
print(json.dumps(REPORT,indent=2))
raise SystemExit(0 if REPORT['passed'] else 1)
