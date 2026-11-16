#!/usr/bin/env python3
"""Actual Nginx + two PHP app processes, shared media and abrupt replica failure.
Only replaces Docker DNS/upstream addresses and log/listen paths for local Nginx.
"""
import concurrent.futures as cf
import http.client
import http.server
import threading
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
SEED = r'''
require 'tests/oidc-bootstrap.php';
use CajeerBook\{IdentityStore,BookStore,MediaStore};
$r=getenv('BOOK_DATA_DIR');(new IdentityStore($r))->initialize('owner','Owner','owner@example.org','password-owner-123');
$b=new BookStore($r);$b->createBook('reliability','Reliability');$f=$r.'/upload.txt';file_put_contents($f,'Reliable media bytes');$m=new MediaStore($r,$b);$id=$m->upload($f,'file.txt')['item']['id'];
$b->change(function(&$d)use($id){$p=['title'=>'Reliability','section'=>'Test','order'=>1,'body'=>'Real published page [media](/media/'.$id.')','updated'=>gmdate('c'),'media'=>[$id]];$d['pages']['reliability/1/start']=['id'=>bin2hex(random_bytes(16)),'revision'=>1,'draft'=>$p,'published'=>$p];});echo $id;
'''

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); return s.getsockname()[1]

def request(p, path, method='GET'):
    c = http.client.HTTPConnection('127.0.0.1', p, timeout=12)
    try:
        c.request(method, path); r = c.getresponse(); return r.status, r.read()
    finally: c.close()

def ready(p):
    for _ in range(100):
        try:
            if request(p, '/health/live')[0] == 200: return
        except OSError: pass
        time.sleep(.05)
    raise RuntimeError('Server failed startup')

def main():
    nginx = shutil.which('nginx')
    if not nginx: raise RuntimeError('Nginx required; no simulated proxy')
    with tempfile.TemporaryDirectory(prefix='book-reliability-http-') as temp:
        t = Path(temp); env = {k:v for k,v in os.environ.items() if not k.startswith('BOOK_')}
        env.update(BOOK_DATA_DIR=str(t/'data'), BOOK_SECURE_COOKIE='0')
        mid = subprocess.check_output(['php','-r',SEED],cwd=ROOT,env=env,text=True).strip()
        assert len(mid)==32
        ports=[port(),port()]; edge=port(); processes=[]; logs=[]
        try:
            for p in ports:
                log=(t/f'php-{p}.log').open('w+');logs.append(log)
                proc=subprocess.Popen(['php','-S',f'127.0.0.1:{p}','-t','public','tests/oidc-bootstrap.php'],cwd=ROOT,env=env,stdout=log,stderr=log);processes.append(proc);ready(p)
            source=(ROOT/'deploy/book-nginx.conf').read_text()
            source=source.replace('resolver 127.0.0.11 valid=5s ipv6=off;','').replace('server book:80 resolve max_fails=1 fail_timeout=5s;', '\n'.join(f'server 127.0.0.1:{p} max_fails=1 fail_timeout=5s;' for p in ports)).replace('listen 80;',f'listen 127.0.0.1:{edge};').replace('/var/log/nginx/access.log',str(t/'access.log'))
            config=t/'nginx.conf';config.write_text(f'pid {t}/nginx.pid; error_log {t}/nginx-error.log; worker_processes 1; events {{ worker_connections 256; }} http {{ client_body_temp_path {t}/client_body; proxy_temp_path {t}/proxy; fastcgi_temp_path {t}/fastcgi; uwsgi_temp_path {t}/uwsgi; scgi_temp_path {t}/scgi; {source} }}')
            subprocess.run([nginx,'-t','-c',str(config),'-p',temp],check=True)
            log=(t/'nginx.log').open('w+');logs.append(log);p=subprocess.Popen([nginx,'-c',str(config),'-p',temp,'-g','daemon off;'],stdout=log,stderr=log);processes.append(p);ready(edge)
            api='/api/v1/pages/reliability/1/start'; media='/media/'+mid
            def read(i):
                if i%2: assert request(edge,media)==(200,b'Reliable media bytes')
                else:
                    status,body=request(edge,api);assert status==200 and json.loads(body)['title']=='Reliability'
            with cf.ThreadPoolExecutor(max_workers=12) as pool:
                list(pool.map(read,range(40)));processes[0].kill();processes[0].wait(timeout=5);list(pool.map(read,range(80)))
            print('PASS actual Nginx switches safe reads after SIGKILL of PHP replica (120 responses)')
            blob=t/'data'/'media'/(mid+'.blob'); original=blob.read_bytes();blob.write_bytes(b'X'*len(original))
            assert request(edge,media)[0]==503 and request(edge,media,'HEAD')[0]==503
            print('PASS same-size corrupt media rejected by actual HTTP before response')
            # Restore exact authenticated bytes; a mismatched repair is rejected and cannot change metadata.
            repair=r'''require 'tests/oidc-bootstrap.php';$r=getenv('BOOK_DATA_DIR');$m=new CajeerBook\MediaStore($r,new CajeerBook\BookStore($r));$m->repair($argv[1],$argv[2]);'''
            wrong=t/'wrong.txt';wrong.write_bytes(b'Y'*len(original));bad=subprocess.run(['php','-r',repair,mid,str(wrong)],cwd=ROOT,env=env,capture_output=True);assert bad.returncode!=0 and blob.read_bytes()==b'X'*len(original)
            good=t/'good.txt';good.write_bytes(original);subprocess.run(['php','-r',repair,mid,str(good)],cwd=ROOT,env=env,check=True);assert request(edge,media)==(200,original)
            print('PASS exact-byte media repair restores original public URL; wrong source rejected')
        except BaseException:
            for log in logs: log.seek(0);print(log.read())
            if (t/'nginx-error.log').exists(): print((t/'nginx-error.log').read_text())
            raise
        finally:
            for p in reversed(processes):
                if p.poll() is None: p.terminate();p.wait(timeout=10)
            for log in logs:log.close()

def transport_replay_test():
    # Transport-only fixture: one backend accepts a request, then dies before returning headers.
    # It verifies actual proxy behavior independently of the real App test above.
    seen=[]
    class Abort(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            seen.append(('primary',self.command,self.path));self.close_connection=True
            self.connection.shutdown(socket.SHUT_RDWR);self.connection.close()
        do_POST=do_GET
        def log_message(self,*args): pass
    class Backup(http.server.BaseHTTPRequestHandler):
        def do_GET(self):
            seen.append(('backup',self.command,self.path));self.send_response(200);self.send_header('Content-Length','2');self.end_headers();self.wfile.write(b'ok')
        do_POST=do_GET
        def log_message(self,*args): pass
    servers=[http.server.ThreadingHTTPServer(('127.0.0.1',0),handler) for handler in (Abort,Backup)]
    for server in servers:threading.Thread(target=server.serve_forever,daemon=True).start()
    with tempfile.TemporaryDirectory(prefix='book-proxy-replay-') as temp:
        t=Path(temp);edge=port();nginx=shutil.which('nginx');p=None
        try:
            source=(ROOT/'deploy/book-nginx.conf').read_text().replace('resolver 127.0.0.11 valid=5s ipv6=off;','').replace('server book:80 resolve max_fails=1 fail_timeout=5s;',f'server 127.0.0.1:{servers[0].server_port} max_fails=0; server 127.0.0.1:{servers[1].server_port} backup;').replace('listen 80;',f'listen 127.0.0.1:{edge};').replace('/var/log/nginx/access.log',str(t/'access.log'))
            cfg=t/'nginx.conf';cfg.write_text(f'pid {t}/nginx.pid; error_log {t}/error.log; events {{ worker_connections 64; }} http {{ client_body_temp_path {t}/client; proxy_temp_path {t}/proxy; fastcgi_temp_path {t}/fastcgi; uwsgi_temp_path {t}/uwsgi; scgi_temp_path {t}/scgi; {source} }}')
            p=subprocess.Popen([nginx,'-c',str(cfg),'-p',temp,'-g','daemon off;'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            for _ in range(100):
                try:
                    with socket.create_connection(('127.0.0.1',edge),timeout=.1):break
                except OSError:time.sleep(.05)
            else:raise RuntimeError('Proxy replay test did not start')
            assert request(edge,'/studio','POST')[0]==502
            assert request(edge,'/auth/oidc/callback')[0]==502
            assert request(edge,'/api/v1/pages/guide/1/start')==(200,b'ok')
            assert seen==[('primary','POST','/studio'),('primary','GET','/auth/oidc/callback'),('primary','GET','/api/v1/pages/guide/1/start'),('backup','GET','/api/v1/pages/guide/1/start')],seen
            print('PASS Nginx retries read after upstream abort but never replays Studio POST or OIDC callback')
        finally:
            if p and p.poll() is None:p.terminate();p.wait(timeout=10)
            for server in servers:server.shutdown();server.server_close()

if __name__=='__main__':main();transport_replay_test()
