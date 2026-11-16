#!/usr/bin/env python3
"""Destructive qualification on a disposable LOCAL Docker host, never an existing project.
Real historical source, real updater, one SQL/media installation through upgrade,
rollback, replica failure and physical recovery. Local fixture signatures/registry
are intentionally not official release evidence or a Cosign test.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import socket
import signal
import subprocess
import tarfile
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]

def load(name, path):
    s = importlib.util.spec_from_file_location(name, path)
    m = importlib.util.module_from_spec(s); s.loader.exec_module(m); return m

u = load('qualification_update', ROOT/'bin/update.py')
http = load('qualification_http', ROOT/'tests/release-http.py')
fixture = load('qualification_fixture', ROOT/'tests/production-qualification-fixture.py')
DOCKER = ['docker', '--host', 'unix:///var/run/docker.sock']
PREVIOUS = '0.8.2'
http.PASSWORD = 'release-test-only-password'
SAFE_ENV = {k:os.environ[k] for k in ('PATH','HOME','LANG','LC_ALL','DOCKER_CONFIG','SSL_CERT_FILE','SSL_CERT_DIR') if k in os.environ}
os.environ.pop('DOCKER_CONTEXT',None)
os.environ['DOCKER_HOST']='unix:///var/run/docker.sock'
os.environ['COMPOSE_DISABLE_ENV_FILE']='1'
STEPS = ('source-history','docker-preflight','build-actual-0.8.2','build-actual-target','build-physical-postgres','seed-historical-http','enroll','upgrade','rollback','reupgrade','replica-redis-recovery','physical-backup-and-wal','destroy-source','physical-restore-and-http','cleanup')


def require(ok, message):
    if not ok: raise RuntimeError(message)


def call(args, cwd=None, data=None, timeout=180, env=None):
    p = subprocess.run([str(x) for x in args], cwd=cwd, input=data, stdout=subprocess.PIPE,
                       stderr=subprocess.PIPE, timeout=timeout, env=SAFE_ENV if env is None else env)
    if p.returncode:
        # Only synthetic credentials are used here; avoid echoing command arguments or output.
        raise RuntimeError(f'{args[0]} operation failed (exit {p.returncode})')
    return p.stdout.decode().strip()


def docker(*args, **kw): return call(DOCKER+list(args), **kw)
def git(root, *args): return call(['git','-c','safe.directory='+str(root),*args], cwd=root)


def revisions(root, ref=None):
    head = git(root,'rev-parse','HEAD'); version = git(root,'show',head+':VERSION')
    require(version == (root/'VERSION').read_text().strip() and u.version(version) > u.version(PREVIOUS), 'Expected committed qualification version newer than 0.8.2')
    require(not git(root,'status','--porcelain','--untracked-files=no'), 'Use a clean committed checkout')
    if ref:
        prior = git(root,'rev-parse','--verify',ref+'^{commit}')
    elif git(root,'tag','--list','v'+PREVIOUS):
        prior=git(root,'rev-parse','refs/tags/v'+PREVIOUS+'^{commit}')
    else:
        prior = next((c for c in git(root,'log','--format=%H',head,'--','VERSION').splitlines()
                      if git(root,'show',c+':VERSION') == PREVIOUS), None)
    require(prior and prior != head and git(root,'show',prior+':VERSION') == PREVIOUS, 'Actual 0.8.2 source commit is required (full Git history or --previous-ref)')
    git(root,'merge-base','--is-ancestor',prior,head)
    return prior, head, version


def archive(root, commit, dest):
    dest.mkdir(); tar = dest.parent/(dest.name+'.tar')
    try:
        call(['git','-c','safe.directory='+str(root),'archive','--format=tar','--output='+str(tar),commit],cwd=root)
        with tarfile.open(tar) as t:
            for m in t.getmembers(): require(m.isfile() or m.isdir(), 'Unexpected Git archive member')
            t.extractall(dest,filter='data')
    finally: tar.unlink(missing_ok=True)


def ready(predicate, label, seconds=180):
    end = time.monotonic()+seconds
    while time.monotonic() < end:
        try:
            if predicate(): return
        except (OSError, RuntimeError, ValueError): pass
        time.sleep(1)
    raise RuntimeError(label+' timed out')


def snapshot(base):
    c = http.Client(base)
    _,_,raw = c.request('/api/v1/pages/'+http.NEW)
    page = json.loads(raw)
    require('Mixed release content' in page['body'] and 'Unpublished secret release' not in page['body'], 'Published snapshot invalid')
    mid = re.search(r'/media/([a-f0-9]{32})',page['body']).group(1)
    _,_,blob = c.request('/media/'+mid)
    require(blob == b'Release integration attachment\n', 'Media bytes differ')
    _,_,raw = c.request('/api/v1/search?q=Mixed&book=release')
    require(http.NEW in [x['key'] for x in json.loads(raw)], 'Search does not return restored page')
    _,_,raw = c.request('/api/v1/search?q=Unpublished%20secret%20release&book=release')
    require(not json.loads(raw), 'Draft leaked through search')
    _,h,_ = c.request('/docs/'+http.PAGE,expected=308)
    require(h['Location']=='/docs/'+http.NEW,'Redirect lost')
    return {'page':hashlib.sha256(json.dumps(page,sort_keys=True).encode()).hexdigest(),
            'media':hashlib.sha256(blob).hexdigest()}


def same_snapshot(base, expected):
    require(snapshot(base)==expected, 'Published page or media changed across transition')


def publication(base, slug, text):
    admin = http.Client(base, True)
    _,_,b = admin.request('/studio')
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', b).group(1).decode()
    _,_,b = admin.request('/studio','POST', {'csrf':csrf,'action':'publish','book':'recovery','version':'1','path':slug,
        'title':slug,'section':'Qualification','order':'1','body':text,'revision':'0'}, {'X-Book-Studio':'editor'})
    require(json.loads(b)['revision'] >= 1,'Recovery marker publication failed')
    _,_,b = http.Client(base).request('/api/v1/pages/recovery/1/'+slug)
    require(json.loads(b)['body']==text,'Recovery marker not visible')
    return admin


def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument('--report-dir',type=Path,required=True)
    p.add_argument('--previous-ref',help='Trusted ancestor commit containing VERSION 0.8.2; otherwise find version in Git history')
    p.add_argument('--candidate-book'); p.add_argument('--candidate-pitr')
    a=p.parse_args(); report=a.report_dir.resolve(); report.mkdir(mode=0o700,parents=True,exist_ok=False)
    os.umask(0o077)
    # The enclosing non-root CI runner must be able to collect non-secret evidence.
    owner = (int(os.environ['SUDO_UID']),int(os.environ['SUDO_GID'])) if os.geteuid()==0 and os.environ.get('SUDO_UID','').isdigit() and os.environ.get('SUDO_GID','').isdigit() else None
    if owner: os.chown(report,*owner)
    def interrupted(*_): raise KeyboardInterrupt('Qualification interrupted')
    signal.signal(signal.SIGTERM,interrupted)
    evidence={'format':1,'status':'failed','release':(ROOT/'VERSION').read_text().strip(),'steps':[],
              'rpo_limit_seconds':900,'rto_limit_seconds':3600,'scope':'disposable single-host Docker/SQL/Redis/PITR; test signatures'}
    def save():
        u.atomic(report/'lifecycle.json',evidence)
        if owner: os.chown(report/'lifecycle.json',*owner)
    def step(name,fn):
        t=time.monotonic(); print('QUALIFY',name,flush=True)
        try: result=fn()
        except BaseException:
            evidence['steps'].append({'name':name,'status':'failed','seconds':round(time.monotonic()-t,3)});save();raise
        evidence['steps'].append({'name':name,'status':'passed','seconds':round(time.monotonic()-t,3)});save();return result
    save()
    require(os.geteuid()==0,'Run as root on an isolated disposable Linux Docker host')
    require(not (bool(a.candidate_book)^bool(a.candidate_pitr)),'Provide both candidate images or neither')
    for ref in (a.candidate_book,a.candidate_pitr):
        require(ref is None or re.fullmatch(r'ghcr\.io/[a-z0-9_.-]+/[a-z0-9_.-]+@sha256:[0-9a-f]{64}',ref),'Invalid candidate reference')
    prior,head,version=step('source-history',lambda:revisions(ROOT,a.previous_ref))
    evidence.update(previous_commit=prior,commit=head);save()
    step('docker-preflight',lambda:docker('info',timeout=30))
    tag='book-qual-'+secrets.token_hex(6); project=tag; registry=tag+'-registry'; network=tag+'-recovery'; restored_db=tag+'-db'; restored_app=tag+'-app'; restvol=tag+'-rest'
    with tempfile.TemporaryDirectory(prefix='book-production-') as tmp:
        root=Path(tmp); oldsrc=root/'old'; newsrc=root/'new'; current=None; tags=[]; cleaned=False
        try:
            archive(ROOT,prior,oldsrc);archive(ROOT,head,newsrc)
            docker('run','-d','--name',registry,'-p','127.0.0.1::5000','registry:2')
            port=docker('port',registry,'5000/tcp').rsplit(':',1)[1]; local='127.0.0.1:'+port
            def registry_ready():
                with urllib.request.build_opener(urllib.request.ProxyHandler({})).open('http://'+local+'/v2/',timeout=2) as r: return r.status==200
            ready(registry_ready,'registry')
            def build(src,role,expected, candidate=None):
                name=tag+':'+role
                if candidate:
                    # Already pulled by protected candidate job. Do not rebuild or replace it.
                    docker('tag',candidate,name)
                    require(docker('image','inspect','--format','{{index .Config.Labels "org.opencontainers.image.revision"}}',name)==head,'Candidate revision mismatch')
                elif role=='pitr': docker('build','-f',str(src/'deploy/pitr/Dockerfile'),'-t',name,str(src),timeout=2400)
                else: docker('build','--target','production','-t',name,str(src),timeout=2400)
                tags.append(name)
                if expected: require(docker('run','--rm','--entrypoint','cat',name,'VERSION')==expected,'Actual image VERSION mismatch')
                image_id=docker('image','inspect','--format','{{.Id}}',name)
                ref=local+'/'+role+':qualified';docker('tag',name,ref);tags.append(ref);docker('push',ref,timeout=1200)
                refs=json.loads(docker('image','inspect','--format','{{json .RepoDigests}}',ref)); digest=next(x.rsplit('@',1)[1] for x in refs if x.startswith(local+'/'+role+'@'))
                immutable=local+'/'+role+'@'+digest;docker('pull',immutable,timeout=1200)
                require(docker('image','inspect','--format','{{.Id}}',immutable)==image_id,'Registry image ID changed')
                evidence.setdefault('images',{})[role]={'id':image_id,'test_ref':immutable,'candidate_ref':candidate};save()
                return immutable
            old=step('build-actual-0.8.2',lambda:build(oldsrc,'old',PREVIOUS))
            new=step('build-actual-target',lambda:build(newsrc,'new',version,a.candidate_book))
            pg=step('build-physical-postgres',lambda:build(newsrc,'pitr',None,a.candidate_pitr))
            # Test-only signed bundles, with actual deployment files from each respective commit.
            # The local registry is used only by this test, never by the production verifier.
            mapping={old.rsplit('@',1)[1]:old,new.rsplit('@',1)[1]:new,pg.rsplit('@',1)[1]:pg}
            u.release.image_ref=lambda repo,role,digest: mapping[digest]
            key=root/'private.pem';pub=root/'public.pem';call(['openssl','genpkey','-algorithm','ED25519','-out',key]);key.chmod(0o600);call(['openssl','pkey','-in',key,'-pubout','-out',pub])
            with socket.socket() as sock: sock.bind(('127.0.0.1',0)); httpport=sock.getsockname()[1]
            base='http://127.0.0.1:'+str(httpport)
            # Separate repository and sidecar survive deletion of every Compose volume.
            repo=root/'pgrepo'; remote=root/'remote'; repo.mkdir();remote.mkdir();os.chown(remote,33,33)
            config=root/'pgbackrest.conf';config.write_text('[global]\nrepo1-path=/repo\nrepo1-retention-full=2\nrepo1-cipher-type=aes-256-cbc\nrepo1-cipher-pass='+secrets.token_hex(32)+'\narchive-async=n\nlog-level-file=off\nlog-level-console=warn\n[book]\npg1-path=/var/lib/postgresql/data\npg1-user=book_admin\n');config.chmod(0o644)
            backupkey=root/'backup-key';backupkey.write_text(secrets.token_hex(32)+'\n');os.chown(backupkey,33,33);backupkey.chmod(0o400)
            docker('run','--rm','-v',str(repo)+':/repo','--entrypoint','sh',pg,'-ec','chown postgres:postgres /repo; chmod 700 /repo')
            password=secrets.token_hex(24); adminpass=secrets.token_hex(24); control=secrets.token_hex(24); namespace=secrets.token_hex(16); env=root/'runtime.env'
            ph=call(['php','-r',f'echo password_hash("{http.PASSWORD}", PASSWORD_DEFAULT);'])
            env.write_text(f"BOOK_ADMIN_HASH='{ph}'\nBOOK_SECURE_COOKIE=0\nBOOK_PG_PASSWORD={password}\nBOOK_PG_ADMIN_PASSWORD={adminpass}\nBOOK_PG_NAMESPACE={namespace}\nBOOK_REDIS_PASSWORD={secrets.token_hex(32)}\nBOOK_PITR_CONFIG_PATH={config}\nBOOK_PITR_REPOSITORY_PATH={repo}\nBOOK_PITR_PG_USER=book_pitr\nBOOK_PITR_PG_PASSWORD={control}\nBOOK_BACKUP_KEY_PATH={backupkey}\nBOOK_PITR_REMOTE_PATH={remote}\n");env.chmod(0o600)
            def bundle(src,v,image):
                tree=root/('bundle-src-'+v);tree.mkdir()
                for f in ('compose.yaml','compose.postgres.yaml','compose.redis.yaml','compose.distributed.yaml','compose.pitr.yaml','src/DatabaseMigrations.php','bin/postgres-init.sh','deploy/book-nginx.conf','deploy/book-remoteip.conf'):
                    dest=tree/f;dest.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(src/f,dest)
                for f in ('compose.yaml','compose.distributed.yaml'):
                    q=tree/f;q.write_text(q.read_text().replace('127.0.0.1:8080:80',f'127.0.0.1:{httpport}:80'))
                q=tree/'compose.pitr.yaml'; text=q.read_text().replace('      BOOK_BACKUP_S3_URI: ${BOOK_PITR_S3_URI:-}', '      BOOK_BACKUP_REMOTE_DIR: /qualification-remote\n      BOOK_BACKUP_S3_URI: ""').replace('      - book-pitr:/var/lib/cajeerpitr','      - book-pitr:/var/lib/cajeerpitr\n      - ${BOOK_PITR_REMOTE_PATH}:/qualification-remote');q.write_text(text)
                (tree/'VERSION').write_text(v+'\n'); git(tree,'init','-q');git(tree,'add','.');git(tree,'-c','user.name=Qualification','-c','user.email=test@example.invalid','commit','-qm','TEST fixture '+v);commit=git(tree,'rev-parse','HEAD')
                ev=root/('evidence-'+v);ev.mkdir(); digests={'book':image.rsplit('@',1)[1],'postgres-pitr':pg.rsplit('@',1)[1]};images={'book':image,'postgres-pitr':pg}
                u.release.dump(ev/'gate-evidence.json',{'version':v,'commit':commit,'repository':'Test/Book','run_id':'1','run_attempt':'1','gates':{g:'success' for g in u.release.gates_for(v)},'images':images,'image_checks':{r:{'smoke':'success','scan':'success'} for r in u.release.ROLES},'test_only':True,'production_qualification':fixture.write_fixture(u.release,ev,tree,v,commit,images)})
                for r in u.release.ROLES:
                    u.release.dump(ev/f'{r}-sbom.spdx.json',{'spdxVersion':'SPDX-2.3','packages':[{'name':'test fixture'}]});u.release.dump(ev/f'{r}-provenance.json',{'buildType':'test fixture','materials':[{'uri':'test fixture'}]});u.release.dump(ev/f'{r}-build-metadata.json',{'containerimage.digest':digests[r]});u.release.dump(ev/f'{r}-scan.json',{'Results':[{'Target':'test fixture'}]});(ev/f'{r}-smoke.log').write_text('TEST ONLY\n')
                out=root/('bundle-'+v);u.release.assemble(argparse.Namespace(root=tree,output=out,version=v,commit=commit,repository='Test/Book',key=key,public_key=pub,book_digest=digests['book'],pitr_digest=digests['postgres-pitr'],evidence=ev));return out
            a0=bundle(oldsrc,PREVIOUS,old);a1=bundle(newsrc,version,new)
            manager=u.Manager(root/'state',verify_oci=lambda m:[docker('pull',ref,timeout=900) for ref in m['images'].values()]);manager.pub.write_bytes(pub.read_bytes());manager.pub.chmod(0o400)
            overlays=['postgres','redis','distributed','pitr']; cfg={'project':project,'repository':'Test/Book','overlays':overlays,'env':str(env),'env_sha':u.release.sha(env)}
            initial=manager.stage(cfg,PREVIOUS,a0);current,m=manager.load(cfg,initial)
            def start():
                current.call('up','-d','--wait','--wait-timeout','120','postgres','redis',timeout=300)
                current.call('exec','-T','-u','postgres','postgres','pgbackrest','--stanza=book','stanza-create',timeout=300)
                current.one('php','bin/postgres-content.php','migrate','--offline')
                current.call('up','-d','--no-build','--wait','--wait-timeout','180','--scale','book=2','--scale','worker=2','book','worker','proxy',timeout=420)
                http.run(base)
                return snapshot(base)
            expected=step('seed-historical-http',start); evidence['content_sha256']=expected;save()
            s=step('enroll',lambda:manager.enroll(argparse.Namespace(project=project,repository='Test/Book',overlays=','.join(overlays),env_file=env,public_key=pub,version=PREVIOUS,bundle=a0,commit=None)))
            target=manager.stage(cfg,version,a1)
            def transition(s,target,rollback=False):
                s=manager.begin(s,target,rollback=rollback);require(s['current']==target and s['pending'] is None,'Update journal not committed')
                same_snapshot(base,expected);return s
            s=step('upgrade',lambda:transition(s,target));s=step('rollback',lambda:transition(s,initial,True));require(s['highest']==target,'Downgrade watermark lost');s=step('reupgrade',lambda:transition(s,target));current,m=manager.load(cfg,target)
            def failover():
                ids=current.call('ps','-q','book').decode().split();require(len(ids)==2,'Expected two app replicas');docker('stop','-t','1',ids[0]);require(docker('inspect','--format','{{.State.Running}}',ids[0])=='false','Replica did not stop');same_snapshot(base,expected);docker('start',ids[0]);current.call('up','-d','--wait','--wait-timeout','180','--scale','book=2','book',timeout=300)
                current.call('restart','redis',timeout=120);current.call('up','-d','--wait','--wait-timeout','120','redis',timeout=240);same_snapshot(base,expected)
                current.one('php','bin/admin.php','check','--deep')
            step('replica-redis-recovery',failover)
            def pgexec(*args, data=None): return current.call('exec','-T','-u','postgres','postgres',*args,stdin=None if data is None else data,timeout=1200).decode().strip()
            def pgjson(*args, data=None):
                if data is None:return json.loads(pgexec(*args))
                with tempfile.TemporaryFile() as inp:
                    inp.write(json.dumps(data).encode());inp.seek(0);return json.loads(pgexec(*args,data=inp))
            def sidecar(*args):return current.call('run','--rm','--no-deps','--pull','never','-T','pitr-sidecar','php','bin/pitr.php',*args,timeout=1800).decode()
            def capture():
                pgexec('psql','-U','book_admin','-d','book','-v','ON_ERROR_STOP=1','-c',f"CREATE ROLE book_pitr LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD '{control}'; GRANT CONNECT ON DATABASE book TO book_pitr; GRANT USAGE ON SCHEMA cajeer_book TO book_pitr; GRANT SELECT ON cajeer_book.content_installation TO book_pitr; GRANT EXECUTE ON FUNCTION pg_catalog.pg_control_system(), pg_catalog.pg_create_restore_point(text), pg_catalog.pg_switch_wal() TO book_pitr")
                pgexec('pgbackrest','--stanza=book','check')
                backup=pgjson('book-pitr-repo','backup')['backup']
                session=publication(base,'before','PITR before '+tag)
                point=json.loads(sidecar('capture',backup));point=json.loads(sidecar('inspect',point['id']))
                pgjson('book-pitr-repo','archive-check',data=point)
                publication(base,'after','PITR after '+tag)
                # Archive a later commit; recovery must stop before it, not at end-of-WAL.
                wal=pgexec('psql','-U','book_admin','-d','book','-Atc','SELECT pg_walfile_name(pg_current_wal_insert_lsn())');require(re.fullmatch(r'[0-9A-F]{24}',wal),'Invalid later WAL name')
                pgexec('psql','-U','book_admin','-d','book','-c','SELECT pg_switch_wal()')
                ready(lambda:bool(pgexec('sh','-ec',f'rm -f /tmp/qualification-later; pgbackrest --stanza=book archive-get {wal} /tmp/qualification-later >/dev/null && test -s /tmp/qualification-later && echo archived')), 'later WAL',180)
                return point,session
            point,session=step('physical-backup-and-wal',capture)
            evidence['restore_point']={'id':point['id'],'lsn':point['lsn'],'backup':point['backup'],'captured_at':point['captured_at']};save()
            # No source volume, SQL cluster, app media, local sidecar or local updater backup is mounted on recovery.
            lost_at=time.time();lost_clock=time.monotonic()
            def destroy():
                current.call('down','-v','--remove-orphans',timeout=420)
                for name in ('book-data','book-postgres','book-pitr'):
                    require(project+'_'+name not in docker('volume','ls','--format','{{.Name}}').splitlines(),'Source volume survived deletion')
            step('destroy-source',destroy);cleaned=True
            mounts=['-v',str(repo)+':/repo:ro','-v',str(config)+':/etc/pgbackrest/pgbackrest.conf:ro','-v',restvol+':/restore']
            recovery=root/'recovery';recovery.mkdir();os.chown(recovery,33,33);spool=recovery/'spool';spool.mkdir();os.chown(spool,33,33)
            docker('volume','create',restvol);docker('run','--rm','-v',restvol+':/restore','--entrypoint','sh',pg,'-ec','chown postgres:postgres /restore; chmod 700 /restore');docker('network','create',network)
            app_env=['-e','BOOK_PG_DSN=pgsql:host=postgres;port=5432;dbname=book;connect_timeout=5','-e','BOOK_PG_USER=book','-e','BOOK_PG_PASSWORD='+password,'-e','BOOK_PG_NAMESPACE='+namespace,'-e','BOOK_SECURE_COOKIE=0']
            restore_env=app_env+['-e','BOOK_DATA_DIR=/recovery/source','-e','BOOK_PITR_DIR=/recovery/spool','-e','BOOK_PITR_PG_USER=book_pitr','-e','BOOK_PITR_PG_PASSWORD='+control,'-e','BOOK_BACKUP_KEY_FILE=/run/backup-key','-e','BOOK_BACKUP_REMOTE_DIR=/remote']
            restore_mounts=['-v',str(recovery)+':/recovery','-v',str(remote)+':/remote:ro','-v',str(backupkey)+':/run/backup-key:ro']
            def restore():
                # Re-read authenticated sidecar from remote only, even after source destruction.
                raw=docker('run','--rm','--user','www-data',*restore_env,*restore_mounts,'--entrypoint','php',new,'bin/pitr.php','inspect',point['id'],timeout=1800)
                authenticated=json.loads(raw);require(authenticated==point,'Remote sidecar changed')
                docker('run','--rm','-i','--user','postgres',*mounts,'--entrypoint','book-pitr-repo',pg,'restore','/restore/pgdata','--isolated',data=raw.encode(),timeout=2400)
                docker('run','-d','--name',restored_db,'--network',network,'--network-alias','postgres','--user','postgres',*mounts,'--entrypoint','postgres',pg,'-D','/restore/pgdata','-c','archive_mode=off','-c','listen_addresses=*')
                ready(lambda:docker('exec',restored_db,'psql','-U','book_admin','-d','book','-Atc','SELECT pg_is_in_recovery()')=='f','PITR promotion',240)
                restored=json.loads(docker('run','--rm','--network',network,'--user','www-data',*restore_env,*restore_mounts,'--entrypoint','php',new,'bin/pitr.php','restore',point['id'],'/recovery/recovered','--isolated',timeout=2400))
                require(restored['status']=='restored_isolated' and restored['replay_lsn']==point['lsn'],'PITR restore result mismatch')
                # Fresh application container serves only the recovered media and restored database.
                docker('run','-d','--name',restored_app,'--network',network,'-p','127.0.0.1::80',*app_env,'-v',str(recovery/'recovered')+':/var/lib/cajeerbook',new)
                port=docker('port',restored_app,'80/tcp').rsplit(':',1)[1];dest='http://127.0.0.1:'+port;http.wait_ready(dest);same_snapshot(dest,expected)
                c=http.Client(dest);_,_,b=c.request('/api/v1/pages/recovery/1/before');require(json.loads(b)['body']=='PITR before '+tag,'Pre-target write missing');c.request('/api/v1/pages/recovery/1/after',expected=404)
                # A previously valid cookie must not regain access after restoring historic SQL.
                cookie='; '.join(x.name+'='+x.value for h in session.opener.handlers if hasattr(h,'cookiejar') for x in h.cookiejar)
                require('book_session=' in cookie,'Missing pre-disaster session');c.request('/studio',headers={'Cookie':cookie},expected=401);http.Client(dest,True).request('/studio')
                docker('exec','--user','www-data',restored_app,'php','bin/admin.php','check','--deep',timeout=1200)
                evidence['rpo_seconds']=lost_at-point['captured_at'];require(evidence['rpo_seconds']>=0,'Invalid recovery timestamp');evidence['rto_seconds']=round(time.monotonic()-lost_clock,3);save()
                require(evidence['rpo_seconds']<=900 and evidence['rto_seconds']<=3600,'Recovery SLO exceeded')
            step('physical-restore-and-http',restore)
            # Status is committed only after all disposable resources are removed.
        finally:
            # Capture limited logs from this disposable project before deleting its containers.
            # No inspect/env output or real operator credentials are written to evidence.
            if not cleaned or any(x['status']=='failed' for x in evidence['steps']):
                try:
                    ids=docker('ps','-aq','--filter','label=com.docker.compose.project='+project).splitlines()
                    for cid in [*ids,restored_db,restored_app]:
                        if not re.fullmatch(r'[a-z0-9-]+',cid):continue
                        logs=subprocess.run(DOCKER+['logs','--tail','300',cid],env=SAFE_ENV,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,timeout=30)
                        if logs.returncode==0:
                            path=report/('container-'+cid+'.log');path.write_bytes(logs.stdout[-512*1024:])
                            if owner:os.chown(path,*owner)
                except (OSError,RuntimeError,subprocess.SubprocessError):pass
            # Only random names owned by this invocation; never an operator project.
            def cleanup():
                failures=[]
                if current is not None and not cleaned:
                    try:current.cleanup_task();current.call('down','-v','--remove-orphans',timeout=420)
                    except Exception:failures.append('compose')
                for kind,name in [('container',restored_app),('container',restored_db),('container',registry),('network',network),('volume',restvol)]:
                    # Absence is expected for resources not yet created; daemon failure is not.
                    try: docker('info',timeout=30)
                    except Exception: failures.append('daemon');break
                    inspect=subprocess.run(DOCKER+[kind,'inspect',name],env=SAFE_ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=30)
                    if inspect.returncode==0:
                        try: docker(kind,'rm',*(['-fv'] if kind=='container' else []),name,timeout=120)
                        except Exception:failures.append(kind)
                for ref in reversed(tags):
                    subprocess.run(DOCKER+['image','rm',ref],env=SAFE_ENV,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=120)
                require(not failures,'Disposable infrastructure cleanup failed: '+','.join(failures))
            step('cleanup',cleanup)
        evidence['status']='passed';save();print('PASS historical upgrade, rollback, app/Redis failure and physical recovery',flush=True)


if __name__=='__main__': main()
