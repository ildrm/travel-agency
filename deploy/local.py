#!/usr/bin/env python3
"""Isolated, low-priority local WordPress runtime. Never modifies system services."""
from pathlib import Path
import argparse
import hashlib
import json
import os
import secrets
import shutil
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
RUNTIME = ROOT / '.runtime'
WP = RUNTIME / 'wordpress'
LOGS = ROOT / 'implementation/evidence'
ENV = dict(os.environ, WP_CLI_CACHE_DIR=str(RUNTIME/'cache'))
URL = 'http://127.0.0.1:8840'

def command(args, name='runtime', check=True):
    LOGS.mkdir(parents=True, exist_ok=True)
    with (LOGS/(name+'.log')).open('a') as out:
        result = subprocess.run(['nice', '-n', '19', *map(str,args)], cwd=ROOT,
                                env=ENV, stdout=out, stderr=subprocess.STDOUT)
    if check and result.returncode:
        raise RuntimeError(f'{args[0]} failed ({result.returncode}); see implementation/evidence/{name}.log')
    return result.returncode

def wp(*args, check=True):
    return command(['wp', '--path='+str(WP), *args], 'setup', check)

def db_alive():
    return subprocess.run(['mariadb-admin','--socket='+str(RUNTIME/'db.sock'),'-u','root','ping'],
                          stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL).returncode == 0

def start_db():
    RUNTIME.mkdir(exist_ok=True, mode=0o700)
    RUNTIME.chmod(0o700)
    config = RUNTIME/'mariadb.cnf'
    config.write_text(f'''[mysqld]
datadir={RUNTIME}/db
socket={RUNTIME}/db.sock
pid-file={RUNTIME}/db.pid
log-error={RUNTIME}/db-error.log
skip-networking
skip-log-bin
performance_schema=OFF
innodb_buffer_pool_size=32M
innodb_log_buffer_size=4M
innodb_read_io_threads=1
innodb_write_io_threads=1
max_connections=12
key_buffer_size=1M
sort_buffer_size=256K
read_buffer_size=128K
tmp_table_size=4M
max_heap_table_size=4M
''')
    if db_alive():
        return
    if not (RUNTIME/'db/mysql').exists():
        command(['mariadb-install-db','--datadir='+str(RUNTIME/'db'),
                 '--auth-root-authentication-method=normal','--skip-test-db'],'db-init')
    with (RUNTIME/'db-process.log').open('a') as log:
        subprocess.Popen(['nice','-n','19','mariadbd','--defaults-file='+str(config)],cwd=ROOT,
                         stdout=log,stderr=log,stdin=subprocess.DEVNULL,start_new_session=True)
    for _ in range(100):
        if db_alive():
            return
        time.sleep(.1)
    raise RuntimeError('Database did not start; see .runtime/db-error.log')

def setup():
    start_db()
    lock_path=ROOT/'deploy/runtime.lock.json'
    lock=json.loads(lock_path.read_text()) if lock_path.exists() else {}
    if not (WP/'wp-load.php').exists():
        wp('core','download','--skip-content',*(['--version='+lock['wordpress']] if lock.get('wordpress') else []))
    credentials_path=RUNTIME/'credentials.json'
    if credentials_path.exists():
        credentials=json.loads(credentials_path.read_text())
    else:
        credentials={'database':'atlas_local','username':'atlas_local','password':secrets.token_urlsafe(28),
                     'admin_user':'atlas_admin','admin_password':secrets.token_urlsafe(28),'url':URL}
        credentials_path.write_text(json.dumps(credentials,indent=2)+'\n')
        credentials_path.chmod(0o600)
    # Generated URL-safe credentials are never printed, placed in git, or taken from a user database.
    sql=("CREATE DATABASE IF NOT EXISTS atlas_local CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
         "CREATE USER IF NOT EXISTS 'atlas_local'@'localhost' IDENTIFIED BY '"+credentials['password']+"';"
         "GRANT ALL ON atlas_local.* TO 'atlas_local'@'localhost';")
    subprocess.run(['mariadb','--socket='+str(RUNTIME/'db.sock'),'-u','root'],input=sql,text=True,check=True)
    if not (WP/'wp-config.php').exists():
        wp('config','create','--dbname=atlas_local','--dbuser=atlas_local',
           '--dbpass='+credentials['password'],'--dbhost=localhost:'+str(RUNTIME/'db.sock'),'--skip-check')
        wp('config','set','DB_NAME',"(PHP_SAPI === 'cli' && getenv('ATLAS_TEST_DB') === 'atlas_integration') ? 'atlas_integration' : 'atlas_local'",'--raw')
        for key,value,raw in [('WP_ENVIRONMENT_TYPE','local',False),('ATLAS_DEMO_MODE','true',True),
                              ('WP_DEBUG','true',True),('WP_DEBUG_LOG','true',True),
                              ('WP_DEBUG_DISPLAY','false',True),('DISABLE_WP_CRON','true',True)]:
            wp('config','set',key,value,*(['--raw'] if raw else []))
    if wp('core','is-installed',check=False):
        wp('core','install','--url='+URL,'--title=Atlas Leisure — Local demonstration',
           '--admin_user='+credentials['admin_user'],'--admin_password='+credentials['admin_password'],
           '--admin_email=admin@example.test','--skip-email')
    # Only links inside this isolated runtime are created; existing source folders are never overwritten.
    for kind in ['themes','plugins']:
        target=WP/'wp-content'/kind
        target.mkdir(parents=True,exist_ok=True)
        for source in (ROOT/'wp-content'/kind).glob('*'):
            link=target/source.name
            if not link.exists() and not link.is_symlink():
                link.symlink_to(source, target_is_directory=True)
    guards=WP/'wp-content/mu-plugins'
    guards.mkdir(parents=True,exist_ok=True)
    shutil.copyfile(ROOT/'deploy/local-mail.php',guards/'atlas-local-mail.php')
    if not (WP/'wp-content/plugins/woocommerce/woocommerce.php').exists():
        wp('plugin','install','woocommerce',*(['--version='+lock['woocommerce']] if lock.get('woocommerce') else []))
    wp('plugin','activate','woocommerce')
    if not (WP/'wp-content/languages/fa_IR.mo').exists():
        wp('--skip-plugins','--skip-themes','language','core','install','fa_IR')
    if not (WP/'wp-content/languages/plugins/woocommerce-fa_IR.mo').exists():
        wp('--skip-plugins','--skip-themes','language','plugin','install','woocommerce','fa_IR')
    if '--activate-atlas' in sys.argv:
        for plugin in ['atlas-core','atlas-operations','atlas-gateways','atlas-inventory']:
            if (ROOT/'wp-content/plugins'/plugin/(plugin+'.php')).exists():
                wp('plugin','activate',plugin)
                if plugin == 'atlas-core':
                    wp('atlas','migrate')
        wp('eval-file',ROOT/'deploy/migrate-all.php')
        if (ROOT/'wp-content/themes/atlas-leisure/style.css').exists():
            wp('theme','activate','atlas-leisure')
    wp('option','update','woocommerce_currency','IRR')
    # This loopback-only demo must expose checkout to its fictional customer;
    # Woo's fresh-install coming-soon mode otherwise hides it from non-admins.
    wp('option','update','woocommerce_coming_soon','no')
    wp('option','update','woocommerce_price_num_decimals','0')
    wp('option','update','woocommerce_enable_guest_checkout','no')
    wp('option','update','woocommerce_enable_myaccount_registration','yes')
    wp('option','update','woocommerce_registration_generate_password','no')
    wp('option','update','woocommerce_custom_orders_table_enabled','yes')
    wp('option','update','blog_public','0')
    wp('option','update','timezone_string','Asia/Tehran')
    wp('rewrite','structure','/%postname%/')
    wp_version=(WP/'wp-includes/version.php').read_text()
    import re
    lock['wordpress']=re.search(r"\$wp_version = '([^']+)'",wp_version).group(1)
    woo=(WP/'wp-content/plugins/woocommerce/woocommerce.php').read_text()
    lock['woocommerce']=re.search(r'Version:\s*([^\s]+)',woo).group(1)
    php_runtime=subprocess.run(['php','-r','echo PHP_VERSION;'],capture_output=True,text=True,check=True).stdout.strip()
    database_runtime=subprocess.run(['mariadb','--socket='+str(RUNTIME/'db.sock'),'-u','root','-N','-e','SELECT VERSION();'],capture_output=True,text=True,check=True).stdout.strip()
    observed={'php':php_runtime,'database':database_runtime}
    prior_status=lock.get('status','downloaded_not_yet_compatibility_verified')
    if lock.get('verified_environment')!=observed:prior_status='downloaded_not_yet_compatibility_verified'
    lock.update({'retrieved':'2026-11-17','source_wordpress':'https://wordpress.org/',
                 'source_woocommerce':'https://wordpress.org/plugins/woocommerce/',
                 'observed_runtime':observed,'status':prior_status})
    lock.pop('php_test_host',None); lock.pop('database_test_host',None)
    lock_path.write_text(json.dumps(lock,indent=2)+'\n')
    print('Local runtime installed. Credentials: .runtime/credentials.json (private, gitignored).')

def start_web():
    start_db()
    import urllib.request
    try:
        response=urllib.request.urlopen(URL,timeout=2)
        if response.headers.get('X-Atlas-Local-Runtime')!=hashlib.sha256(str(ROOT).encode()).hexdigest()[:16]:
            raise RuntimeError('Port 8840 belongs to a different application; it was left untouched.')
        print(URL+' already responding; no second server started.')
        return
    except RuntimeError:
        raise
    except (OSError, urllib.error.URLError):
        pass
    with (RUNTIME/'web.log').open('a') as log:
        process=subprocess.Popen(['nice','-n','19','php','-S','127.0.0.1:8840','-t',str(WP),str(ROOT/'deploy/router.php')],
                                 cwd=ROOT,stdout=log,stderr=log,stdin=subprocess.DEVNULL,start_new_session=True)
    (RUNTIME/'web.pid').write_text(str(process.pid))
    print('Local-only server: '+URL)

def stop():
    import signal
    path=RUNTIME/'web.pid'
    if path.exists():
        try:
            pid=int(path.read_text())
            process=subprocess.run(['ps','-p',str(pid),'-o','command='],capture_output=True,text=True)
            if str(ROOT/'deploy/router.php') in process.stdout and str(WP) in process.stdout:
                os.kill(pid,signal.SIGTERM)
            else:
                print('Stored server PID no longer belongs to this project; unrelated process left untouched.')
        except ProcessLookupError:
            pass
        path.unlink(missing_ok=True)
    if db_alive():
        command(['mariadb-admin','--socket='+str(RUNTIME/'db.sock'),'-u','root','shutdown'],'shutdown')
    print('Project runtime stopped; system services unchanged.')

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('action',choices=['setup','demo','start','stop','wp','health','backup'])
    parser.add_argument('args',nargs=argparse.REMAINDER)
    args=parser.parse_args()
    if args.action=='setup': setup()
    elif args.action=='demo':
        sys.argv.append('--activate-atlas')
        setup()
        wp('eval-file',ROOT/'deploy/seed.php')
        start_web()
    elif args.action=='start': start_web()
    elif args.action=='stop': stop()
    elif args.action=='wp': sys.exit(wp(*args.args,check=False))
    elif args.action=='health':
        print(json.dumps({'db':db_alive(),'wordpress':(WP/'wp-load.php').exists(),'url':URL}))
    elif args.action=='backup':
        RUNTIME.mkdir(exist_ok=True)
        with (RUNTIME/'backup.sql').open('w') as out:
            subprocess.run(['nice','-n','19','mariadb-dump','--socket='+str(RUNTIME/'db.sock'),
                            '-u','root','--single-transaction','atlas_local'],stdout=out,check=True)
        print('Private local backup: .runtime/backup.sql')

if __name__=='__main__': main()
