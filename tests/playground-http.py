#!/usr/bin/env python3
"""Published API Playground visibility, CSRF, stale binding and actual browser UI."""
import hashlib, html, io, zipfile, importlib.util, json, os, re, socket, ssl, subprocess, sys, tempfile, threading, time, urllib.parse
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('workspace_http',ROOT/'tests/workspaces-http.py');w=importlib.util.module_from_spec(spec);spec.loader.exec_module(w)
Client,post,check,PASSWORD=w.Client,w.post,w.check,w.PASSWORD
spec=importlib.util.spec_from_file_location('playground_tls',ROOT/'tests/playground-https.py');tls=importlib.util.module_from_spec(spec);spec.loader.exec_module(tls)
FULL='--policy-only' not in sys.argv
BROWSER='--browser' in sys.argv

def exercise(base,data,origin):
    owner,admin,editor,viewer,outsider,guest=[Client(base) for _ in range(6)];owner.login()
    for name,c in [('manager',admin),('writer',editor),('reader',viewer),('outside',outsider)]:
        post(owner,'/account/users','create',username=name,name=name,email=name+'@example.org',password='password-'+name+'-123');c.login(name,'password-'+name+'-123')
    org=post(owner,'/organizations','create',name='Playground team',slug='playground-team')[0]['Location'];oid=org.split('/')[-1]
    def identity():return json.loads((data/'identity/identity.json').read_text())
    def rev():return str(identity()['organizations'][oid]['revision'])
    def uid(name):return next(u['id'] for u in identity()['users'].values() if u['username']==name)
    for name,c in [('manager',admin),('writer',editor),('reader',viewer)]:
        _,h=post(owner,org,'invite',200,revision=rev(),username=name);post(c,'/organizations/join','accept',token=re.search(rb'/organizations/join#([a-f0-9]{64})',h).group(1).decode())
    for name,role in [('manager','admin'),('writer','editor')]:post(owner,org,'role',revision=rev(),user=uid(name),role=role)
    for name in ['Reference','Other']:post(owner,'/workspaces','create',organization=oid,name=name,slug=name.lower())
    spaces={s['name']:s for s in identity()['workspaces'].values()};wid=spaces['Reference']['id'];prefix='/w/'+oid+'/'+wid;other='/w/'+oid+'/'+spaces['Other']['id'];route=prefix+'/studio/openapi';reviews=prefix+'/studio/reviews';key='api/1/reference/pets/operations/post-'+hashlib.sha256(b'/pets/{id}').hexdigest()[:24];pg=prefix+'/playground?'+urllib.parse.urlencode({'key':key});proxy=prefix+'/api/v1/playground'
    dbpath=data/'workspaces'/oid/wid/'books.json'
    def db():return json.loads(dbpath.read_text())
    def source(server=origin+'/v1',summary='Create pet'):
        return {'openapi':'3.1.0','info':{'title':'Pets API','version':'1'},'servers':[{'url':server}],'security':[{'token':[],'apiKey':[]}],'components':{'securitySchemes':{'token':{'type':'http','scheme':'bearer'},'apiKey':{'type':'apiKey','in':'query','name':'key'}}},'paths':{'/pets/{id}':{'post':{'summary':summary,'parameters':[{'name':'id','in':'path','required':True,'schema':{'type':'string'}},{'name':'limit','in':'query','required':True,'schema':{'type':'integer'}},{'name':'X-Trace','in':'header','schema':{'type':'string'}}],'requestBody':{'required':True,'content':{'application/json':{'schema':{'type':'object'}}}},'responses':{'201':{'description':'Created'}}}}}}
    target={'book':'api','version':'1','slug':'pets','root':'openapi.json'}
    def apply(src):
        h=post(editor,route,'preview',200,**target,source=json.dumps(src))[1].decode();fields={n:html.unescape(re.search(r'name="'+n+r'" value="([^"]*)"',h).group(1)) for n in ['token','bundle','commit']};post(editor,route,'apply',303,**target,**fields)
    def model(c):
        headers,body=c.request(pg,200);m=json.loads(html.unescape(re.search(rb'data-api-playground="([^"]*)"',body).group(1).decode()));return headers,m,body
    def publish():
        rid=post(editor,reviews,'submit',kind='page',target=key,expected=str(db()['pages'][key]['revision']),reviewer=uid('manager'))[0]['Location'].split('=')[-1];post(admin,reviews,'approve',id=rid,revision='1');post(owner,reviews,'publish',id=rid,revision='2')
    sdk=prefix+'/sdk?'+urllib.parse.urlencode({'key':key})
    def sdk_download(c,lang='typescript',digest=None,status=200):
        if digest is None:
            _,page=c.request(sdk,200);digest=re.search(rb'Publication digest: <code>([a-f0-9]{64})</code>',page).group(1).decode()
        return c.request(sdk+'&lang='+lang+'&download=1&digest='+digest,status)
    post(owner,reviews,'policy',required='1',revision='1');apply(source());viewer.request(sdk,404);guest.request(sdk,401);outsider.request(sdk,404);viewer.request(pg,404);editor.request(pg,404);guest.request(pg,401);outsider.request(pg,404);check('draft-only operations are inaccessible even to editors')
    publish();headers,m,body=model(viewer);assert m['definition']['servers']==[origin+'/v1'] and m['browser']==[0] and m['proxy']==[0] and m['endpoint']==proxy and m['csrf'];assert 'connect-src' in headers['Content-Security-Policy'] and origin in headers['Content-Security-Policy'] and headers['Referrer-Policy']=='no-referrer';assert headers['Cache-Control']=='private, no-store';assert b'api-playground.js' in body
    guest.request(pg,401);outsider.request(pg,404);viewer.request(other+'/playground?'+urllib.parse.urlencode({'key':key}),404);check('published model, scoped URL, CSP and private workspace isolation')
    if FULL:assert b'Try API in Playground' in viewer.request(prefix+'/docs/'+key,200)[1]
    # SDK is generated from the exact visible publication, never the latest source.
    _,sdk_page=viewer.request(sdk,200);sdk_digest=re.search(rb'Publication digest: <code>([a-f0-9]{64})</code>',sdk_page).group(1).decode()
    for lang in ['typescript','python','php']:
        h,z=sdk_download(viewer,lang,sdk_digest);assert h['Content-Type']=='application/zip' and 'attachment' in h['Content-Disposition'] and h['Cache-Control']=='private, no-store'
        with zipfile.ZipFile(io.BytesIO(z)) as archive:
            assert archive.testzip() is None and json.loads(archive.read('publication.json'))['digest']==sdk_digest
            assert origin+'/v1' in archive.read('operations.json').decode() and 'secret-bearer' not in archive.read('operations.json').decode()
    guest.request(sdk,401);outsider.request(sdk,404);viewer.request(other+'/sdk?'+urllib.parse.urlencode({'key':key}),404);sdk_download(viewer,'typescript','0'*64,409)
    viewer.request(sdk+'&lang=invalid',400);check('SDK ZIP downloads, per-language examples, exact digest and workspace isolation')
    # Proxy must not accept arbitrary URL/method, nor transmit any request to loopback.
    payload={'server':0,'security':0,'values':['a b','2','trace'],'auth':['secret-bearer','secret-query'],'body':'{"name":"Ada"}','content_type':'application/json','confirm':True}
    form={'key':key,'binding':m['binding'],'csrf':m['csrf'],'payload':json.dumps(payload)}
    guest.request(proxy,401,form);outsider.request(proxy,404,form);viewer.request(proxy,403,form|{'csrf':'bad'});viewer.request(proxy,409,form|{'binding':'a'*64});viewer.request(proxy,422,form|{'payload':json.dumps(payload|{'confirm':False})});viewer.request(proxy,422,form|{'payload':json.dumps(payload|{'server':99})});viewer.request(proxy,422,form|{'payload':json.dumps(payload|{'url':'https://evil.example','method':'DELETE'})});_,res=viewer.request(proxy,422,form);assert b'globally routable IPv4' in res and b'secret' not in res;assert not any(r[2]=='Bearer secret-bearer' for r in tls.REQUESTS);check('proxy authorization, CSRF, binding, confirmation, server selection and DNS rejection')
    # Browser request exercises the exact same published model against real HTTPS/CORS fixture.
    if BROWSER:browser(base,pg,viewer,origin,body)
    apply(source('https://other.example.org/v2','New draft destination'));_,m2,_=model(viewer);assert m2==m;check('unpublished specification update does not change executable model');sdk_download(viewer,'python',sdk_digest);check('unpublished source leaves SDK digest and download unchanged')
    # Old published model is still valid after reimport, until actual approved publication.
    publish();headers,body=viewer.request(pg,200);assert b'No API server is enabled' in body;viewer.request(proxy,409,form);check('new approved publication invalidates previous proxy binding');sdk_download(viewer,'typescript',sdk_digest,409);check('new approved publication invalidates stale SDK download link')
    # Revoke role at workspace; no public disclosure or proxy use.
    post(owner,'/workspaces','role',id=wid,revision=str(identity()['workspaces'][wid]['revision']),user=uid('reader'),role='none');viewer.request(pg,404);viewer.request(proxy,404,form);check('workspace access revocation closes Playground and proxy');viewer.request(sdk,404);sdk_download(viewer,'php',sdk_digest,404)
    raw=dbpath.read_text();assert 'secret-bearer' not in raw and 'secret-query' not in raw and '"name":"Ada"' not in raw;rate=(data/'playground-rate.json').read_text();assert 'secret' not in rate and 'http' not in rate;check('request secrets and body not persisted in BookStore or rate limiter')
    # A public workspace exposes only published browser model, never anonymous proxy.
    # Enable workspace public via the current revision and re-publish the enabled server.
    apply(source());publish();post(owner,'/workspaces','visibility',id=wid,revision=str(identity()['workspaces'][wid]['revision']),visibility='public');_,g,_=model(guest);assert g['browser']==[0] and g['proxy']==[] and not g['csrf'];guest.request(proxy,401,form);viewer.request(pg,404);check('public guest browser-only; explicit workspace deny still enforced');sdk_download(guest);viewer.request(sdk,404)
    post(owner,prefix+'/studio/books','visibility',slug='api',visibility='private',revision='1');guest.request(pg,404);outsider.request(pg,404);check('private book in public workspace blocks operation model');guest.request(sdk,404);outsider.request(sdk,404);check('SDK public download respects explicit deny and book visibility')

def browser(base,pg,viewer,origin,body):
    from playwright.sync_api import sync_playwright, expect
    with sync_playwright() as p:
        args={'headless':True};exe=os.environ.get('BOOK_CHROMIUM');
        if exe:args['executable_path']=exe
        b=p.chromium.launch(**args);ctx=b.new_context(ignore_https_errors=True);page=ctx.new_page()
        try:
            for c in viewer.jar:ctx.add_cookies([{'name':c.name,'value':c.value,'url':base}])
            if os.environ.get('BOOK_TEST_BROWSER_TRANSPORT')=='1':
                # Local environment may prohibit page navigation; load exact real HTML and production JS.
                page.set_content(body.decode());page.add_script_tag(content=(ROOT/'public/assets/api-playground.js').read_text())
            else:page.goto(base+pg,wait_until='domcontentloaded')
            page.locator('[data-pg-send]').wait_for();page.locator('[data-pg-mode]').select_option('browser');page.get_by_label('id (path, string, required)').fill('a b');page.get_by_label('limit (query, integer, required)').fill('2');page.get_by_label('X-Trace (header, string)').fill('trace');page.get_by_label('token (header)').fill('browser-ui-secret');page.get_by_label('apiKey (query)').fill('query-ui-secret');page.locator('[data-pg-payload]').fill('{"name":"Ada"}');page.locator('[data-pg-confirm]').check();page.locator('[data-pg-curl]').click();assert 'browser-ui-secret' not in page.locator('[data-pg-command]').inner_text() and 'query-ui-secret' not in page.locator('[data-pg-command]').inner_text();page.locator('[data-pg-send]').click();expect(page.locator('[data-pg-result]')).to_contain_text('HTTP 201',timeout=15000);assert json.loads(page.locator('[data-pg-result-body]').inner_text())['body']['name']=='Ada';page.locator('[data-pg-clear]').click();assert not page.locator('[data-pg-auth] input').first.input_value() and not page.locator('[data-pg-payload]').input_value();check('Chromium actual form, HTTPS API response, redacted cURL and credential clearing')
        finally:ctx.close();b.close()

def main():
    with tempfile.TemporaryDirectory(prefix='book-playground-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';cert=tmp/'cert.pem';key=tmp/'key.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        server=tls.http.server.ThreadingHTTPServer(('127.0.0.1',0),tls.API);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True);threading.Thread(target=server.serve_forever,daemon=True).start();origin=f'https://localhost:{server.server_port}'
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_PLAYGROUND_ORIGINS=origin,BOOK_PLAYGROUND_PROXY_ORIGINS=origin,BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True))
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            php=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data,origin)
            except Exception:log.seek(0);print(log.read());raise
            finally:php.terminate();php.wait(timeout=5);server.shutdown();server.server_close()
if __name__=='__main__':main()
