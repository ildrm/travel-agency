#!/usr/bin/env python3
"""Prepare an isolated integration DB; browser/demo records are never test fixtures."""
from pathlib import Path
import json
import os
import secrets
import subprocess
import sys

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'deploy'))
import local
local.start_db()
WP=ROOT/'.runtime/wordpress'
if not (WP/'wp-config.php').exists():
    sys.exit('Run python3 deploy/local.py setup --activate-atlas first.')
credentials=json.loads((ROOT/'.runtime/credentials.json').read_text())
log_path=ROOT/'implementation/evidence/test-runtime.log'
env=dict(os.environ,WP_CLI_CACHE_DIR=str(ROOT/'.runtime/cache'))

def wp(*args, test=True, check=True):
    child_env=dict(env)
    if test:child_env['ATLAS_TEST_DB']='atlas_integration'
    else:child_env.pop('ATLAS_TEST_DB',None)
    with log_path.open('a') as log:
        result=subprocess.run(['nice','-n','19','wp','--path='+str(WP),*map(str,args)],
                              cwd=ROOT,env=child_env,stdout=log,stderr=subprocess.STDOUT)
    if check and result.returncode:
        raise RuntimeError('Test runtime setup failed; see implementation/evidence/test-runtime.log')
    return result.returncode

# The only override accepted is this fixed test DB under PHP CLI. HTTP requests
# always use the demonstration DB, even if an environment variable is supplied.
expression="(PHP_SAPI === 'cli' && getenv('ATLAS_TEST_DB') === 'atlas_integration') ? 'atlas_integration' : 'atlas_local'"
wp('config','set','DB_NAME',expression,'--raw',test=False)
sql="CREATE DATABASE IF NOT EXISTS atlas_integration CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON atlas_integration.* TO 'atlas_local'@'localhost';"
subprocess.run(['nice','-n','19','mariadb','--socket='+str(ROOT/'.runtime/db.sock'),'-u','root'],
               input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
if wp('--skip-plugins','--skip-themes','core','is-installed',check=False):
    wp('--skip-plugins','--skip-themes','core','install','--url=http://127.0.0.1:8840',
       '--title=Isolated integration fixtures','--admin_user=atlas_test_admin',
       '--admin_password='+secrets.token_urlsafe(32),'--admin_email=test-admin@example.test','--skip-email')
for plugin in ['woocommerce','atlas-core','atlas-operations','atlas-gateways','atlas-inventory']:
    wp('plugin','activate',plugin)
    if plugin=='atlas-core':wp('atlas','migrate')
wp('eval-file',ROOT/'deploy/migrate-all.php')
wp('theme','activate','atlas-leisure')
for key,value in [('woocommerce_currency','IRR'),('woocommerce_price_num_decimals','0'),
                  ('woocommerce_enable_guest_checkout','no'),('woocommerce_custom_orders_table_enabled','yes'),
                  ('blog_public','0'),('timezone_string','Asia/Tehran')]:
    wp('option','update',key,value)
print('Integration fixtures use atlas_integration; HTTP demonstration remains atlas_local.')
