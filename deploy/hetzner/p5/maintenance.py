#!/usr/bin/env python3
"""Prepare/activate/deactivate a root-approved production 443 maintenance marker; never reloads Nginx."""
from __future__ import annotations
import argparse, ipaddress, json, os, re, stat, subprocess, time
from pathlib import Path
from p5_common import P5Error, emit_error, exclusive_lock, read_json_private, root_required, validate_approval, validate_no_symlink_components, validate_root_directory, validate_root_file

NGINX=Path('/usr/sbin/nginx'); CONFIG=Path('/etc/erpcondominios/p5/maintenance.json'); APPROVAL=Path('/etc/erpcondominios/p5/maintenance-approval.json'); BACKUP_ROOT=Path('/var/backups/erpcondominios/p5-maintenance'); LOCK=Path('/run/lock/erpcondominios-p5-maintenance.lock')
NGINX_MAIN=Path('/etc/nginx/nginx.conf'); ALLOW_CONFIG=Path('/etc/nginx/conf.d/erp-p5-maintenance-maps.conf'); ACTIVE_ENTRIES=Path('/etc/nginx/conf.d/erp-p5-maintenance-active.map'); LOCATION=Path('/etc/nginx/snippets/erp-p5-maintenance-503.conf')
MAIN_MARKER='# P5-MAINTENANCE-MAPS'; VHOST_MARKER='# P5-MAINTENANCE-LOCATION'; MAP_INCLUDE='include /etc/nginx/conf.d/erp-p5-maintenance-maps.conf;'; LOCATION_INCLUDE='include /etc/nginx/snippets/erp-p5-maintenance-503.conf;'
PRODUCTION_ROOT='/var/www/erpcondominios/current'; VHOST_DIR=Path('/etc/nginx/sites-enabled'); CONFIG_KEYS={'vhosts','allowed_client_cidr','nginx_main','backup_root','approval_file'}
DOMAIN_RE=re.compile(r'^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$')

def _load_config(*,owner_uid=0):
    data=read_json_private(CONFIG,expected_keys=CONFIG_KEYS,owner_uid=owner_uid)
    if data.get('nginx_main')!=str(NGINX_MAIN) or data.get('backup_root')!=str(BACKUP_ROOT) or data.get('approval_file')!=str(APPROVAL): raise P5Error('MAINTENANCE_CONFIG_NOT_ALLOWLISTED')
    try: net=ipaddress.ip_network(data.get('allowed_client_cidr',''),strict=True)
    except (ValueError,TypeError) as e: raise P5Error('PRIVATE_ALLOWLIST_CIDR_INVALID') from e
    if not isinstance(net,ipaddress.IPv4Network) or net.prefixlen!=32 or net.network_address.is_unspecified or net.network_address.is_multicast or net.network_address.is_loopback or net.network_address.is_link_local: raise P5Error('PRIVATE_ALLOWLIST_CIDR_INVALID')
    items=data.get('vhosts')
    if not isinstance(items,list) or len(items)!=2: raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED')
    kinds=[]; paths=[]; names=[]
    for x in items:
        if not isinstance(x,dict) or set(x)!={'kind','path','server_name'} or x['kind'] not in {'domain','ip'}: raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED')
        path=Path(x['path']); name=x['server_name']
        if not path.is_absolute() or path.parent!=VHOST_DIR or '..' in path.parts or not isinstance(name,str): raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED')
        if x['kind']=='domain' and not DOMAIN_RE.fullmatch(name): raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED')
        if x['kind']=='ip':
            try: ipaddress.ip_address(name)
            except ValueError as e: raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED') from e
        kinds.append(x['kind']); paths.append(str(path)); names.append(name)
    if sorted(kinds)!=['domain','ip'] or len(set(paths))!=2 or len(set(names))!=2: raise P5Error('ACTIVE_VHOSTS_NOT_ALLOWLISTED')
    return data,str(net)

def _read(path,owner_uid=0):
    validate_root_file(path,mode=0o644,owner_uid=owner_uid); raw=path.read_bytes()
    if len(raw)>262144: raise P5Error('NGINX_CONFIG_TOO_LARGE')
    return raw

def _active_configs(data,owner_uid=0):
    main=_read(NGINX_MAIN,owner_uid).decode('utf-8')
    main_original=main.count(MAIN_MARKER)==1 and MAP_INCLUDE not in main
    main_prepared=main.count(MAIN_MARKER)==0 and main.count(MAP_INCLUDE)==1
    if not (main_original or main_prepared): raise P5Error('NGINX_MAIN_MARKER_UNCONFIGURED')
    result={NGINX_MAIN:main.encode()}
    original_state=main_original
    for x in data['vhosts']:
        path=Path(x['path']); text=_read(path,owner_uid).decode('utf-8')
        listen=re.search(r'(?m)^\s*listen\s+443\s+ssl\b',text)
        server=re.search(r'(?m)^\s*server_name\s+'+re.escape(x['server_name'])+r'\s*;',text)
        root=re.search(r'(?m)^\s*root\s+'+re.escape(PRODUCTION_ROOT)+r'\s*;',text)
        if 'server {' not in text or not listen or not server or not root:
            raise P5Error('NGINX_PRODUCTION_VHOST_NOT_ALLOWLISTED')
        is_original=text.count(VHOST_MARKER)==1 and LOCATION_INCLUDE not in text
        is_prepared=text.count(VHOST_MARKER)==0 and text.count(LOCATION_INCLUDE)==1
        if not (is_original or is_prepared) or is_original!=main_original:
            raise P5Error('NGINX_PRODUCTION_VHOST_NOT_ALLOWLISTED')
        original_state=original_state and is_original
        result[path]=text.encode()
    support=(ALLOW_CONFIG,ACTIVE_ENTRIES,LOCATION)
    present=[p.exists() or p.is_symlink() for p in support]
    if original_state:
        if any(present): raise P5Error('MAINTENANCE_PARTIAL_STATE')
        return 'UNPREPARED',result
    if not all(present): raise P5Error('MAINTENANCE_PARTIAL_STATE')
    if _read(ALLOW_CONFIG,owner_uid)!=_map_config(data['allowed_client_cidr']):
        raise P5Error('MAINTENANCE_MAP_CONFIG_MISMATCH')
    if _read(LOCATION,owner_uid)!=b'# Missing/empty allowlist during an activated window is fail-closed.\nif ($p5_maintenance_block != 0) { return 503; }\n':
        raise P5Error('MAINTENANCE_LOCATION_CONFIG_MISMATCH')
    active_names='|'.join(re.escape(x['server_name']) for x in data['vhosts'])
    active_expected=('~^(?:'+active_names+')$ 1;\n').encode('ascii')
    active_entries=_read(ACTIVE_ENTRIES,owner_uid)
    inactive_entries={
        b'# Inactive by default; activation requires approved --activate.\n',
        b'# Inactive by default.\n',
    }
    if active_entries not in inactive_entries and active_entries!=active_expected:
        raise P5Error('MAINTENANCE_ACTIVE_MAP_INVALID')
    return ('ACTIVE' if active_entries==active_expected else 'PREPARED'),result

def check(*,owner_uid=0):
    data,cidr=_load_config(owner_uid=owner_uid)
    validate_root_directory(VHOST_DIR,mode=0o755,owner_uid=owner_uid); validate_root_directory(NGINX_MAIN.parent,mode=0o755,owner_uid=owner_uid)
    validate_no_symlink_components(BACKUP_ROOT)
    if not BACKUP_ROOT.exists(): raise P5Error('MAINTENANCE_BACKUP_ROOT_NOT_PROVISIONED')
    validate_root_directory(BACKUP_ROOT,mode=0o700,owner_uid=owner_uid); state,_=_active_configs(data,owner_uid)
    for d in (ALLOW_CONFIG.parent,ACTIVE_ENTRIES.parent,LOCATION.parent): validate_root_directory(d,mode=0o755,owner_uid=owner_uid)
    if NGINX.is_symlink() or not NGINX.is_file() or not os.access(NGINX,os.X_OK): raise P5Error('NGINX_BINARY_UNAVAILABLE')
    return data,cidr,state

def _map_config(cidr):
    ip=str(ipaddress.ip_network(cidr,strict=True).network_address)
    return (f'map $remote_addr $p5_maintenance_allowlisted {{ default 0; {ip} 1; }}\n'
            'map $host $p5_maintenance_activation { default 0; include /etc/nginx/conf.d/erp-p5-maintenance-active.map; }\n'
            'map $p5_maintenance_allowlisted $p5_maintenance_not_allowlisted { default 1; 1 0; }\n'
            'map "$p5_maintenance_activation:$p5_maintenance_not_allowlisted" $p5_maintenance_block { default 0; "1:1" 1; }\n').encode('ascii')

def _write(path,payload,owner_uid=0):
    validate_no_symlink_components(path)
    if path.exists() or path.is_symlink(): validate_root_file(path,mode=0o644,owner_uid=owner_uid)
    tmp=path.with_name('.'+path.name+'.'+str(os.getpid())+'.tmp')
    if tmp.exists() or tmp.is_symlink(): raise P5Error('MAINTENANCE_TEMP_EXISTS')
    fd=os.open(tmp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
    try:
        with os.fdopen(fd,'wb') as f: f.write(payload); f.flush(); os.fsync(f.fileno())
        os.chown(tmp,owner_uid,0 if owner_uid==0 else os.getegid()); os.chmod(tmp,0o644); os.replace(tmp,path)
        dfd=os.open(path.parent,os.O_RDONLY|getattr(os,'O_DIRECTORY',0)|getattr(os,'O_NOFOLLOW',0))
        try: os.fsync(dfd)
        finally: os.close(dfd)
    except BaseException:
        if tmp.exists() and not tmp.is_symlink():
            try: tmp.unlink()
            except OSError: pass
        raise

def _backup(path,payload,stamp,owner_uid=0):
    dest=BACKUP_ROOT/(stamp+'-'+path.name+'.bak')
    if dest.exists() or dest.is_symlink(): raise P5Error('MAINTENANCE_BACKUP_EXISTS')
    fd=os.open(dest,os.O_WRONLY|os.O_CREAT|os.O_EXCL|getattr(os,'O_NOFOLLOW',0),0o600)
    with os.fdopen(fd,'wb') as f: f.write(payload); f.flush(); os.fsync(f.fileno())
    os.chown(dest,owner_uid,0 if owner_uid==0 else os.getegid()); os.chmod(dest,0o600)
    dfd=os.open(BACKUP_ROOT,os.O_RDONLY|getattr(os,'O_DIRECTORY',0)|getattr(os,'O_NOFOLLOW',0))
    try: os.fsync(dfd)
    finally: os.close(dfd)

def _backup_stamp():
    return time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+'-'+str(os.getpid())+'-'+str(time.monotonic_ns())

def _nginx_test(runner):
    try: result=runner([str(NGINX),'-t'],stdin=subprocess.DEVNULL,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,env={'PATH':'/usr/sbin:/usr/bin:/sbin:/bin','LC_ALL':'C'},close_fds=True,check=False)
    except OSError as e: raise P5Error('NGINX_TEST_UNAVAILABLE') from e
    if result.returncode: raise P5Error('NGINX_TEST_FAILED')

def prepare(*,runner=subprocess.run,owner_uid=0):
    root_required(); data,cidr,state=check(owner_uid=owner_uid)
    if state!='UNPREPARED': raise P5Error('MAINTENANCE_ALREADY_PREPARED')
    validate_approval(APPROVAL,action='maintenance-503-prepare',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts',owner_uid=owner_uid)
    lock=exclusive_lock(LOCK,owner_uid=owner_uid)
    try:
        locked_data,locked_cidr,locked_state=check(owner_uid=owner_uid)
        if locked_state!='UNPREPARED' or locked_cidr!=cidr or locked_data['vhosts']!=data['vhosts']:
            raise P5Error('MAINTENANCE_STATE_CHANGED')
        data=locked_data
        validate_approval(APPROVAL,action='maintenance-503-prepare',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts',owner_uid=owner_uid)
        state,original=_active_configs(data,owner_uid)
        if state!='UNPREPARED': raise P5Error('MAINTENANCE_ALREADY_PREPARED')
        changes={}
        changes[NGINX_MAIN]=original[NGINX_MAIN].decode().replace(MAIN_MARKER,MAP_INCLUDE).encode()
        for x in data['vhosts']:
            p=Path(x['path']); changes[p]=original[p].decode().replace(VHOST_MARKER,LOCATION_INCLUDE).encode()
        changes[ALLOW_CONFIG]=_map_config(cidr); changes[ACTIVE_ENTRIES]=b'# Inactive by default; activation requires approved --activate.\n'
        changes[LOCATION]=b'# Missing/empty allowlist during an activated window is fail-closed.\nif ($p5_maintenance_block != 0) { return 503; }\n'
        stamp=_backup_stamp()
        for p,b in original.items(): _backup(p,b,stamp,owner_uid)
        for p in (ALLOW_CONFIG,ACTIVE_ENTRIES,LOCATION):
            if p.exists(): _backup(p,_read(p,owner_uid),stamp,owner_uid)
        prior={p:(_read(p,owner_uid) if p.exists() else None) for p in changes}
        try:
            for p,b in changes.items(): _write(p,b,owner_uid)
            _nginx_test(runner)
        except BaseException:
            try:
                for p,b in reversed(list(prior.items())):
                    if b is not None:
                        _write(p,b,owner_uid)
                    elif p.exists() or p.is_symlink():
                        validate_root_file(p,mode=0o644,owner_uid=owner_uid)
                        p.unlink()
            except BaseException as rollback_error:
                raise P5Error('MAINTENANCE_PREPARE_ROLLBACK_FAILED') from rollback_error
            raise
        return {'status':'PREPARED_NOT_RELOADED','backup_count':len(original),'nginx_test':'PASS','reload':'NOT_RUN'}
    finally: os.close(lock)

def activate(*,runner=subprocess.run,owner_uid=0):
    data,cidr,state=check(owner_uid=owner_uid)
    if state!='PREPARED': raise P5Error('MAINTENANCE_NOT_PREPARED_OR_ALREADY_ACTIVE')
    validate_approval(APPROVAL,action='maintenance-503-activate',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts:'+cidr,owner_uid=owner_uid)
    lock=exclusive_lock(LOCK,owner_uid=owner_uid)
    try:
        locked_data,locked_cidr,locked_state=check(owner_uid=owner_uid)
        if locked_state!='PREPARED' or locked_cidr!=cidr or locked_data['vhosts']!=data['vhosts']:
            raise P5Error('MAINTENANCE_STATE_CHANGED')
        validate_approval(APPROVAL,action='maintenance-503-activate',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts:'+cidr,owner_uid=owner_uid)
        validate_root_file(ACTIVE_ENTRIES,mode=0o644,owner_uid=owner_uid)
        stamp=_backup_stamp(); _backup(ACTIVE_ENTRIES,_read(ACTIVE_ENTRIES,owner_uid),stamp,owner_uid)
        names='|'.join(re.escape(x['server_name']) for x in data['vhosts'])
        previous=_read(ACTIVE_ENTRIES,owner_uid)
        try:
            _write(ACTIVE_ENTRIES,('~^(?:'+names+')$ 1;\n').encode('ascii'),owner_uid); _nginx_test(runner)
        except Exception:
            try:
                _write(ACTIVE_ENTRIES,previous,owner_uid)
            except Exception as rollback_error:
                raise P5Error('MAINTENANCE_ACTIVATE_ROLLBACK_FAILED') from rollback_error
            raise
        return {'status':'ACTIVATION_MARKER_PREPARED_NOT_RELOADED','reload':'NOT_RUN'}
    finally: os.close(lock)

def deactivate(*,runner=subprocess.run,owner_uid=0):
    data,cidr,state=check(owner_uid=owner_uid)
    if state!='ACTIVE': raise P5Error('MAINTENANCE_NOT_ACTIVE')
    validate_approval(APPROVAL,action='maintenance-503-deactivate',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts:'+cidr,owner_uid=owner_uid)
    lock=exclusive_lock(LOCK,owner_uid=owner_uid)
    try:
        locked_data,locked_cidr,locked_state=check(owner_uid=owner_uid)
        if locked_state!='ACTIVE' or locked_cidr!=cidr or locked_data['vhosts']!=data['vhosts']:
            raise P5Error('MAINTENANCE_STATE_CHANGED')
        validate_approval(APPROVAL,action='maintenance-503-deactivate',target='production-443',source_schema=None,destination='approved-domain-and-ip-vhosts:'+cidr,owner_uid=owner_uid)
        validate_root_file(ACTIVE_ENTRIES,mode=0o644,owner_uid=owner_uid)
        stamp=_backup_stamp(); _backup(ACTIVE_ENTRIES,_read(ACTIVE_ENTRIES,owner_uid),stamp,owner_uid)
        previous=_read(ACTIVE_ENTRIES,owner_uid)
        try:
            _write(ACTIVE_ENTRIES,b'# Inactive by default.\n',owner_uid); _nginx_test(runner)
        except Exception:
            try:
                _write(ACTIVE_ENTRIES,previous,owner_uid)
            except Exception as rollback_error:
                raise P5Error('MAINTENANCE_DEACTIVATE_ROLLBACK_FAILED') from rollback_error
            raise
        return {'status':'DEACTIVATION_MARKER_CLEARED_NOT_RELOADED','reload':'NOT_RUN','backup_count':1}
    finally: os.close(lock)

def main():
    p=argparse.ArgumentParser(description=__doc__); g=p.add_mutually_exclusive_group(required=True)
    for flag in ('check','plan','prepare','activate','deactivate'): g.add_argument('--'+flag,action='store_true')
    a=p.parse_args()
    try:
        if a.check or a.plan:
            d,c,state=check(); result={'status':'CHECK_READY' if a.check else 'PLAN_READY_NOT_RELOADED','vhosts':2,'state':state,'activation':'root-approval-marker','reload':'NOT_RUN'}
        elif a.prepare: result=prepare()
        elif a.activate: result=activate()
        else: result=deactivate()
    except P5Error as e: return emit_error(e)
    except Exception: return emit_error(P5Error('INTERNAL_FAILURE'))
    print(json.dumps(result,sort_keys=True)); return 0
if __name__=='__main__': raise SystemExit(main())
