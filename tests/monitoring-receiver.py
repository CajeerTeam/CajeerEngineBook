"""Disposable receiver for real Alertmanager notifications, not deployed in production."""
from http.server import BaseHTTPRequestHandler, HTTPServer
import json
class Receiver(BaseHTTPRequestHandler):
    def do_POST(self):
        n=int(self.headers.get('Content-Length','0'))
        if self.path!='/alerts' or not 0<n<=1048576:
            self.send_error(400);return
        data=json.loads(self.rfile.read(n))
        with open('/alerts/events.jsonl','a',encoding='utf-8') as f:
            f.write(json.dumps(data,separators=(',',':'))+'\n')
        self.send_response(204);self.end_headers()
HTTPServer(('0.0.0.0',8080),Receiver).serve_forever()
