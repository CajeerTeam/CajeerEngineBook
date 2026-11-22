#!/usr/bin/env python3
"""Integration test: real Book routes + two independent TLS OIDC providers."""
import importlib.util, json, os, ssl, subprocess, tempfile, threading, time, uuid, re, urllib.parse, http.client
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('legacy_oidc_http',str(ROOT/'tests/oidc-http.py'))
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)

def main():
    with tempfile.TemporaryDirectory(prefix='book-oidc-links-http-') as directory:
        tmp=Path(directory);key=tmp/'key.pem';cert=tmp/'cert.pem'
        subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=127.0.0.1','-addext','subjectAltName=IP:127.0.0.1'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,check=True)
        mod=subprocess.check_output(['openssl','rsa','-in',str(key),'-modulus','-noout'],text=True).strip().split('=')[1]
        tls=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);tls.load_cert_chain(cert,key)
        servers=[]
        for name in ['one','two']:
            srv=m.http.server.ThreadingHTTPServer(('127.0.0.1',0),m.Provider)
            srv.issuer=f'https://127.0.0.1:{srv.server_port}/realm';srv.key=key;srv.jwk={'kty':'RSA','kid':'test-key','alg':'RS256','use':'sig','n':m.b64(bytes.fromhex(mod)),'e':'AQAB'}
            srv.codes={};srv.revoked=[];srv.groups=['readers'];srv.subject='identity-'+name;srv.sid='sid-'+name;srv.bad_nonce=False
            srv.socket=tls.wrap_socket(srv.socket,server_side=True)
            threading.Thread(target=srv.serve_forever,daemon=True).start();servers.append(srv)
        env=os.environ.copy();dsn=env.pop('BOOK_PG_DSN',None);env.pop('BOOK_REDIS_HOST',None)
        env.update(BOOK_DATA_DIR=str(tmp/'data'),BOOK_SECURE_COOKIE='1',BOOK_OIDC_REQUIRED='0',BOOK_PG_NAMESPACE=uuid.uuid4().hex)
        for k in list(env):
            if k.startswith(('BOOK_OIDC_CONFIG','BOOK_OIDC_CLIENT_SECRET','BOOK_SKIF4ER_','BOOK_OIDC_PROVIDERS','BOOK_OIDC_ONE_','BOOK_OIDC_TWO_')):env.pop(k,None)
        setup=json.loads(m.php(env,r'''$s=new CajeerBook\IdentityStore(getenv('BOOK_DATA_DIR'));$s->initialize('owner','Owner','owner@example.org','owner-password-123');$owner=$s->login('owner','owner-password-123','192.0.2.1')['token'];$o=$s->createOrganization($owner,'Docs','docs');$s->createUser($owner,'writer','Writer','writer@example.org','writer-password-123');$local=$s->login('writer','writer-password-123','192.0.2.2')['token'];$inv=$s->mutateOrganization($owner,$o['id'],$s->organization($owner,$o['id'])['revision'],'invite',['username'=>'writer']);$s->acceptInvitation($inv['token'],$local,'192.0.2.2');echo json_encode(['org'=>$o['id'],'session'=>$local]);'''))
        if '--postgres' in __import__('sys').argv:
            if not dsn:raise RuntimeError('PostgreSQL required for --postgres')
            env['BOOK_PG_DSN']=dsn
            m.php(env,r'''CajeerBook\PostgresContentStore::migrate(getenv('BOOK_DATA_DIR'));''')
        cfg={name:{'issuer':srv.issuer,'client_id':'book','redirect_uri':'https://book.example.org/auth/oidc/callback','organization':setup['org'],'label':name+' ID','groups':{'readers':'viewer'},'backchannel_logout':True,'post_logout_redirect_uri':'https://book.example.org/login'} for name,srv in zip(['one','two'],servers)}
        env.update(BOOK_OIDC_PROVIDERS=json.dumps(cfg),BOOK_OIDC_ONE_CLIENT_SECRET='test-secret',BOOK_OIDC_TWO_CLIENT_SECRET='test-secret')
        port=m.freeport();log=(tmp/'node.log').open('w+')
        proc=subprocess.Popen(['php','-d',f'openssl.cafile={cert}','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],env=env,cwd=ROOT,stdout=log,stderr=log)
        try:
            for _ in range(100):
                try:
                    if m.request(port,'/login')[0]==200:break
                except OSError:pass
                time.sleep(.05)
            else:raise RuntimeError('Book web node unavailable')
            st,h,login=m.request(port,'/login');m.check(st==200 and b'Sign in with one ID' in login and b'Sign in with two ID' in login,'login renders both configured provider buttons')
            local={'__Host-book_session':setup['session']}
            def csrf(html):return re.search(rb'name="csrf" value="([a-f0-9]{64})"',html).group(1).decode()
            def idp_authorize(redirect):
                target=urllib.parse.urlsplit(redirect);port=int(target.port)
                c=http.client.HTTPSConnection('127.0.0.1',port,context=ssl.create_default_context(cafile=str(cert)))
                c.request('GET',target.path+'?'+target.query);response=c.getresponse();assert response.status==302
                callback=urllib.parse.urlsplit(response.getheader('Location'));response.read();c.close();return callback.path+'?'+callback.query
            def start(which, linking, browserCookie=None):
                if linking:
                    status,_,page=m.request(port,'/account',cookies=local);assert status==200
                    fields={'csrf':csrf(page),'provider':which,'intent':'link','password':'writer-password-123'}
                    cookies=local
                else:
                    status,h,page=m.request(port,'/login');assert status==200
                    pre,_=m.cookie(h,'__Host-book_preauth');cookies={'__Host-book_preauth':pre};fields={'csrf':csrf(page),'provider':which}
                status,h,_=m.request(port,'/auth/oidc/start','POST',fields,cookies);assert status==303,(status,h)
                browser,_=m.cookie(h,'__Host-book_oidc')
                return idp_authorize(m.header(h,'Location')),browser
            for which in ['one','two']:
                path,browser=start(which,True)
                bad=m.request(port,path,cookies={'__Host-book_oidc':'f'*64})
                m.check(bad[0]==401,'browser-bound link callback rejects foreign cookie')
                status,h,body=m.request(port,path,cookies={'__Host-book_oidc':browser})
                m.check(status==200 and b'Identity provider linked' in body and b'href="/account"' in body,'cross-site callback links '+which+' without SameSite=Strict session cookie')
                m.check(m.request(port,'/account',cookies=local)[0]==200,'local account remains authorized')
            status,_,page=m.request(port,'/account',cookies=local)
            m.check('one ID · Connected'.encode() in page and 'two ID · Connected'.encode() in page,'account shows two independent linked providers')
            for which in ['one','two']:
                path,browser=start(which,False)
                status,h,_=m.request(port,path,cookies={'__Host-book_oidc':browser});assert status==303,(status,h)
                token,_=m.cookie(h,'__Host-book_session')
                m.check(m.request(port,'/account',cookies={'__Host-book_session':token})[0]==200,'linked '+which+' authorizes real Book session')
                if which=='one':first=token
                else:second=token
            # Distinct backchannel issuer/audience is selected only through a known provider id.
            now=int(time.time());claims={'iss':servers[0].issuer,'sub':'identity-one','aud':'book','iat':now,'jti':uuid.uuid4().hex,'events':{'http://schemas.openid.net/event/backchannel-logout':{}},'sid':'sid-one'}
            data=m.b64(json.dumps({'alg':'RS256','kid':'test-key'}).encode())+'.'+m.b64(json.dumps(claims).encode())
            signature=subprocess.check_output(['openssl','dgst','-sha256','-sign',str(key)],input=data.encode());jwt=data+'.'+m.b64(signature)
            m.check(m.request(port,'/auth/oidc/backchannel-logout?provider=two','POST',{'logout_token':jwt})[0]==400,'cross-provider signed logout token rejected')
            m.check(m.request(port,'/auth/oidc/backchannel-logout?provider=one','POST',{'logout_token':jwt})[0]==200,'correct provider accepts signed logout')
            m.check(m.request(port,'/account',cookies={'__Host-book_session':first})[0]==401 and m.request(port,'/account',cookies={'__Host-book_session':second})[0]==200,'provider-scoped logout does not revoke other provider or local session')
            status,_,page=m.request(port,'/account',cookies=local);assert status==200
            m.check(m.request(port,'/account','POST',{'csrf':csrf(page),'action':'unlink-oidc','provider':'one','password':'wrong'},local)[0] in (409,422),'unlink rejects incorrect local password')
            status,h,_=m.request(port,'/account','POST',{'csrf':csrf(page),'action':'unlink-oidc','provider':'one','password':'writer-password-123'},local)
            m.check(status==303,'unlink authenticated and persisted by account controller')
            page=m.request(port,'/account',cookies=local)[2]
            m.check('one ID · Connected'.encode() not in page and 'two ID · Connected'.encode() in page,'unlink leaves other provider association intact')
        finally:
            proc.terminate();proc.wait(timeout=10);log.close()
            if proc.returncode not in (-15,0):print((tmp/'node.log').read_text()[-3000:])
            for server in servers:server.shutdown();server.server_close()
if __name__=='__main__':main()
