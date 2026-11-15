#!/usr/bin/env python3
"""HTTP acceptance with real sessions and generated drafts; --policy-only skips renderer/YAML."""
import hashlib, html, importlib.util, json, os, re, socket, subprocess, sys, tempfile, time, urllib.parse, urllib.request
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
FULL='--policy-only' not in sys.argv

def exercise(base,data):
    owner,admin,editor,viewer,outside,guest=[Client(base) for _ in range(6)];owner.login()
    for name,c in [('manager',admin),('writer',editor),('reader',viewer),('outside',outside)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='API Team',slug='api-team')[0]['Location'];oid=org.split('/')[-1]
    def identity():return json.loads((data/'identity/identity.json').read_text())
    def rev():return str(identity()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('manager',admin),('writer',editor),('reader',viewer)]:
        _,h=post(owner,org,'invite',200,revision=rev(),username=name);post(c,'/organizations/join','accept',token=re.search(rb'/organizations/join#([a-f0-9]{64})',h).group(1).decode())
    for name,role in [('manager','admin'),('writer','editor')]:post(owner,org,'role',revision=rev(),user=uid(name),role=role)
    for name in ['Reference','Other']:post(owner,'/workspaces','create',organization=oid,name=name,slug=name.lower())
    spaces={ws['name']:ws for ws in identity()['workspaces'].values()};wid=spaces['Reference']['id'];prefix='/w/'+oid+'/'+wid;other='/w/'+oid+'/'+spaces['Other']['id'];route=prefix+'/studio/openapi';reviews=prefix+'/studio/reviews';scope='api/1.0/reference/pets';key=scope+'/operations/get-'+hashlib.sha256(b'/pets').hexdigest()[:24]
    def db():return json.loads((data/'workspaces'/oid/wid/'books.json').read_text())
    def source(summary='List pets'):
        return {'openapi':'3.1.0','info':{'title':'Pets API','version':'1.0'},'paths':{'/pets':{'get':{'summary':summary,'responses':{'200':{'description':'OK'}}}}}}
    target={'book':'api','version':'1.0','slug':'pets','root':'openapi.json'}
    def fields(h):
        h=h.decode();return {n:html.unescape(re.search(r'name="'+n+r'" value="([^"]*)"',h).group(1)) for n in ['token','bundle','commit']}
    def preview(s,c=editor):return post(c,route,'preview',200,**target,source=json.dumps(s))[1]
    def apply(h,c=editor,status=303):return post(c,route,'apply',status,**target,**fields(h))
    editor.request(route,200);viewer.request(route,403);outside.request(route,404);guest.request(route,401);post(viewer,route,'preview',403,**target,source=json.dumps(source()));post(editor,route,'publish',403);editor.request(route,403,{'action':'preview','csrf':'invalid'});check('OpenAPI Studio RBAC and CSRF enforced')
    bad=source();bad['paths']['/pets']['get']['responses']['200']={'$ref':'https://invalid.example/a.json'};post(editor,route,'preview',422,**target,source=json.dumps(bad));assert not db().get('openapi');check('external references rejected without writes')
    post(owner,reviews,'policy',required='1',revision='1');h=preview(source());assert b'Apply reference drafts' in h;apply(h);assert db()['schema']==4 and db()['pages'][key]['published'] is None;apply(h,status=409);assert not json.loads(viewer.request(prefix+'/api/v1/pages',200)[1]);check('real JSON import, private draft and stale signed preview')
    download=route+'?'+urllib.parse.urlencode({'spec':scope,'download':'1'});assert json.loads(editor.request(download,200)[1])['files']['openapi.json']['info']['title']=='Pets API';viewer.request(download,403);outside.request(download,404);editor.request(other+'/studio/openapi?'+urllib.parse.urlencode({'spec':scope,'download':'1'}),404);check('source download isolated across roles and workspaces')
    rid=post(editor,reviews,'submit',kind='page',target=key,expected='1',reviewer=uid('manager'))[0]['Location'].split('=')[-1];post(admin,reviews,'approve',id=rid,revision='1');post(owner,reviews,'publish',id=rid,revision='2');published=db()['pages'][key]['published'];assert 'List pets' in published['body'];assert json.loads(viewer.request(prefix+'/api/v1/pages/'+key,200)[1])['title'].startswith('GET');outside.request(prefix+'/api/v1/pages/'+key,404);guest.request(prefix+'/api/v1/pages/'+key,401)
    if FULL:assert b'List pets' in viewer.request(prefix+'/docs/'+key,200)[1]
    check('independent approval publishes generated reference through real workflow')
    h=preview(source('List all pets'));assert b'Compare' in h;apply(h);assert db()['pages'][key]['published']==published and 'List all pets' in db()['pages'][key]['draft']['body'];h=post(editor,route,'restore-preview',200,spec=scope,source_revision='1')[1];apply(h);assert db()['openapi'][scope]['revision']==3 and db()['pages'][key]['published']==published;check('update and source-history restore preserve published revision')
    # Actual multipart upload of a root document and separate referenced schema.
    boundary='OpenApiTestBoundary123';parts=[];src=source();src['paths']['/pets']['get']['responses']['200']={'$ref':'response.json#/ok'}
    for n,v in {'csrf':editor.csrf('/account'),'action':'preview',**target,'slug':'uploaded'}.items():parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{n}"\r\n\r\n{v}\r\n'.encode())
    for n,v in [('openapi.json',src),('response.json',{'ok':{'description':'Multipart result'}})]:parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="files[]"; filename="{n}"\r\nContent-Type: application/json\r\n\r\n'.encode()+json.dumps(v).encode()+b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode());req=urllib.request.Request(base+route,data=b''.join(parts),headers={'Content-Type':f'multipart/form-data; boundary={boundary}'})
    with editor.opener.open(req,timeout=10) as r:assert r.status==200;h=r.read()
    post(editor,route,'apply',303,**(target|{'slug':'uploaded'}),**fields(h));assert 'api/1.0/reference/uploaded' in db()['openapi'];check('real multi-file multipart import resolves external bundle file')
    if FULL:
        yaml="openapi: 3.0.3\ninfo: {title: YAML API, version: '1.0'}\npaths:\n  /ping:\n    get:\n      responses:\n        '200': {description: OK}\n";h=post(editor,route,'preview',200,**(target|{'slug':'yaml','root':'openapi.yaml'}),source=yaml)[1];post(editor,route,'apply',303,**(target|{'slug':'yaml'}),**fields(h));check('YAML import over HTTP')
    post(owner,org,'role',revision=rev(),user=uid('writer'),role='viewer');editor.request(download,403);post(editor,route,'preview',403,**target,source=json.dumps(source()));check('role revocation closes source and import routes')

def main():
    with tempfile.TemporaryDirectory(prefix='book-openapi-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data)
            except Exception:log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)
if __name__=='__main__':main()
