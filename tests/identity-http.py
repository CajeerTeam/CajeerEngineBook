#!/usr/bin/env python3
"""Real PHP HTTP authentication tests; no Markdown rendering required."""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
PASSWORD = 'legacy-http-password-123'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(NoRedirect(), urllib.request.HTTPCookieProcessor(self.jar), urllib.request.ProxyHandler({}))
    def request(self, path, expected, form=None, headers=None):
        data = urllib.parse.urlencode(form).encode() if form is not None else None
        req = urllib.request.Request(self.base+path, data=data, headers=headers or {})
        try:
            result = self.opener.open(req, timeout=10)
        except urllib.error.HTTPError as exc:
            result = exc
        with result:
            content = result.read()
            assert result.status == expected, (path, expected, result.status, content[:600])
            return result.headers, content
    def csrf(self, path):
        _, content = self.request(path, 200)
        return re.search(rb'name="csrf" value="([a-f0-9]{64})"', content).group(1).decode()
    def login(self, username='admin', password=PASSWORD, expected=303):
        csrf = self.csrf('/login')
        return self.request('/login', expected, {'username':username,'password':password,'csrf':csrf})

def check(name, condition=True):
    assert condition, name
    print('PASS', name, flush=True)

def exercise(base, env, data):
    guest, owner, other, member = [Client(base) for _ in range(4)]
    _, original = guest.request('/api/v1/pages', 200)
    guest.request('/studio', 401)
    guest.request('/studio/export', 401)
    guest.request('/studio/media/backup', 401)
    guest.request('/studio/media/picker', 401)
    guest.request('/studio', 401, headers={'Authorization':'Basic '+base64.b64encode(f'admin:{PASSWORD}'.encode()).decode()})
    check('all Studio routes require cookie authentication; Basic disabled')
    owner.request('/login', 403, {'username':'admin','password':PASSWORD})
    token = owner.csrf('/login')
    guest.request('/login', 403, {'username':'admin','password':PASSWORD,'csrf':token})
    check('login CSRF requires same browser cookie')
    owner.request('/login', 401, {'username':'admin','password':'wrong','csrf':token})
    headers, _ = owner.request('/login', 303, {'username':'admin','password':PASSWORD,'csrf':token})
    cookies = headers.get_all('Set-Cookie'); assert any('book_session=' in c and 'HttpOnly' in c and 'SameSite=Strict' in c for c in cookies)
    check('legacy login creates owner and private HttpOnly session cookie')
    owner.request('/studio', 200)
    other.login()
    csrf = owner.csrf('/account/users')
    owner.request('/account/users', 403, {'action':'create','username':'writer','name':'Writer','email':'writer@example.org','password':'writer-password-123'})
    owner.request('/account/users', 303, {'action':'create','username':'writer','name':'Writer','email':'writer@example.org','password':'writer-password-123','csrf':csrf})
    _, body = owner.request('/account/users', 200); uid = re.search(rb'name="id" value="([a-f0-9]{32})"',body).group(1).decode()
    check('owner creates user with CSRF-protected form')
    member.login('writer','writer-password-123')
    member.request('/account', 200); member.request('/studio',403); member.request('/studio/export',403);member.request('/studio/media',403);member.request('/studio/tree',403);member.request('/account/users',403)
    member.request('/studio',403,{'csrf':member.csrf('/account'),'action':'publish'})
    check('ordinary account cannot use Studio or management endpoints')
    owner.request('/account/users',303,{'csrf':csrf,'action':'disable','id':uid}); member.request('/account',401)
    member.login('writer','writer-password-123',401)
    owner.request('/account/users',303,{'csrf':csrf,'action':'enable','id':uid});member.login('writer','writer-password-123')
    check('disable immediately revokes sessions; enable requires new login')
    member_csrf=member.csrf('/account');member.request('/account',303,{'csrf':member_csrf,'action':'profile','name':'Writer Updated','email':'updated@example.org'})
    _, body=member.request('/account',200);assert b'Writer Updated' in body
    check('profile persists')
    raw = (data/'identity'/'identity.json').read_text(); assert PASSWORD not in raw
    _, backup = owner.request('/studio/export',200); assert 'users' not in json.loads(backup) and b'identity' not in backup and b'$2y$' not in backup
    guest.request('/identity/identity.json',404)
    check('identity secrets excluded from content export and public paths')
    reset = subprocess.check_output(['php','bin/identity.php','reset','writer'],cwd=ROOT,env=env,text=True).strip()
    assert len(reset)==64 and reset not in (data/'identity'/'identity.json').read_text()
    r=Client(base);r_csrf=r.csrf('/account/reset');r.request('/account/reset',303,{'csrf':r_csrf,'token':reset,'password':'recovered-password-123','confirm':'recovered-password-123'})
    member.request('/account',401);r_csrf=r.csrf('/account/reset');r.request('/account/reset',401,{'csrf':r_csrf,'token':reset,'password':'another-password-123','confirm':'another-password-123'})
    member.login('writer','recovered-password-123')
    check('CLI recovery token is single use and revokes existing sessions')
    owner_csrf=owner.csrf('/account'); owner.request('/account',303,{'csrf':owner_csrf,'action':'password','old':PASSWORD,'password':'owner-new-password-123','confirm':'owner-new-password-123'})
    other.request('/studio',401);owner.request('/studio',200)
    other.login('admin',PASSWORD,401)
    other.login('admin','owner-new-password-123')
    check('password change revokes prior sessions and old environment hash cannot authenticate')
    owner_csrf=owner.csrf('/account');owner.request('/logout',405);owner.request('/logout',403,{})
    owner.request('/logout',303,{'csrf':owner_csrf});owner.request('/studio',401);other.request('/studio',200)
    check('logout requires POST and CSRF; only current session revoked')
    other_csrf=other.csrf('/account');other.request('/account',303,{'csrf':other_csrf,'action':'revoke','id':'all'});other.request('/studio',401)
    check('revoke all sessions')
    for _ in range(5):guest.login('nonexistent','wrong',401)
    headers,_=guest.login('nonexistent','wrong',429);assert headers['Retry-After']=='900'
    check('persistent account rate limit and generic failure')
    _, after=guest.request('/api/v1/pages',200);assert original==after
    check('existing public documentation unchanged by authentication migration')

def main():
    with tempfile.TemporaryDirectory(prefix='book-identity-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';hash_=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True)
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=hash_)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(50):
                    try:
                        Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,env,data)
                # CLI account backup restores only users into another data directory.
                backup=tmp/'identity-backup.json';subprocess.run(['php','bin/identity.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
                restored=dict(env,BOOK_DATA_DIR=str(tmp/'restored'));subprocess.run(['php','bin/identity.php','restore',str(backup)],cwd=ROOT,env=restored,check=True)
                state=json.loads((tmp/'restored'/'identity'/'identity.json').read_text());assert len(state['users'])==2 and not state['sessions'] and not state['resets'];check('CLI backup/restore preserves users, drops all credentials except password hashes')
            except Exception:
                log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
