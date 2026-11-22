#!/usr/bin/env python3
"""Book secure update manager. Run from a trusted, operator-owned checkout on the Docker host."""
import argparse
import contextlib
import copy
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile

sys.dont_write_bytecode = True
_spec = importlib.util.spec_from_file_location('book_release', Path(__file__).with_name('release-package.py'))
release = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(release)
require = release.require
ROLES = {'book', 'worker', 'backup', 'domain-check', 'diagnostics', 'pitr-sidecar'}
INFRA = {'postgres', 'redis'}
SERVICES = ROLES | INFRA | {'proxy', 'prometheus', 'alertmanager', 'grafana'}
OVERLAYS = ('postgres', 'redis', 'redis-sentinel', 'distributed', 'domains', 'backup', 'enterprise',
            'observability', 'security', 'shared-storage', 'pitr', 'update-agent')
ALL_FILES = release.FILES | {'release.json', 'release.json.sig', 'source.zip.sig', 'deployment.zip.sig'}
def all_files(v):
    return release.files_for(v) | {'release.json', 'release.json.sig', 'source.zip.sig', 'deployment.zip.sig'}


HOSTS = {'github.com', 'release-assets.githubusercontent.com', 'objects.githubusercontent.com'}
HTTPS_PORTS = {None,443}
MAX_UNPACK = 512 * 1024 * 1024
MAX_TOTAL = 1024 * 1024 * 1024
MAX_FILES = 10000


def version(v):
    require(isinstance(v, str) and release.VERSION.fullmatch(v), 'Invalid version')
    return tuple(map(int, v.split('.')))


def syncdir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def atomic(path, obj):
    fd, temp = tempfile.mkstemp(prefix='.state-', dir=path.parent)
    try:
        with os.fdopen(fd, 'w') as out:
            json.dump(obj, out, sort_keys=True, indent=2, allow_nan=False)
            out.write('\n'); out.flush(); os.fsync(out.fileno())
        os.replace(temp, path); syncdir(path.parent)
    finally:
        if os.path.exists(temp): os.unlink(temp)


def private_file(path):
    path = Path(path)
    require(path.is_absolute() and path.resolve() == path and path.is_file(), 'Expected absolute regular file without symlinks')
    s = path.stat()
    require(s.st_uid == os.geteuid() and stat.S_IMODE(s.st_mode) & 0o077 == 0, 'Private operator file must be owned by operator with 0600/0400 permissions')
    return path


def safe_url(url):
    u = urllib.parse.urlsplit(url)
    require(u.scheme == 'https' and u.hostname in HOSTS and u.port in HTTPS_PORTS and not u.username and not u.password and not u.fragment, 'Untrusted download URL')
    return url


class Redirect(urllib.request.HTTPRedirectHandler):
    max_redirections = 5
    max_repeats = 2
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        safe_url(newurl)
        return super().redirect_request(req, fp, code, msg, headers, newurl)


def limit(name):
    if name.endswith('.sig'): return 64
    if name == 'release.json': return 1024 * 1024
    if name.endswith('.zip'): return release.MAX_ZIP
    return release.asset_limit(name)


def copy_stream(inp, dest, cap, expected=None, deadline=None):
    n = 0
    with dest.open('xb') as out:
        while True:
            if deadline is not None: require(time.monotonic() < deadline, 'Download deadline exceeded')
            chunk = (inp.read1 if hasattr(inp, 'read1') else inp.read)(65536)
            if not chunk: break
            n += len(chunk)
            require(n <= cap and (expected is None or n <= expected), 'Artifact exceeds allowed size')
            out.write(chunk)
        if deadline is not None: require(time.monotonic() < deadline, 'Download deadline exceeded')
        require(expected is None or n == expected, 'Incomplete artifact')
        out.flush(); os.fsync(out.fileno())


def download(url, dest, cap, expected=None):
    # Do not use ambient HTTP(S)_PROXY or credentials; redirects remain on GitHub asset hosts.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), Redirect())
    deadline = time.monotonic() + 900
    with opener.open(urllib.request.Request(safe_url(url), headers={'User-Agent':'CajeerEngineBook-Updater/1','Accept-Encoding':'identity'}), timeout=20) as response:
        safe_url(response.geturl())
        require(response.status == 200 and response.headers.get('Content-Encoding','identity') == 'identity', 'Unexpected asset response')
        lengths = response.headers.get_all('Content-Length') or []
        require(len(lengths) <= 1, 'Ambiguous asset length')
        if lengths:
            require(lengths[0].isdigit() and int(lengths[0]) <= cap and (expected is None or int(lengths[0]) == expected), 'Unexpected asset length')
        copy_stream(response, dest, cap, expected, deadline)


def manifest_header(bundle, pub, repo, v):
    release.verify_sig(bundle/'release.json', pub)
    m = release.json_read(bundle/'release.json')
    require(m['format']==1 and m['product']=='CajeerEngineBook' and m['version']==v and m['repository']==repo and release.COMMIT.fullmatch(m['commit']), 'Unexpected signed release identity')
    require(m['signing_key_sha256']==hashlib.sha256(release.key_der(pub,True)).hexdigest(), 'Wrong release key')
    require(set(m['artifacts'])==release.files_for(v), 'Unexpected signed artifact set')
    total = 0
    for name, a in m['artifacts'].items():
        require(type(a['size']) is int and 0 < a['size'] <= limit(name) and re.fullmatch(r'[0-9a-f]{64}',a['sha256']), 'Invalid signed artifact size or hash')
        total += a['size']
    require(total <= MAX_TOTAL, 'Release exceeds download budget')
    return m


def zip_members(z):
    return release.zip_members(z)


def verify_bundle(bundle, pub, repo, v, commit=None):
    manifest_header(bundle,pub,repo,v)
    # Authenticate artifact bytes before opening either archive.
    m=release.verify_directory(bundle,pub,v,repo,commit)
    for n in ('source.zip','deployment.zip'):
        with zipfile.ZipFile(bundle/n) as z:
            require(z.getinfo('src/DatabaseMigrations.php').file_size <= 1024*1024, 'Schema source exceeds limit')
            body=z.read('src/DatabaseMigrations.php').decode('utf-8')
            match=re.search(r'public const VERSION = (\d+);',body)
            require(match and int(match.group(1))==m['schema_version'],'Signed schema metadata mismatch')
    return m


def unpack(bundle, tree):
    tree.mkdir(mode=0o700)
    with zipfile.ZipFile(bundle/'deployment.zip') as z:
        for i in zip_members(z):
            dst=tree/i.filename; dst.parent.mkdir(parents=True,exist_ok=True)
            with z.open(i) as inp: copy_stream(inp,dst,i.file_size,i.file_size)
            dst.chmod(0o755 if i.external_attr>>16 & 0o111 else 0o644)
    for d, _, _ in os.walk(tree,topdown=False): syncdir(d)


def verify_tree(bundle, tree):
    require(tree.is_dir() and not tree.is_symlink(), 'Missing deployment tree')
    with zipfile.ZipFile(bundle/'deployment.zip') as z:
        infos=zip_members(z)
        expected={i.filename for i in infos}
        actual=set()
        for d, dirs, files in os.walk(tree):
            require(not any((Path(d)/x).is_symlink() for x in dirs+files),'Symlink in deployment tree')
            for n in files: actual.add((Path(d)/n).relative_to(tree).as_posix())
        require(actual==expected,'Deployment tree differs from signed ZIP')
        for i in infos:
            p=tree/i.filename
            require(p.is_file() and p.stat().st_size==i.file_size and stat.S_IMODE(p.stat().st_mode)==(0o755 if i.external_attr>>16 & 0o111 else 0o644),'Deployment file changed')
            with z.open(i) as f: require(hashlib.file_digest(f,'sha256').hexdigest()==release.sha(p),'Deployment file changed')


def same_bind(a, b):
    a,b=Path(a),Path(b)
    if a.is_symlink() or b.is_symlink(): return False
    if a.is_file() and b.is_file(): return release.sha(a)==release.sha(b)
    if not a.is_dir() or not b.is_dir(): return False
    def files(root):
        result={}
        for d,dirs,names in os.walk(root):
            if any((Path(d)/n).is_symlink() for n in dirs+names): raise ValueError('Symlink in mounted release configuration')
            for n in names:
                p=Path(d)/n; result[p.relative_to(root).as_posix()]=release.sha(p)
        return result
    return files(a)==files(b)


def run(args, timeout=180, stdout=None, stdin=None):
    # No inherited Compose overrides, application secrets, proxy or remote Docker endpoint.
    env={k:os.environ[k] for k in ('PATH','HOME','LANG','LC_ALL','DOCKER_CONFIG','SSL_CERT_FILE','SSL_CERT_DIR') if k in os.environ}
    try:
        p=subprocess.run(args,env=env,stdin=stdin or subprocess.DEVNULL,stdout=stdout if stdout is not None else subprocess.PIPE,
                         stderr=subprocess.PIPE,timeout=timeout,check=False)
    except subprocess.TimeoutExpired as e:
        raise RuntimeError('Command timed out: '+args[0]) from e
    # Never echo Compose interpolation, container environment, or database credentials.
    if p.returncode: raise RuntimeError(f'Command failed ({p.returncode}): {args[0]} / {args[1]}')
    return p.stdout


def oci(m):
    identity=f"https://github.com/{m['repository']}/.github/workflows/ci.yml@refs/tags/v{m['version']}"
    for ref in m['images'].values():
        run(['cosign','verify','--certificate-identity',identity,'--certificate-oidc-issuer','https://token.actions.githubusercontent.com',ref],300)


class Compose:
    def __init__(self, tree, cfg, command=run):
        self.tree=tree; self.cfg=cfg; self.command=command
        self.docker=['docker','--host','unix:///var/run/docker.sock']
        self.base=self.docker+['compose','--project-name',cfg['project'],'--project-directory',str(tree),'--env-file',cfg['env'],'--profile','*']
        for f in ['compose.yaml']+['compose.'+x+'.yaml' for x in cfg['overlays']]:
            require((tree/f).is_file(),'Missing signed Compose overlay: '+f); self.base+=['-f',str(tree/f)]

    def call(self,*args,**kw):
        try: return self.command(self.base+list(args),**kw)
        except RuntimeError as e: raise RuntimeError('Compose '+args[0]+' failed; inspect local operator logs') from e
    def dc(self,*args,**kw):
        try: return self.command(self.docker+list(args),**kw)
        except RuntimeError as e: raise RuntimeError('Docker '+args[0]+' failed') from e
    def config(self,m):
        raw=self.call('config','--format','json')
        # Compose v2.38.2 escapes every dollar in rendered JSON so that it can
        # be reused as a Compose file. Other versions may emit literal dollars.
        # Probe the installed CLI, never accept two possible secret values.
        if b'$' in raw:
            with tempfile.TemporaryDirectory(prefix='book-compose-probe-') as tmp:
                probe=Path(tmp)/'compose.json'; env=Path(tmp)/'empty.env'; env.touch()
                probe.write_text(json.dumps({'services':{'probe':{'image':'busybox:1.37','environment':{'BOOK_CONFIG_PROBE':'$$book_config_probe'}}}}))
                result=json.loads(self.command(self.docker+['compose','--project-name','book-config-probe','--project-directory',tmp,'--env-file',str(env),'-f',str(probe),'config','--format','json']))
                value=result['services']['probe']['environment']['BOOK_CONFIG_PROBE']
                require(value in ('$book_config_probe','$$book_config_probe'),'Unsupported Compose dollar rendering')
                if value=='$$book_config_probe': raw=raw.replace(b'$$',b'$')
        c=json.loads(raw); services=c['services']
        require('book' in services and set(services)<=SERVICES,'Unsupported Compose topology')
        require(all('build' not in s and 'image' in s for s in services.values()),'Source builds are forbidden')
        require(all(services[n]['image']==m['images']['book'] for n in ROLES & set(services)), 'Unpinned Book service')
        if 'pitr' in self.cfg['overlays']: require(services['postgres']['image']==m['images']['postgres-pitr'],'Unpinned PITR image')
        return c

    def validate_container(self, obj, config, n):
        service=config['services'][n]; actual={m['Destination']:m for m in obj['Mounts']}
        wanted=service.get('volumes',[])
        for v in wanted:
            require(isinstance(v,dict) and v.get('type') in ('volume','bind') and v.get('source'),'Unsupported anonymous or special mount')
            dest=v['target']; m=actual.get(dest)
            require(m is not None and m['Type']==v['type'] and m['RW']==(not v.get('read_only',False)),'Container mount mismatch: '+n+' '+dest)
            if v['type']=='volume':
                name=config['volumes'][v['source']]['name']; require(m['Name']==name,'Container uses wrong data volume: '+n+' '+dest)
            else:
                src=Path(v['source']); live=Path(m['Source'])
                if src.is_relative_to(self.tree):
                    # Enrollment can adopt an existing verified deployment in another directory.
                    require(same_bind(src,live),'Mounted release configuration differs: '+n+' '+dest)
                else: require(src==live,'External bind mount changed: '+n+' '+dest)
        # Disallow undeclared mounts that can mask executable code or redirect storage.
        extra=set(actual)-{v['target'] for v in wanted}
        # Redis upstream declares /data as an anonymous volume; Book config disables persistence.
        require(not extra or (n=='redis' and extra=={'/data'} and actual['/data']['Type']=='volume'),'Unexpected container mounts: '+n)
        env={x.split('=',1)[0]:x.split('=',1)[1] for x in obj['Config']['Env'] if '=' in x}
        for key,value in service.get('environment',{}).items():
            require(value is not None and env.get(key)==str(value),'Container environment differs: '+n+' '+key)
        image=json.loads(self.dc('image','inspect',service['image']))[0]
        # Compose JSON explicitly emits null for an inherited CMD/ENTRYPOINT.
        # An explicit non-empty entrypoint suppresses the image's default CMD
        # (Docker Engine merge semantics). Preserve explicit empty overrides;
        # never accept an alternative executable or argument list.
        entrypoint=service.get('entrypoint'); command=service.get('command')
        expected_entrypoint=image['Config']['Entrypoint'] if entrypoint is None else entrypoint
        expected_command=(None if entrypoint else image['Config']['Cmd']) if command is None else command
        require((obj['Config']['Entrypoint'] or [])==(expected_entrypoint or []),'Container entrypoint differs: '+n)
        require((obj['Config']['Cmd'] or [])==(expected_command or []),'Container command differs: '+n)
        return image['Id']

    def inventory(self, config):
        ids=self.dc('ps','-aq','--filter','label=com.docker.compose.project='+self.cfg['project']).decode().split()
        running={}
        for cid in ids:
            obj=json.loads(self.dc('inspect',cid))[0]; labels=obj['Config']['Labels']; n=labels.get('com.docker.compose.service')
            if labels.get('com.docker.compose.oneoff','').lower()=='true':
                require(not obj['State']['Running'],'Active Compose one-off; stop it before updating'); continue
            require(n in config['services'],'Orphan Compose container; reconcile project first')
            if obj['State']['Running']:
                ref=config['services'][n]['image']
                require(obj['Config']['Image']==ref,'Running container image differs from managed release: '+n)
                expected=self.validate_container(obj,config,n)
                require(obj['Image']==expected,'Running container image ID mismatch: '+n)
                running[n]=running.get(n,0)+1
        require(running.get('book',0)>0,'No running Book service to update')
        for n in INFRA & set(config['services']): require(running.get(n)==1,'Expected exactly one running '+n)
        return running

    def pull(self,m):
        for r in sorted({m['images']['book']}):
            try: self.dc('image','inspect',r)
            except RuntimeError: self.dc('pull',r,timeout=900)

    def stop(self, config):
        names=sorted(set(config['services'])-INFRA)
        # Stop edge first, then all application writers and monitor containers; do not stop DB/Redis.
        if 'proxy' in names: self.call('stop','-t','120','proxy',timeout=180)
        if names: self.call('stop','-t','960',*names,timeout=1100)
        # Never assume that a stopped client implies all containers stopped.
        ids=self.dc('ps','-q','--filter','label=com.docker.compose.project='+self.cfg['project']).decode().split()
        for cid in ids:
            ob=json.loads(self.dc('inspect',cid))[0]; require(ob['Config']['Labels'].get('com.docker.compose.service') in INFRA,'Application process still running; refuse offline mutation')

    def one(self,*args,mount=None,timeout=2400):
        name=self.cfg['project']+'-book-update-task'
        cmd=['run','--rm','--no-deps','--pull','never','-T','--name',name,'--user','www-data']
        if mount: cmd+=['-v',str(mount)+':/book-update-backup']
        return self.call(*cmd,'book',*args,timeout=timeout)

    def cleanup_task(self):
        name=self.cfg['project']+'-book-update-task'
        ids=self.dc('ps','-aq','--filter','name=^/'+name+'$').decode().split()
        if ids:
            require(len(ids)==1,'Ambiguous update task')
            ob=json.loads(self.dc('inspect',ids[0]))[0]
            lab=ob['Config']['Labels']; require(lab.get('com.docker.compose.project')==self.cfg['project'] and lab.get('com.docker.compose.service')=='book' and lab.get('com.docker.compose.oneoff','').lower()=='true','Unexpected update task container')
            self.dc('rm','-f',ids[0],timeout=180)

    def migrations(self,*args): return json.loads(self.one('php','bin/migrations.php',*args))
    def check_version(self,m):
        require(self.one('cat','VERSION').decode().strip()==m['version'],'Container VERSION mismatch')
    def check(self,m,sql):
        self.check_version(m)
        self.one('php','-r',"require 'bootstrap.php'; CajeerBook\\ReleaseHealth::check(getcwd());")
        if sql:
            r=self.migrations('verify'); require(r['current']==m['schema_version'] and r['compatible'],'Schema verification failed')
        self.one('php','bin/installation.php','check')
        if sql:
            self.one('php','bin/postgres-content.php','check'); self.one('php','bin/search.php','check'); self.one('php','bin/audit.php','check')
        if 'redis' in self.cfg['overlays'] or 'redis-sentinel' in self.cfg['overlays']: self.one('php','bin/runtime.php','check')

    def backup(self, directory, sql):
        directory.mkdir(mode=0o700); os.chown(directory,33,33)
        self.one('php','bin/installation.php','backup','/book-update-backup/portable',mount=directory)
        # Restore to an isolated portable directory and verify before touching schema.
        self.one('sh','-ec','unset BOOK_PG_DSN BOOK_PG_NAMESPACE BOOK_PG_USER BOOK_PG_PASSWORD BOOK_REDIS_HOST BOOK_REDIS_SENTINELS; BOOK_DATA_DIR=/book-update-backup/drill php bin/installation.php restore /book-update-backup/portable',mount=directory)
        if sql:
            # Dedicated Book DB, native dump preserves SQL ledger and audit (portable restore does not).
            with (directory/'database.dump').open('xb') as f:
                self.call('exec','-T','postgres','pg_dump','-Fc','-U','book_admin','-d','book',stdout=f,timeout=2400)
                f.flush(); os.fsync(f.fileno())
            require((directory/'database.dump').stat().st_size>0,'Empty database dump')
            with (directory/'database.dump').open('rb') as f:
                self.call('exec','-T','postgres','pg_restore','--list',stdin=f,timeout=180)
        # Persist the actual backup files and directory entries before the migration journal advances.
        for d,dirs,files in os.walk(directory,topdown=False):
            for n in files:
                p=Path(d)/n; require(p.is_file() and not p.is_symlink(),'Unexpected backup file type')
                with p.open('rb') as f: os.fsync(f.fileno())
            syncdir(d)
        syncdir(directory.parent)

    def start_service(self, config, n, replicas):
        # Historical workers explicitly disable the image's HTTP healthcheck.
        # Compose --wait can reject these containers; never skip the check for
        # a service with an enabled/inherited healthcheck.
        disabled=(config['services'][n].get('healthcheck') or {}).get('disable') is True
        require(not disabled or n not in {'book','proxy'},'Required application readiness check disabled: '+n)
        args=['up','-d','--no-deps','--no-build','--pull','never']
        if not disabled: args+=['--wait','--wait-timeout','180']
        self.call(*args,'--scale',f'{n}={replicas}',n,timeout=300)
        if disabled:
            deadline=time.monotonic()+180; previous=None
            while time.monotonic()<deadline:
                ids=sorted(self.call('ps','-q',n).decode().split())
                if len(ids)==replicas:
                    states=[json.loads(self.dc('inspect',cid))[0] for cid in ids]
                    if all(o['State']['Running'] and not o['State'].get('Restarting',False) for o in states):
                        current=[(cid,o.get('RestartCount',0),o['State'].get('StartedAt')) for cid,o in zip(ids,states)]
                        if current==previous: return
                        previous=current
                    else: previous=None
                else: previous=None
                time.sleep(2)
            raise RuntimeError('Service did not stabilize: '+n)

    def activate(self, config, running):
        # Remove stopped old service containers, never volumes; retain DB and Redis unchanged.
        names=sorted(set(config['services'])-INFRA)
        if names: self.call('rm','-f',*names,timeout=180)
        self.start_service(config,'book',running['book'])
        for n in sorted(set(running)-INFRA-{'book','proxy'}): self.start_service(config,n,running[n])
        if 'proxy' in running: self.start_service(config,'proxy',running['proxy'])
        require(self.inventory(config)==running,'Replica inventory differs after activation')


def normalized(c,tree):
    def norm(v):
        if isinstance(v,dict): return {k:norm(x) for k,x in v.items()}
        if isinstance(v,list): return [norm(x) for x in v]
        if isinstance(v,str) and (v==str(tree) or v.startswith(str(tree)+'/')): return '@release'+v[len(str(tree)):]
        return v
    c=norm(c)
    for n in ROLES & set(c['services']):
        c['services'][n]['image']='@book'
        # Signed application healthchecks evolve with application code (and are
        # enforced by start_service). Infrastructure and all other topology stay strict.
        c['services'][n].pop('healthcheck',None)
    return c


class Manager:
    def __init__(self, root, command=run, verify_oci=oci):
        self.root=Path(root); self.command=command; self.verify_oci=verify_oci
        require(self.root.is_absolute() and self.root.resolve()==self.root,'State directory must be absolute without symlinks')
        self.root.mkdir(mode=0o700,exist_ok=True)
        for parent in self.root.parents:
            st=parent.stat(); require(st.st_uid in (0,os.geteuid()) and (not st.st_mode & 0o022 or (st.st_uid==0 and st.st_mode & stat.S_ISVTX)),'Untrusted state directory ancestor')
        s=self.root.stat(); require(s.st_uid==os.geteuid() and stat.S_IMODE(s.st_mode)==0o700,'State directory must be operator-owned 0700')
        for d in ('releases','backups'):
            p=self.root/d; p.mkdir(mode=0o700,exist_ok=True)
            require(p.resolve()==p and p.stat().st_uid==os.geteuid() and stat.S_IMODE(p.stat().st_mode)==0o700,'Invalid private state subdirectory')
        self.statefile=self.root/'state.json'; self.pub=self.root/'release-public.pem'

    @contextlib.contextmanager
    def locked(self):
        fd=os.open(self.root/'lock',os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
        with os.fdopen(fd,'w') as f:
            try: fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError: raise RuntimeError('Another update is in progress')
            yield

    def read(self,allow_env_change=False):
        s=release.json_read(self.statefile); require(s['format']==1,'Unsupported updater state')
        current_sha=release.sha(private_file(Path(s['config']['env'])))
        require(allow_env_change or current_sha==s['config']['env_sha'],'Operator environment changed; reconcile before updating')
        require(hashlib.sha256(release.key_der(self.pub,True)).hexdigest()==s['key'],'Pinned release key changed')
        return s

    def save(self,s): atomic(self.statefile,s)

    def stage(self,cfg,v,bundle=None,commit=None):
        version(v); repo=cfg['repository']; require(release.REPO.fullmatch(repo),'Invalid repository')
        with tempfile.TemporaryDirectory(prefix='.download-',dir=self.root) as tmp:
            temp=Path(tmp); dest=temp/'bundle'; dest.mkdir(mode=0o700)
            def get(n,expected=None):
                if bundle is not None:
                    require(Path(bundle).is_dir() and not Path(bundle).is_symlink() and {p.name for p in Path(bundle).iterdir()}==all_files(v),'Unexpected or missing local release files')
                    p=release.regular(Path(bundle)/n)
                    with p.open('rb') as f: copy_stream(f,dest/n,limit(n),expected)
                else: download(f'https://github.com/{repo}/releases/download/v{v}/{n}',dest/n,limit(n),expected)
            get('release.json'); get('release.json.sig',64)
            m=manifest_header(dest,self.pub,repo,v)
            require(commit is None or m['commit']==commit,'Unexpected commit')
            for n in sorted(all_files(v)-{'release.json','release.json.sig'}): get(n,64 if n.endswith('.sig') else m['artifacts'][n]['size'])
            m=verify_bundle(dest,self.pub,repo,v,commit)
            self.verify_oci(m)
            identifier=v+'-'+m['commit']; final=self.root/'releases'/identifier
            receipt={'id':identifier,'version':v,'commit':m['commit'],'schema':m['schema_version'],'manifest':release.sha(dest/'release.json')}
            if final.exists():
                self.load(cfg,receipt); return receipt
            unpack(dest,temp/'tree'); syncdir(dest); syncdir(temp)
            # Move a complete verified directory into the private version store.
            stage=temp/'complete'; stage.mkdir(); dest.rename(stage/'bundle'); (temp/'tree').rename(stage/'tree'); syncdir(stage)
            stage.rename(final); syncdir(final.parent)
            return receipt

    def load(self,cfg,r):
        require(r['id']==r['version']+'-'+r['commit'] and release.COMMIT.fullmatch(r['commit']),'Invalid release receipt')
        version(r['version']); p=self.root/'releases'/r['id']; require(p.resolve()==p,'Invalid release directory')
        m=verify_bundle(p/'bundle',self.pub,cfg['repository'],r['version'],r['commit'])
        require(release.sha(p/'bundle'/'release.json')==r['manifest'] and m['schema_version']==r['schema'],'Stored release receipt mismatch')
        verify_tree(p/'bundle',p/'tree')
        return Compose(p/'tree',cfg,self.command),m

    def enroll(self,a):
        require(not self.statefile.exists(),'Already enrolled')
        require(re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,39}',a.project),'Invalid Compose project')
        overlays=a.overlays.split(',') if a.overlays else []
        require(len(overlays)==len(set(overlays)) and set(overlays)<=set(OVERLAYS),'Invalid overlays')
        require(not {'redis','redis-sentinel'}<=set(overlays),'Choose one Redis topology')
        require('shared-storage' not in overlays,'Multi-host shared storage is not supported by this single-host updater')
        cfg={'project':a.project,'repository':a.repository,'overlays':overlays,'env':str(private_file(a.env_file)),'env_sha':release.sha(a.env_file)}
        pub=Path(a.public_key); require(pub.is_absolute() and pub.resolve()==pub,'Invalid public key path'); der=release.key_der(pub,True)
        # No key replacement once an installation is enrolled. A failed enrollment may be retried with the same key only.
        if self.pub.exists(): require(release.key_der(self.pub,True)==der,'Enrollment key already pinned')
        else:
            with self.pub.open('xb') as f: f.write(pub.read_bytes()); f.flush(); os.fsync(f.fileno())
            self.pub.chmod(0o400); syncdir(self.root)
        r=self.stage(cfg,a.version,a.bundle,a.commit); c,m=self.load(cfg,r); conf=c.config(m); running=c.inventory(conf)
        c.check(m,'postgres' in conf['services'])
        s={'format':1,'config':cfg,'key':hashlib.sha256(der).hexdigest(),'current':r,'previous':None,'highest':r,'pending':None}
        self.save(s); return s

    def enable_agent_overlay(self, s):
        """Operator-only adoption of the signed, tightly scoped socket mount after deployment.

        Requires the operator to recreate the Book container with the new signed
        overlay first. The new live topology must match *only* the expected mount
        and environment addition, then is re-pinned in the durable updater state.
        """
        require(s['pending'] is None, 'Recover pending update before topology adoption')
        oldcfg=s['config']
        require('update-agent' not in oldcfg['overlays'], 'Update agent overlay is already enrolled')
        cfg=copy.deepcopy(oldcfg)
        cfg['overlays'].append('update-agent')
        old, om=self.load(oldcfg,s['current'])
        new, nm=self.load(cfg,s['current'])
        before=normalized(old.config(om),old.tree)
        after=new.config(nm)
        candidate=normalized(after,new.tree)
        require(set(candidate['services'])==set(before['services']) and 'book' in candidate['services'], 'Overlay changed service topology')
        base=copy.deepcopy(candidate)
        book=base['services']['book']
        env=book.get('environment',{})
        require(env.pop('BOOK_UPDATE_AGENT_SOCKET',None)=='/run/book-update/agent.sock', 'Unexpected agent socket setting')
        mounts=book.get('volumes',[])
        added=[v for v in mounts if v.get('target')=='/run/book-update']
        require(len(added)==1 and added[0].get('type')=='bind' and added[0].get('source')=='/run/cajeerbook'
                and added[0].get('read_only') is True, 'Unexpected agent socket mount')
        book['volumes']=[v for v in mounts if v.get('target')!='/run/book-update']
        require(base==before, 'Agent overlay changed unrelated runtime settings')
        require(Path('/run/cajeerbook/agent.sock').is_socket(), 'Trusted host agent socket is not running')
        # Recreated containers must already run the original signed image and
        # exactly the newly permitted bind/environment. No mixed topology accepted.
        new.inventory(after)
        new.check(nm,'postgres' in after['services'])
        s['config']=cfg
        self.save(s)
        return {'status':'adopted','overlay':'update-agent','current':s['current']['version']}

    def reconcile_env(self,s):
        require(s['pending'] is None,'Restore the original environment before recovering a pending update')
        cfg=s['config']; c,m=self.load(cfg,s['current']); conf=c.config(m); c.inventory(conf); c.check(m,'postgres' in conf['services'])
        cfg['env_sha']=release.sha(private_file(Path(cfg['env']))); self.save(s); return s

    def plan(self,s,target):
        require(target['schema']>=s['current']['schema'],'Schema downgrade denied')
        cfg=s['config']; old,om=self.load(cfg,s['current']); new,nm=self.load(cfg,target)
        oc=old.config(om); nc=new.config(nm)
        require(normalized(oc,old.tree)==normalized(nc,new.tree),'Compose topology/config changed (including infrastructure image); manual infrastructure upgrade required')
        for n in INFRA & set(oc['services']):
            for v in oc['services'][n].get('volumes',[]):
                if v['type']=='bind' and Path(v['source']).is_relative_to(old.tree):
                    require(same_bind(v['source'],new.tree/Path(v['source']).relative_to(old.tree)),'Infrastructure bind content changed; manual upgrade required')
        running=old.inventory(oc)
        return old,om,oc,new,nm,nc,running

    def begin(self,s,target,rollback=False,allow_schema=False):
        require(s['pending'] is None,'Unfinished update; use recover')
        if rollback:
            require(target==s['previous'] and target['schema']==s['current']['schema'],'Rollback requires retained previous release with same schema')
        else:
            require(version(target['version'])>version(s['current']['version']),'Downgrade or replay denied')
            hi=s['highest']; require(version(target['version'])>version(hi['version']) or target==hi,'Version below highest accepted release or conflicting same-version release')
            require(target['schema']>=s['current']['schema'],'Schema downgrade denied')
            if target['schema']!=s['current']['schema']:
                require(allow_schema and version(s['current']['version'])>=(0,8,1),'Schema changes require explicit permission and a rollback-aware current release')
        old,om,oc,new,nm,nc,running=self.plan(s,target)
        self.verify_oci(nm); new.pull(nm)
        # Persist the full plan before the first stop; high-water never decreases, even on rollback.
        tx={'from':s['current'],'to':target,'running':running,'phase':'stopping','backup':time.strftime('%Y%m%dT%H%M%SZ',time.gmtime())+'-'+os.urandom(8).hex(),'rollback':rollback,'started_at':int(time.time()),'backup_complete':False}
        s['pending']=tx
        if not rollback: s['highest']=target
        self.save(s)
        sql='postgres' in oc['services']
        try:
            old.stop(oc); tx['phase']='backup'; self.save(s)
            old.check(om,sql); old.backup(self.root/'backups'/tx['backup'],sql)
            tx['backup_complete']=True; self.save(s)
            new.check_version(nm)
            tx['phase']='migration'; self.save(s)
            if sql:
                plan=new.migrations('status')
                require(plan['current']==om['schema_version'] and plan['required']==nm['schema_version'] and all(x['reversible'] is True for x in plan['pending']),'Unsupported migration plan')
                p=new.migrations('preflight')
                require(p['preflight']=='passed' and p['dry_run_rolled_back'] is True and p['current']==om['schema_version'] and p['required']==nm['schema_version'],'Migration preflight mismatch')
                require(all(x['reversible'] is True for x in p['pending']),'Irreversible migration denied')
                result=new.migrations('apply','--offline'); require(result['from']==om['schema_version'] and result['to']==nm['schema_version'],'Migration result mismatch')
            new.check(nm,sql)
            # From here the new version can write. Recovery requires an explicit compatibility acknowledgement.
            tx['phase']='activating'; self.save(s)
            new.activate(nc,running)
            s['previous']=None if rollback else tx['from']; s['current']=target; s['last_transaction']=dict(tx,result='completed',finished_at=int(time.time())); s['pending']=None; self.save(s)
            return s
        except Exception:
            # Before activation, automatic rollback is safe. On unknown outcome leave a durable journal.
            if tx['phase']!='activating':
                try: self.recover(s,False)
                except Exception:
                    print('Automatic rollback did not complete; keep traffic fenced and run recover',file=sys.stderr)
            else:
                try: new.cleanup_task(); new.stop(nc)
                except Exception:
                    print('Could not confirm all services stopped; fence traffic immediately',file=sys.stderr)
            raise

    def recover(self,s,accept):
        tx=s['pending']; require(tx is not None,'No pending update')
        require(tx['phase']!='activating' or accept,'Activation began; assess data compatibility and pass --accept-data-compatibility to recover')
        cfg=s['config']; old,om=self.load(cfg,tx['from']); new,nm=self.load(cfg,tx['to'])
        oc=old.config(om); nc=new.config(nm)
        require(normalized(oc,old.tree)==normalized(nc,new.tree),'Compose configuration mismatch')
        new.cleanup_task(); new.stop(nc)
        sql='postgres' in oc['services']
        if sql:
            # The old catalog suffices if no migration committed, even if the candidate
            # image itself failed. A higher applied schema must be handled by the new catalog.
            old.pull(om)
            try:
                prior=old.migrations('status')
                require(prior['current']==om['schema_version'] and prior['compatible'],'Old schema is not active')
            except (RuntimeError,ValueError,KeyError):
                require(tx['phase']=='migration' and om['schema_version']<nm['schema_version'],'Schema not compatible; manual recovery required')
                status=new.migrations('status'); cur=status['current']
                require(cur==nm['schema_version'],'Unexpected database schema; manual recovery required')
                res=new.migrations('rollback',str(om['schema_version']),'--offline'); require(res['to']==om['schema_version'],'Schema rollback failed')
        old.pull(om); old.check(om,sql); old.activate(oc,tx['running'])
        s['current']=tx['from']; s['last_transaction']=dict(tx,result='rolled-back',finished_at=int(time.time())); s['pending']=None; self.save(s); return s


@contextlib.contextmanager
def project_lock(project, directory=Path('/run/lock')):
    require(re.fullmatch(r'[a-z0-9][a-z0-9_-]{0,39}',project),'Invalid Compose project')
    path=directory/('cajeerbook-update-'+project+'.lock')
    fd=os.open(path,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    with os.fdopen(fd,'w') as f:
        st=os.fstat(f.fileno())
        require(stat.S_ISREG(st.st_mode) and st.st_uid==os.geteuid() and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o600,'Unsafe project lock')
        try: fcntl.flock(f,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError: raise RuntimeError('Another updater is operating on this Compose project')
        yield

def main():
    p=argparse.ArgumentParser(description=__doc__); p.add_argument('--state-dir',type=Path,required=True)
    sub=p.add_subparsers(dest='action',required=True)
    e=sub.add_parser('enroll'); e.add_argument('--project',required=True); e.add_argument('--repository',required=True); e.add_argument('--env-file',type=Path,required=True); e.add_argument('--public-key',type=Path,required=True); e.add_argument('--overlays',default='')
    for a in (e,sub.add_parser('fetch'),sub.add_parser('preflight'),sub.add_parser('apply')):
        a.add_argument('--version',required=True); a.add_argument('--bundle',type=Path); a.add_argument('--commit')
    a=sub.choices['apply']; a.add_argument('--offline',action='store_true',required=True); a.add_argument('--allow-schema-upgrade',action='store_true')
    for n in ('rollback','recover'):
        q=sub.add_parser(n); q.add_argument('--offline',action='store_true',required=True); q.add_argument('--accept-data-compatibility',action='store_true')
    check=sub.add_parser('check', help='Discover signed GitHub Releases without installing them')
    check.add_argument('--channel', choices=('stable', 'beta'), default='stable')
    check.add_argument('--refresh', action='store_true', help='Ignore cached GitHub ETags')
    sub.add_parser('status'); sub.add_parser('reconcile-env'); sub.add_parser('enable-agent-overlay')
    args=p.parse_args()
    try:
        os.umask(0o077)
        require(os.geteuid()==0,'Run as root on the local Docker host (private backup bind mount requires UID 33)')
        mgr=Manager(args.state_dir)
        with mgr.locked():
            s=None if args.action=='enroll' else mgr.read(allow_env_change=args.action in ('reconcile-env','status','check'))
            project=args.project if s is None else s['config']['project']
            with project_lock(project):
                if args.action=='enroll': result=mgr.enroll(args)
                else:
                    if args.action=='check':
                        spec=importlib.util.spec_from_file_location('book_update_discovery', Path(__file__).with_name('update-discovery.py'))
                        discovery=importlib.util.module_from_spec(spec); spec.loader.exec_module(discovery)
                        cfg=s['config']; cache=discovery.cache_load(mgr.root,cfg['repository'])
                        result,new_cache=discovery.discover(
                            cfg['repository'],s['current'],s['highest'],mgr.pub,
                            channel=args.channel,cache=cache,refresh=args.refresh,
                            download=download,manifest_header=manifest_header,
                            pending=s['pending'] is not None,
                            env_changed=release.sha(private_file(Path(cfg['env'])))!=cfg['env_sha'])
                        discovery.cache_save(mgr.root,new_cache)
                    elif args.action=='status': result=dict(s,env_changed=release.sha(private_file(Path(s['config']['env'])))!=s['config']['env_sha'])
                    elif args.action=='reconcile-env': result=mgr.reconcile_env(s)
                    elif args.action=='enable-agent-overlay': result=mgr.enable_agent_overlay(s)
                    elif args.action=='recover': result=mgr.recover(s,args.accept_data_compatibility)
                    elif args.action=='rollback':
                        require(args.accept_data_compatibility and s['previous'] is not None,'Rollback requires explicit data compatibility acknowledgement and a previous release')
                        result=mgr.begin(s,s['previous'],True)
                    else:
                        require(s['pending'] is None,'Unfinished update; use recover')
                        require(version(args.version)>version(s['current']['version']) and version(args.version)>=version(s['highest']['version']),'Downgrade or replay denied')
                        r=mgr.stage(s['config'],args.version,args.bundle,args.commit)
                        require(version(r['version'])>version(s['highest']['version']) or r==s['highest'],'Conflicting highest-version release')
                        if args.action=='fetch': result=r
                        elif args.action=='preflight':
                            old,om,oc,new,nm,nc,running=mgr.plan(s,r)
                            new.pull(nm)
                            result={'status':'passed','current':s['current'],'candidate':r,'running':running,'schema_change':r['schema']!=s['current']['schema'],'offline_sql_preflight_required':'postgres' in oc['services']}
                        else: result=mgr.begin(s,r,allow_schema=args.allow_schema_upgrade)
            print(json.dumps(result,indent=2,sort_keys=True))
            if args.action=='check' and result['status'] in ('rejected','blocked','incomplete'):
                return 2
    except (OSError,ValueError,KeyError,TypeError,RuntimeError,subprocess.CalledProcessError,zipfile.BadZipFile,urllib.error.URLError) as e:
        print('Update rejected: '+str(e),file=sys.stderr); return 1
    return 0

if __name__=='__main__': sys.exit(main())
