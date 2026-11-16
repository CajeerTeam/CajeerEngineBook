#!/usr/bin/env python3
"""Actual signed HTTPS deliveries, using the private pinned transport for localhost only."""
import hashlib, hmac, http.server, json, os, ssl, subprocess, tempfile, threading, time
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
SECRET='wire-secret-'*4
received=[]
class Receiver(http.server.BaseHTTPRequestHandler):
    def log_message(self,*args): pass
    def do_POST(self):
        body=self.rfile.read(int(self.headers['Content-Length']))
        ts=self.headers['X-Cajeer-Timestamp']; delivery=self.headers['X-Cajeer-Delivery']
        signature='sha256='+hmac.new(SECRET.encode(),ts.encode()+b'.'+body,hashlib.sha256).hexdigest()
        assert hmac.compare_digest(signature,self.headers['X-Cajeer-Signature'])
        assert abs(time.time()-int(ts))<60 and self.path=='/hook'
        assert json.loads(body)=={'id':delivery,'event':'document.changed','at':json.loads(body)['at']}
        received.append((delivery,body))
        self.send_response(503 if len(received)==1 else 204)
        if len(received)==1:self.send_header('Content-Length','0')
        self.end_headers()
with tempfile.TemporaryDirectory(prefix='book-automation-tls-') as td:
    d=Path(td); cert=d/'cert.pem'; key=d/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    server=http.server.HTTPServer(('127.0.0.1',0),Receiver);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
    thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
    env=os.environ|{'BOOK_AUTOMATION_KEY':'ab'*32,'BOOK_WEBHOOK_ORIGINS':f'https://localhost:{server.server_port}','TEST_SECRET':SECRET,'TEST_DIR':str(d/'data'),'TEST_PORT':str(server.server_port),'TEST_ROOT':str(ROOT)}
    code=r'''<?php
spl_autoload_register(static function($c){foreach(['CajeerBook\\'=>'src/','CajeerEngine\\'=>'core/'] as $p=>$d)if(str_starts_with($c,$p)){$f=getenv('TEST_ROOT').'/'.$d.str_replace('\\','/',substr($c,strlen($p))).'.php';if(is_file($f))require_once $f;}});
use CajeerBook\{AutomationQueue as Q,AutomationWorker,BookStore,IdentityStore,ApiPlaygroundTransport};
$root=getenv('TEST_DIR');mkdir($root,0700);$identity=new IdentityStore($root);$owner=$identity->initialize('admin','Admin','admin@example.org','password-admin-123');$s=new BookStore($root);$s->change(fn(&$d)=>$d['pages']=[]);
$url=getenv('BOOK_WEBHOOK_ORIGINS').'/hook';$s->change(fn(&$d)=>Q::configure($d,$owner['id'],'','',$url,getenv('TEST_SECRET'),['document.changed']));$s->change(fn(&$d)=>Q::enqueueEvent($d,'document.changed'));
$id=array_key_last($s->read()['automation']['jobs']);$job=null;$s->change(function(&$d)use(&$job){$job=Q::claim($d,time());});
$req=Q::delivery($s->read()['automation']['config'],$job);try{ApiPlaygroundTransport::send($req,'BOOK_WEBHOOK_ORIGINS');throw new RuntimeException('private IP accepted');}catch(InvalidArgumentException $e){}
$wire=new ReflectionMethod(ApiPlaygroundTransport::class,'sendPinned');$sender=fn($r)=>$wire->invoke(null,$r,'localhost',(int)getenv('TEST_PORT'),'127.0.0.1');
$w=new AutomationWorker($root,$identity,null,$sender);if($w->tick()!==1)throw new RuntimeException('claim recovery failed');$j=$s->read()['automation']['jobs'][$id];if($j['state']!=='queued'||$j['attempts']!==2)throw new RuntimeException('503 retry failed');
$s->change(fn(&$d)=>$d['automation']['jobs'][$id]['due']=time());if($w->tick()!==1||$s->read()['automation']['jobs'][$id]['state']!=='succeeded')throw new RuntimeException('204 delivery failed');echo "PASS real TLS signature, crash recovery, 503 retry and 204 completion\n";
'''
    try: subprocess.run(['php','-d',f'openssl.cafile={cert}'],input=code,text=True,env=env,check=True,timeout=60)
    finally: server.shutdown();server.server_close();thread.join()
    assert len(received)==2 and received[0]==received[1],received
    print('PASS same delivery ID and body on both real HTTPS requests')
