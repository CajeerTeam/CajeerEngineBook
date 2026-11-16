#!/usr/bin/env python3
"""Real authenticated HTTP regression with no optional PHP dependencies."""
import importlib.util, os, pathlib, socket, subprocess, tempfile, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('identity_http', ROOT/'tests/identity-http.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
with tempfile.TemporaryDirectory() as tmp:
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    base=f'http://127.0.0.1:{port}';env={k:v for k,v in os.environ.items() if not k.startswith('BOOK_')};env.update(BOOK_DATA_DIR=tmp,BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',m.PASSWORD],text=True))
    with open(pathlib.Path(tmp)/'server.log','w') as log:
        p=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
        try:
            c=m.Client(base)
            for _ in range(100):
                try:c.request('/health/live',200);break
                except Exception:
                    if p.poll() is not None:raise
                    time.sleep(.05)
            else:raise RuntimeError('server not ready')
            token=c.csrf('/login');c.request('/login',403,{'username':'admin','password':m.PASSWORD,'csrf':token},{'Origin':'https://evil.test'})
            c.login();token=c.csrf('/account');form={'csrf':token,'action':'profile','name':'Security Test Owner','email':'security@example.test'}
            for headers in [{'Origin':'https://evil.test'},{'Origin':'null'},{'Origin':base,'Sec-Fetch-Site':'same-site'}]:c.request('/account',403,form,headers)
            _,body=c.request('/account',200);assert b'Security Test Owner' not in body
            c.request('/account',303,form,{'Origin':base,'Sec-Fetch-Site':'same-origin'});_,body=c.request('/account',200);assert b'Security Test Owner' in body
            for path in ['/api/v1/pages/%2e%2e','/api/v1/pages/%2f','/api/v1//pages']:c.request(path,400)
            c.request('/api/v1/ci',401,{'action':'publish'},{'Origin':'https://evil.test','Authorization':'Bearer invalid','Content-Type':'application/json'})
            print('PASS real authenticated Origin/Fetch Metadata, profile persistence, ambiguous targets and machine authentication')
        finally:p.terminate();p.wait(timeout=10)
