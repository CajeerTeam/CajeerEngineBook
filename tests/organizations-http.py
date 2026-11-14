#!/usr/bin/env python3
"""Real HTTP tests for organizations with distinct cookie sessions and invite signup."""
import json
import os
from pathlib import Path
import re
import socket
import subprocess
import tempfile
import time
from importlib.util import spec_from_file_location, module_from_spec

ROOT = Path(__file__).resolve().parents[1]
spec = spec_from_file_location('identity_http', ROOT/'tests/identity-http.py')
module = module_from_spec(spec); spec.loader.exec_module(module)
Client, check, PASSWORD = module.Client, module.check, module.PASSWORD


def form(client, path, action, expected=303, **fields):
    _, body = client.request(path, 200)
    csrf = re.search(rb'name="csrf" value="([a-f0-9]{64})"', body).group(1).decode()
    rev = re.search(rb'name="revision" value="(\d+)"', body)
    data = {'csrf': csrf, 'action': action}
    if rev: data['revision'] = rev.group(1).decode()
    return client.request(path, expected, data | fields)


def invite(client, path, username):
    _, body = form(client,path,'invite',200,username=username)
    return re.search(rb'/organizations/join#([a-f0-9]{64})', body).group(1).decode()


def exercise(base, data, env):
    guest, owner, member, newbie = [Client(base) for _ in range(4)]
    guest.request('/organizations',401)
    guest.request('/organizations/join',403,{'action':'accept','token':'a'*64})
    owner.login(); member.request('/organizations',401)
    form(owner,'/account/users','create',username='writer',name='Writer',email='writer@example.org',password='writer-password-123')
    member.login('writer','writer-password-123')
    headers,_=form(owner,'/organizations','create',name='Engineering <team>',slug='engineering');path=headers['Location'];assert re.fullmatch(r'/organizations/[a-f0-9]{32}',path)
    _,body=owner.request(path,200);assert b'Engineering &lt;team&gt;' in body and b'Engineering <team>' not in body
    member.request(path,404);_,body=member.request('/organizations',200);assert b'engineering' not in body
    check('organization creation, escaped name, nonmember isolation')
    form(member,'/organizations','create',name='Duplicate',slug='ENGINEERING',expected=409)
    headers,_=form(member,'/organizations','create',name='Other team',slug='other-team');other=headers['Location'];owner.request(other,404)
    owner.request(path,403,{'action':'archive','revision':'1'})
    member.request(path,404,{'action':'rename','revision':'1','csrf':member.csrf('/account'),'name':'Attack','slug':'attack'})
    check('unique slugs, cross-organization access, CSRF')
    token=invite(owner,path,'writer');assert token not in (data/'identity/identity.json').read_text()
    _,body=owner.request(path,200);assert token.encode() not in body
    form(guest,'/organizations/join','accept',422,token=token,name='Wrong',email='wrong@example.org',password='wrong-password-123',confirm='wrong-password-123')
    owner.request('/organizations/join',422,{'csrf':owner.csrf('/account'),'action':'accept','token':token})
    headers,_=form(member,'/organizations/join','accept',token=token);assert headers['Location']==path
    form(member,'/organizations/join','accept',422,token=token)
    check('existing-account invitation binding, no plaintext persistence, single use')
    member.request(path,200);form(member,path,'invite',403,username='newbie');member.request('/studio',403)
    check('ordinary organization member cannot invite or access global Studio')
    t=invite(owner,path,'newbie');old=invite(owner,path,'newbie');assert old!=t
    form(newbie,'/organizations/join','accept',422,token=t,name='New User',email='new@example.org',password='newbie-password-123',confirm='newbie-password-123')
    h,_=form(newbie,'/organizations/join','accept',token=old,name='New User',email='new@example.org',password='newbie-password-123',confirm='newbie-password-123');assert h['Location']=='/login?joined=1'
    newbie.login('newbie','newbie-password-123');newbie.request(path,200);newbie.request('/studio',403)
    check('replacement invalidates previous token; invited registration works')
    state=json.loads((data/'identity/identity.json').read_text());uid=next(u['id'] for u in state['users'].values() if u['username']=='writer')
    _,body=owner.request(path,200);revision=re.search(rb'name="revision" value="(\d+)"',body).group(1).decode()
    form(owner,path,'rename',name='Engineering updated',slug='engineering')
    owner.request(path,409,{'csrf':owner.csrf('/account'),'action':'archive','revision':revision})
    form(owner,path,'transfer',user=uid)
    form(owner,path,'archive',403)
    form(owner,'/account/users','disable',409,id=uid)
    check('revision conflicts, ownership transfer and disabled-owner guard')
    form(member,path,'archive');_,body=member.request(path,200);assert b'Archived' in body
    form(member,path,'invite',409,username='future')
    form(member,path,'restore');form(member,path,'remove',user=state['organizations'][path.rsplit('/',1)[1]]['owner'])
    # state snapshot predates transfer, so the removed member is original owner.
    owner.request(path,404);form(newbie,path,'leave');newbie.request(path,404)
    check('archive, restore, member removal and leave')
    _,export=owner.request('/studio/export',200);assert b'organizations' not in export and b'identity' not in export
    backup=data.parent/'org-backup.json';subprocess.run(['php','bin/identity.php','backup',str(backup)],cwd=ROOT,env=env,check=True)
    raw=json.loads(backup.read_text());assert path.rsplit('/',1)[1] in raw['organizations'] and 'invitations' not in raw and 'sessions' not in raw
    check('organization backup and content export isolation')
    form(member,path,'archive');form(member,path,'delete',409,confirm='wrong');form(member,path,'delete',confirm='engineering');member.request(path,404);member.request(other,200)
    check('confirmed deletion does not affect other organizations')


def main():
    with tempfile.TemporaryDirectory(prefix='book-org-http-') as tmp:
        tmp=Path(tmp);data=tmp/'data';hash_=subprocess.check_output(['php','-r','echo password_hash($argv[1], PASSWORD_DEFAULT);',PASSWORD],text=True)
        env=dict(os.environ,BOOK_DATA_DIR=str(data),BOOK_SECURE_COOKIE='0',BOOK_ADMIN_HASH=hash_)
        with socket.socket() as sock:sock.bind(('127.0.0.1',0));port=sock.getsockname()[1]
        base=f'http://127.0.0.1:{port}'
        with (tmp/'server.log').open('w+') as log:
            server=subprocess.Popen(['php','-S',f'127.0.0.1:{port}','-t','public','public/router.php'],cwd=ROOT,env=env,stdout=log,stderr=log)
            try:
                for _ in range(60):
                    try:Client(base).request('/health/live',200);break
                    except OSError:time.sleep(.1)
                exercise(base,data,env)
            except Exception:
                log.seek(0);print(log.read());raise
            finally:server.terminate();server.wait(timeout=5)

if __name__=='__main__':main()
