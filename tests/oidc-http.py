#!/usr/bin/env python3
"""Real HTTPS discovery/token/JWKS exchange and two independent Book HTTP nodes.
The fixture is an isolated OIDC server, never a production token bypass.
"""
import base64, hashlib, http.client, http.server, json, os, re, secrets, socket, ssl, subprocess, sys, tempfile, threading, time, urllib.parse, uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def b64(b): return base64.urlsafe_b64encode(b).rstrip(b'=').decode()
def php(env, code):
    return subprocess.check_output(['php','-r','require "tests/oidc-bootstrap.php";'+code],cwd=ROOT,env=env,text=True)
def check(ok, text):
    if not ok: raise AssertionError(text)
    print('PASS '+text,flush=True)

class Provider(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def send(self, data, status=200):
        raw=json.dumps(data).encode();self.send_response(status);self.send_header('Content-Type','application/json');self.send_header('Content-Length',str(len(raw)));self.end_headers();self.wfile.write(raw)
    def do_GET(self):
        server=self.server;url=urllib.parse.urlsplit(self.path)
        if url.path=='/realm/.well-known/openid-configuration':
            self.send({'issuer':server.issuer,'authorization_endpoint':server.issuer+'/authorize','token_endpoint':server.issuer+'/token','jwks_uri':server.issuer+'/jwks','end_session_endpoint':server.issuer+'/logout','revocation_endpoint':server.issuer+'/revoke','backchannel_logout_supported':True,'id_token_signing_alg_values_supported':['RS256'],'response_types_supported':['code'],'code_challenge_methods_supported':['S256'],'token_endpoint_auth_methods_supported':['client_secret_basic','client_secret_post']})
        elif url.path=='/realm/jwks': self.send({'keys':[server.jwk]})
        elif url.path=='/realm/logout':
            q=urllib.parse.parse_qs(url.query)
            assert q.get('client_id')==['book'] and q.get('post_logout_redirect_uri')==['https://book.example.org/login']
            assert 'id_token_hint' in q
            self.send_response(302);self.send_header('Location',q['post_logout_redirect_uri'][0]);self.end_headers()
        elif url.path=='/realm/authorize':
            q=urllib.parse.parse_qs(url.query);assert q['code_challenge_method']==['S256'] and q['response_type']==['code'] and q['client_id']==['book'] and q['scope']==['openid profile email']
            code=secrets.token_urlsafe(24);server.codes[code]=q;self.send_response(302);self.send_header('Location',q['redirect_uri'][0]+'?'+urllib.parse.urlencode({'code':code,'state':q['state'][0],'iss':server.issuer}));self.send_header('Content-Length','0');self.end_headers()
        else:self.send({},404)
    def do_POST(self):
        server=self.server
        if self.path=='/realm/revoke':
            form=urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode())
            if self.headers.get('Authorization')!='Basic '+base64.b64encode(b'book:test-secret').decode() or form.get('token_type_hint') not in (['access_token'],['refresh_token']):
                self.send({},401);return
            server.revoked.append((form['token_type_hint'][0],form.get('token',[''])[0]));self.send({});return
        if self.path!='/realm/token':self.send({},404);return
        q=urllib.parse.parse_qs(self.rfile.read(int(self.headers['Content-Length'])).decode());code=q.get('code',[''])[0];auth=server.codes.pop(code,None)
        if not auth or self.headers.get('Authorization')!='Basic '+base64.b64encode(b'book:test-secret').decode() or q.get('grant_type')!=['authorization_code'] or q.get('redirect_uri')!=auth['redirect_uri'] or b64(hashlib.sha256(q.get('code_verifier',[''])[0].encode()).digest())!=auth['code_challenge'][0]:
            self.send({'error':'invalid_grant'},400);return
        now=int(time.time());claims={'iss':server.issuer,'sub':server.subject,'aud':'book','nonce':auth['nonce'][0] if not server.bad_nonce else 'wrong','iat':now,'exp':now+3600,'sid':server.sid,'groups':server.groups,'email':'corporate@example.org','email_verified':True,'name':'Corporate User'}
        data=b64(json.dumps({'alg':'RS256','kid':'test-key'}).encode())+'.'+b64(json.dumps(claims).encode());sig=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(server.key)],input=data.encode());self.send({'id_token':data+'.'+b64(sig),'access_token':'test-access-'+code,'refresh_token':'test-refresh-'+code,'token_type':'Bearer'})

def request(port,path,method='GET',fields=None,cookies=None):
    c=http.client.HTTPConnection('127.0.0.1',port,timeout=15);headers={}
    if cookies:headers['Cookie']='; '.join(k+'='+v for k,v in cookies.items())
    body=None
    if fields is not None:body=urllib.parse.urlencode(fields);headers['Content-Type']='application/x-www-form-urlencoded'
    c.request(method,path,body,headers);r=c.getresponse();data=r.read();h=r.getheaders();status=r.status;c.close();return status,h,data

def header(headers,key):return next((v for k,v in headers if k.lower()==key.lower()),'')
def cookie(headers,key):
    for k,v in headers:
        if k.lower()=='set-cookie' and v.startswith(key+'='):return v.split(';',1)[0].split('=',1)[1],v
    raise AssertionError('Missing cookie '+key)
def freeport():
    with socket.socket() as s:s.bind(('127.0.0.1',0));return s.getsockname()[1]

def main():
    with tempfile.TemporaryDirectory(prefix='book-oidc-http-') as d:
        tmp=Path(d);key=tmp/'key.pem';cert=tmp/'cert.pem';subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        mod=subprocess.check_output(['openssl','rsa','-in',str(key),'-modulus','-noout'],text=True).strip().split('=')[1]
        idp=http.server.ThreadingHTTPServer(('127.0.0.1',0),Provider);idp.issuer='https://127.0.0.1:'+str(idp.server_port)+'/realm';idp.key=key;idp.jwk={'kty':'RSA','kid':'test-key','alg':'RS256','use':'sig','n':b64(bytes.fromhex(mod)),'e':'AQAB'};idp.codes={};idp.revoked=[];idp.groups=['writers'];idp.subject='employee-1';idp.sid='idp-session-1';idp.bad_nonce=False
        tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key);idp.socket=tls.wrap_socket(idp.socket,server_side=True);threading.Thread(target=idp.serve_forever,daemon=True).start()
        env=os.environ.copy();dsn=env.pop('BOOK_PG_DSN',None);env.pop('BOOK_REDIS_HOST',None);env.update(BOOK_DATA_DIR=str(tmp/'data'),BOOK_SECURE_COOKIE='1',BOOK_OIDC_REQUIRED='0',BOOK_PG_NAMESPACE=uuid.uuid4().hex)
        for k in ['BOOK_OIDC_CONFIG','BOOK_OIDC_CLIENT_SECRET','BOOK_OIDC_CLIENT_SECRET_FILE']:env.pop(k,None)
        setup=json.loads(php(env,r'''$s=new CajeerBook\IdentityStore(getenv('BOOK_DATA_DIR'));$s->initialize('owner','Owner','owner@example.org','owner-password-123');$t=$s->login('owner','owner-password-123','192.0.2.1')['token'];$o=$s->createOrganization($t,'Team','team');echo json_encode(['org'=>$o['id'],'token'=>$t]);'''))
        if '--postgres' in sys.argv:
            if not dsn:raise RuntimeError('PostgreSQL required')
            env['BOOK_PG_DSN']=dsn;php(env,r'''CajeerBook\PostgresContentStore::migrate(getenv('BOOK_DATA_DIR'));''')
        secret=tmp/'oidc-secret';secret.write_text('test-secret\n');secret.chmod(0o600)
        env.update(BOOK_OIDC_CONFIG=json.dumps({'backchannel_logout':True,'issuer':idp.issuer,'client_id':'book','redirect_uri':'https://book.example.org/auth/oidc/callback','organization':setup['org'],'label':'Corporate ID','post_logout_redirect_uri':'https://book.example.org/login','groups':{'writers':'editor','readers':'viewer'}}),BOOK_OIDC_CLIENT_SECRET_FILE=str(secret))
        processes=[];logs=[]
        try:
            ports=[freeport(),freeport()]
            for i,port in enumerate(ports):
                log=(tmp/f'node{i}.log').open('w+');logs.append(log);p=subprocess.Popen(['php','-d',f'openssl.cafile={cert}','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log);processes.append(p)
                for _ in range(100):
                    try:
                        if request(port,'/login')[0]==200:break
                    except OSError:pass
                    time.sleep(.05)
                else:raise RuntimeError('Node unavailable')
            a,b=ports
            def begin():
                status,h,body=request(a,'/login');assert status==200 and b'Sign in with Corporate ID' in body
                pre,attrs=cookie(h,'__Host-book_preauth');csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',body).group(1).decode()
                assert request(a,'/auth/oidc/start','POST',{'csrf':'wrong'},{'__Host-book_preauth':pre})[0]==403
                status,h,_=request(a,'/auth/oidc/start','POST',{'csrf':csrf},{'__Host-book_preauth':pre});assert status==303,(status,h)
                browser,attrs=cookie(h,'__Host-book_oidc');assert 'secure' in attrs.lower() and 'httponly' in attrs.lower() and 'samesite=lax' in attrs.lower()
                target=urllib.parse.urlsplit(header(h,'Location'));connection=http.client.HTTPSConnection('127.0.0.1',idp.server_port,context=ssl.create_default_context(cafile=str(cert)));connection.request('GET',target.path+'?'+target.query);r=connection.getresponse();assert r.status==302;callback=urllib.parse.urlsplit(r.getheader('Location'));r.read();connection.close();return callback.path+'?'+callback.query,browser
            path,browser=begin();check(request(b,path,cookies={'__Host-book_oidc':'0'*64})[0]==401,'wrong browser cannot consume state')
            status,h,_=request(b,path,cookies={'__Host-book_oidc':browser});assert status==303,(status,h);session,attrs=cookie(h,'__Host-book_session');check('samesite=strict' in attrs.lower() and 'secure' in attrs.lower() and header(h,'Location')=='/auth/oidc/complete','HTTPS exchange, PKCE and session issue on second node')
            check(request(a,'/account',cookies={'__Host-book_session':session})[0]==200,'session accepted by first node');check(request(b,path,cookies={'__Host-book_oidc':browser})[0]==401,'callback replay rejected')
            idp.bad_nonce=True;path,browser=begin();check(request(b,path,cookies={'__Host-book_oidc':browser})[0]==401,'signed token with wrong nonce rejected');idp.bad_nonce=False
            idp.groups=['readers'];path,browser=begin();status,h,_=request(b,path,cookies={'__Host-book_oidc':browser});assert status==303;new,_=cookie(h,'__Host-book_session');check(request(a,'/account',cookies={'__Host-book_session':session})[0]==401,'group reauthentication revokes prior session')
            check(request(a,'/account',cookies={'__Host-book_session':new})[0]==200,'new downgraded session valid')
            # Full browser logout: CSRF, provider token revocation, RP end-session URL and local revocation.
            status,_,account=request(a,'/account',cookies={'__Host-book_session':new})
            csrf=re.search(rb'name="csrf" value="([a-f0-9]{64})"',account).group(1).decode()
            check(request(a,'/logout','POST',{'csrf':'incorrect'},{'__Host-book_session':new})[0]==403,'logout requires session CSRF')
            status,h,_=request(a,'/logout','POST',{'csrf':csrf},{'__Host-book_session':new})
            rp=urllib.parse.urlsplit(header(h,'Location'))
            params=urllib.parse.parse_qs(rp.query)
            check(status==303 and rp.path=='/realm/logout' and params.get('client_id')==['book'] and params.get('post_logout_redirect_uri')==['https://book.example.org/login'] and 'id_token_hint' in params,'RP logout redirects to trusted provider with signed ID token hint')
            check(request(b,'/account',cookies={'__Host-book_session':new})[0]==401,'browser sign-out revokes local session on other node')
            check(len(idp.revoked)==2 and {a for a,b in idp.revoked}=={'access_token','refresh_token'},'logout revokes provider access and refresh tokens')
            idp.sid='idp-session-2';path,browser=begin();status,h,_=request(b,path,cookies={'__Host-book_oidc':browser});assert status==303;new,_=cookie(h,'__Host-book_session')
            # Verify a real signed back-channel token through HTTPS discovery/JWKS on the other node.
            def logout_token(**overrides):
                claims={'iss':idp.issuer,'sub':idp.subject,'aud':'book','iat':int(time.time()),'jti':uuid.uuid4().hex,'events':{'http://schemas.openid.net/event/backchannel-logout':{}}};claims.update(overrides)
                if claims.get('sub') is None:claims.pop('sub',None)
                data=b64(json.dumps({'alg':'RS256','kid':'test-key'}).encode())+'.'+b64(json.dumps(claims).encode());sig=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(key)],input=data.encode());return data+'.'+b64(sig)
            logout='/auth/oidc/backchannel-logout'
            check(request(b,logout,'POST',{'logout_token':logout_token(aud='wrong')})[0]==400 and request(a,'/account',cookies={'__Host-book_session':new})[0]==200,'wrong logout audience denied without session change')
            check(request(b,logout,'POST',{'logout_token':logout_token(nonce='wrong')})[0]==400,'logout token with nonce rejected')
            check(request(b,logout,'POST',{'logout_token':logout_token(events={})})[0]==400,'logout event required')
            check(request(b,logout,'POST',{'logout_token':logout_token(sid='other-sid',sub=None)})[0]==200 and request(a,'/account',cookies={'__Host-book_session':new})[0]==200,'unrelated sid-only logout does not revoke valid session')
            sid_token=logout_token(sid=idp.sid,sub=None)
            check(request(b,logout,'POST',{'logout_token':sid_token})[0]==200 and request(a,'/account',cookies={'__Host-book_session':new})[0]==401,'signed sid-only backchannel logout revokes matching provider session')
            time.sleep(1.1);idp.sid='idp-session-3';path,browser=begin();status,h,_=request(b,path,cookies={'__Host-book_oidc':browser});assert status==303;new,_=cookie(h,'__Host-book_session')
            token=logout_token();check(request(b,logout,'POST',{'logout_token':token})[0]==200 and request(a,'/account',cookies={'__Host-book_session':new})[0]==401,'signed backchannel logout revokes session on other node')
            time.sleep(1.1);path,browser=begin();status,h,_=request(b,path,cookies={'__Host-book_oidc':browser});assert status==303;new,_=cookie(h,'__Host-book_session')
            check(request(b,logout,'POST',{'logout_token':token})[0]==200 and request(a,'/account',cookies={'__Host-book_session':new})[0]==200,'replayed logout cannot revoke newer login')
            idp.groups=[];path,browser=begin();check(request(b,path,cookies={'__Host-book_oidc':browser})[0]==401 and request(a,'/account',cookies={'__Host-book_session':new})[0]==401,'group removal denies and revokes across nodes')
            data=json.loads(php(env,r'''echo json_encode((new CajeerBook\IdentityStore(getenv('BOOK_DATA_DIR')))->backup());'''));users=[u for u in data['users'].values() if 'oidc' in u];check(len(users)==1 and users[0]['oidc']['subject']=='employee-1' and 'oidc_flows' not in data,'stable JIT identity, backup excludes temporary flow')
        except Exception:
            for log in logs:log.seek(0);print(log.read(),file=sys.stderr)
            raise
        finally:
            for p in processes:p.terminate();p.wait(timeout=5)
            for log in logs:log.close()
            idp.shutdown();idp.server_close()
            if '--postgres' in sys.argv:php(env,r'''$p=CajeerBook\PostgresContentStore::connect();$q=$p->prepare('DELETE FROM cajeer_book.content_installation WHERE installation=?');$q->execute([getenv('BOOK_PG_NAMESPACE')]);''')
if __name__=='__main__':main()
