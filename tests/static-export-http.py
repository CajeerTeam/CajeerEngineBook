#!/usr/bin/env python3
"""Real Studio POST, CSRF, roles, scope and private export policy."""
import importlib.util, io, json, os, re, socket, subprocess, tempfile, time, zipfile
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py'); w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client, post, PASSWORD=w.Client,w.post,w.PASSWORD

def exercise(base,data):
    owner,guest,editor,viewer,outsider=[Client(base) for _ in range(5)];owner.login()
    route='/studio/static-export';guest.request(route,401);owner.request(route,200)
    owner.request(route,403,{'csrf':'bad','action':'download','visibility':'public','scope':''})
    headers,raw=post(owner,route,'download',200,visibility='public',scope='handbook/1.0')
    assert headers['Content-Type']=='application/zip' and 'no-store' in headers['Cache-Control']
    with zipfile.ZipFile(io.BytesIO(raw)) as z:assert 'index.html' in z.namelist() and 'search.html' in z.namelist() and 'books.json' not in z.namelist()
    post(owner,route,'download',422,visibility='all',scope='')
    for name,c in [('writer',editor),('reader',viewer),('outsider',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Static team',slug='static-team')[0]['Location'].split('/')[-1]
    def state():return json.loads((data/'identity/identity.json').read_text())
    def rev():return str(state()['organizations'][org]['revision'])
    def uid(name):return next(u['id'] for u in state()['users'].values() if u['username']==name)
    for name,c in [('writer',editor),('reader',viewer)]:
        _,html=post(owner,'/organizations/'+org,'invite',200,revision=rev(),username=name);token=re.search(rb'/organizations/join#([a-f0-9]{64})',html).group(1).decode();post(c,'/organizations/join','accept',token=token)
    post(owner,'/organizations/'+org,'role',revision=rev(),user=uid('writer'),role='editor')
    post(owner,'/workspaces','create',organization=org,name='Static workspace',slug='static')
    ws=next(x for x in state()['workspaces'].values() if not x['legacy']);prefix='/w/'+org+'/'+ws['id'];route=prefix+'/studio/static-export'
    post(owner,prefix+'/studio','publish',book='secret',version='1',path='start',title='Secret',section='Guide',order='10',body='PRIVATE_EXPORT_MARKER',revision='0')
    for c,code in [(editor,403),(viewer,403),(outsider,404),(guest,401)]:c.request(route,code);c.request(route,code,{'action':'download','visibility':'all','confirm_private':'1','csrf':c.csrf('/account') if c is not guest else 'bad'})
    post(owner,route,'download',422,visibility='public',scope='')
    _,raw=post(owner,route,'download',200,visibility='all',scope='secret/1',confirm_private='1')
    with zipfile.ZipFile(io.BytesIO(raw)) as z:assert b'PRIVATE_EXPORT_MARKER' in z.read('assets/search-index.js') and b'noindex,nofollow' in z.read('index.html') and not any('handbook' in n for n in z.namelist())
    print('PASS Static Export HTTP authorization, CSRF, scoped private confirmation and actual ZIP',flush=True)

if __name__=='__main__':
    with tempfile.TemporaryDirectory(prefix='book-static-http-') as temp:
        data=Path(temp)/'data';env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}';server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        try:
            for _ in range(100):
                try:Client(base).request('/health/live',200);break
                except Exception:time.sleep(.1)
            exercise(base,data)
        finally:server.terminate();server.wait(timeout=5)
