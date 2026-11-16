#!/usr/bin/env python3
"""Real TLS wire tests for bounded reads, framing and DNS policy."""
import json, pathlib, socket, ssl, subprocess, tempfile, threading, time
ROOT=pathlib.Path(__file__).resolve().parents[1]
PHP=r'''require 'tests/oidc-bootstrap.php';$start=hrtime(true);try{$r=CajeerBook\ApiPlaygroundTransport::integration($argv[1],$argv[2],[],'',1024,0.8,$argv[3]==='public');echo json_encode(['ok'=>true,'body'=>$r['body'],'ms'=>(hrtime(true)-$start)/1e6]);}catch(Throwable $e){echo json_encode(['ok'=>false,'ms'=>(hrtime(true)-$start)/1e6]);}'''
with tempfile.TemporaryDirectory() as d:
    cert=pathlib.Path(d)/'ca.pem';key=pathlib.Path(d)/'key.pem'
    subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-days','1','-subj','/CN=localhost','-addext','subjectAltName=DNS:localhost','-keyout',str(key),'-out',str(cert)],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key)
    count=0
    def run(wire,expected,method='GET',slow=False,stall=False,public=False):
        global count
        server=socket.socket();server.bind(('127.0.0.1',0));server.listen();server.settimeout(2);port=server.getsockname()[1]
        def serve():
            try:
                with server.accept()[0] as conn:
                    if stall:time.sleep(1.5);return
                    with ctx.wrap_socket(conn,server_side=True) as tls:
                        tls.settimeout(2);data=b''
                        while b'\r\n\r\n' not in data:
                            chunk=tls.recv(4096)
                            if not chunk:return
                            data+=chunk
                        if slow:
                            for b in wire:tls.sendall(bytes([b]));time.sleep(.12)
                        else:tls.sendall(wire)
            except (OSError,ssl.SSLError):pass
            finally:server.close()
        t=threading.Thread(target=serve,daemon=True);t.start()
        r=json.loads(subprocess.check_output(['php','-d',f'openssl.cafile={cert}','-r',PHP,f'https://localhost:{port}/',method,'public' if public else 'private'],cwd=ROOT,timeout=4))
        assert r['ok']==expected,r
        if expected:assert r['body']==('' if wire.startswith(b'HTTP/1.1 204') else 'hello'),r
        if slow or stall:assert 600<=r['ms']<1500,r
        t.join(timeout=3);count+=1
    h=b'HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n'
    run(h+b'5\r\nhello\r\n0\r\nX-Trace: yes\r\n\r\n',True)
    run(h+b'5\r\nhello\r\n0\r\n',False)
    run(h+b'0\r\nContent-Length: 0\r\n\r\n',False)
    run(h+b'0\r\nbad trailer\r\n\r\n',False)
    run(b'HTTP/1.1 200 OK\r\nContent-Length: 5\r\nContent-Length: 5\r\n\r\nhello',False)
    run(b'HTTP/1.1 200 OK\r\nContent-Length: 5\r\nTransfer-Encoding: chunked\r\n\r\n',False,method='HEAD')
    run(b'HTTP/1.1 200 OK\r\nContent-Length: 8\r\n\r\nhello',False)
    run(b'HTTP/1.1 200 OK\r\nX-Long: '+b'x'*9000+b'\r\n\r\n',False)
    run(b'HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello',False,slow=True)
    run(b'HTTP/1.1 204 No Content\r\n\r\n',True)
    run(b'HTTP/1.1 204 No Content\r\nContent-Length: 0\r\n\r\n',False)
    run(b'HTTP/1.1 100 Continue\r\nContent-Length: 0\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello',False)
    run(b'HTTP/1.1 103 Early Hints\r\nLink: </x>\r\n\r\nHTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello',True)
    run(b'',False,stall=True)
    run(b'',False,public=True)
    print(f'PASS {count} real TLS framing/deadline/address policy checks')
