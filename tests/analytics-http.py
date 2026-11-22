#!/usr/bin/env python3
"""End-to-end anonymous consent-gated collection and role-protected workspace settings."""
import importlib.util, json, os, re, socket, subprocess, tempfile, time, urllib.error, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('idhttp',ROOT/'tests/identity-http.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
Client=mod.Client

def check(ok,label):
    assert ok,label
    print('PASS '+label,flush=True)

def raw(base,path,body,origin=None):
    headers={'Content-Type':'application/json'}
    if origin is not None:headers['Origin']=origin
    req=urllib.request.Request(base+path,data=json.dumps(body).encode(),headers=headers)
    try:r=urllib.request.urlopen(req,timeout=10)
    except urllib.error.HTTPError as e:r=e
    with r:return r.status,r.read()

with tempfile.TemporaryDirectory(prefix='book-analytics-http-') as tmp:
    data=Path(tmp)/'data'
    password='http-analytics-password-123'
    h=subprocess.check_output(['php','-r','echo password_hash($argv[1],PASSWORD_DEFAULT);',password],text=True)
    env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=h)
    with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
    base=f'http://127.0.0.1:{port}'
    with (Path(tmp)/'server.log').open('w+') as log:
        server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
        try:
            for _ in range(80):
                try:Client(base).request('/health/live',200);break
                except OSError:time.sleep(.1)
            else:raise RuntimeError('PHP server failed to start')
            admin,guest=Client(base),Client(base)
            admin.login('admin',password)
            csrf=admin.csrf('/organizations')
            headers,_=admin.request('/organizations',303,dict(csrf=csrf,action='create',name='Analytics Org',slug='analytics-org'))
            oid=headers['Location'].rsplit('/',1)[-1]
            admin.request('/workspaces',303,dict(csrf=admin.csrf('/workspaces'),action='create',organization=oid,name='Public Docs',slug='public-docs'))
            ws=list(json.loads((data/'identity/identity.json').read_text())['workspaces'].values())[0]
            wid=ws['id'];prefix=f'/w/{oid}/{wid}'
            guest.request(prefix+'/',401)
            admin.request('/workspaces',303,dict(csrf=admin.csrf('/workspaces'),action='visibility',id=wid,revision='1',visibility='public',slug=''))
            check(b'Analytics' in admin.request('/workspaces',200)[1], 'workspace UI links to real analytics management')
            guest.request(prefix+'/',200)
            check(b'"workspace"' not in guest.request(prefix+'/',200)[1], 'disabled analytics does not expose client tracking')
            guest.request('/analytics?id='+wid,401)
            admin.request('/analytics?id='+wid,303,dict(csrf=admin.csrf('/analytics?id='+wid),revision='2',enabled='1',ga4='G-ABCDEF1234',metrica='12345678',webvisor='1'))
            _,html=guest.request(prefix+'/',200)
            check(b'G-ABCDEF1234' in html and b'12345678' in html and b'analytics.js' in html, 'published reader receives selected workspace tracker configuration')
            payload={'event':'page_view','path':'/','consent':True}
            check(raw(base,prefix+'/analytics/collect',payload,base)[0]==204,'anonymous first-party page view is counted')
            check(raw(base,prefix+'/analytics/collect',{'event':'search','path':'/search','consent':True},base)[0]==204,'real search event counted')
            check(raw(base,prefix+'/analytics/collect',payload)[0]==403,'missing origin denied')
            check(raw(base,prefix+'/analytics/collect',payload,'https://external.example')[0]==403,'cross-site origin denied')
            check(raw(base,prefix+'/analytics/collect',{**payload,'consent':False},base)[0]==400,'no-consent payload denied')
            check(raw(base,prefix+'/analytics/collect',{**payload,'visitor':'123'},base)[0]==400,'visitor identifier rejected')
            check(raw(base,prefix+'/analytics/collect',{**payload,'path':'/docs/guide/../private'},base)[0]==400,'traversal path rejected')
            _,stats=admin.request('/analytics?id='+wid,200)
            check(b'Page views</span>' in stats and b'<strong>1</strong>' in stats and b'search' in stats and 'token=' not in (data/'workspaces'/oid/wid/'analytics'/(time.strftime('%Y-%m-%d',time.gmtime())+'.json')).read_text(), 'management dashboard displays aggregated events without raw URL query data')
            admin.request('/analytics?id='+wid,303,dict(csrf=admin.csrf('/analytics?id='+wid),revision='3',ga4='',metrica=''))
            check(raw(base,prefix+'/analytics/collect',payload,base)[0]==404,'disabled workspace does not collect subsequent events')
            check(b'G-ABCDEF1234' not in guest.request(prefix+'/',200)[1],'disabled workspace no longer exposes third-party IDs')
        except Exception:
            log.seek(0);print(log.read());raise
        finally:server.terminate();server.wait(timeout=5)
