"""Dependency-free synchronous HTTPS client for published CajeerBook operations."""
import base64
import json
import math
import re
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

@dataclass(frozen=True)
class Response:
    status: int
    headers: dict[str, str]
    body: bytes
    def text(self) -> str:
        return self.body.decode('utf-8')
    def json(self):
        return json.loads(self.body)

def _enc(v):
    return urllib.parse.quote(v, safe='')

def _scalar(v, schema):
    if not isinstance(v, str) or len(v.encode()) > 4096 or re.search(r'[\x00-\x1f\x7f]', v):
        raise ValueError('Invalid parameter value')
    t = schema['type']
    if (t == 'integer' and not re.fullmatch(r'-?(0|[1-9][0-9]*)', v)) or (t == 'number' and (not re.fullmatch(r'[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?', v, re.I | re.ASCII) or not math.isfinite(float(v)))) or (t == 'boolean' and v not in ('true', 'false')):
        raise ValueError('Invalid parameter type')
    if 'enum' in schema and v not in [str(x).lower() if isinstance(x, bool) else str(x) for x in schema['enum']]:
        raise ValueError('Invalid enum value')
    return v

def build_request(d, *, server=0, security=0, values=(), auth=(), body='', content_type=''):
    if type(server) is not int or not 0 <= server < len(d['servers']) or type(security) is not int or not 0 <= security < len(d['security']) or not isinstance(values, (list, tuple)) or not isinstance(auth, (list, tuple)) or len(values) != len(d['parameters']) or len(auth) != len(d['security'][security]):
        raise ValueError('Invalid server, security or parameter count')
    path = d['path']; query = []; cookies = []; headers = {'Accept': 'application/json, text/plain, */*'}
    def put(loc, name, v):
        nonlocal path
        if loc == 'path':
            if not v or v in ('.', '..') or re.search(r'[/\\%]', v): raise ValueError('Unsafe path parameter')
            path = path.replace('{'+name+'}', _enc(v))
        elif loc == 'query': query.append(_enc(name)+'='+_enc(v))
        elif loc == 'cookie': cookies.append(_enc(name)+'='+_enc(v))
        else:
            if re.search(r'[^\x20-\x7e]', v): raise ValueError('Invalid header value')
            headers[name] = v
    for p, v in zip(d['parameters'], values):
        if v is None or v == '':
            if p['required']: raise ValueError('Required parameter: '+p['name'])
        else: put(p['in'], p['name'], _scalar(v, p['schema']))
    if '{' in path or '}' in path: raise ValueError('Missing path parameter')
    for a, v in zip(d['security'][security], auth):
        v = _scalar(v, {'type':'string'})
        if not v: raise ValueError('Missing credential')
        if a['kind'] == 'basic':
            if ':' not in v: raise ValueError('Basic auth requires username:password')
            v = 'Basic '+base64.b64encode(v.encode()).decode('ascii')
        elif a['kind'] == 'bearer': v = 'Bearer '+v
        put(a['in'], a['field'], v)
    if cookies: headers['Cookie'] = '; '.join(cookies)
    if not isinstance(body, str) or len(body.encode()) > 262144: raise ValueError('Invalid request body')
    if body:
        if content_type not in d['body_types'] or d['method'] in ('GET','HEAD'): raise ValueError('Invalid content type')
        if content_type == 'application/json' or content_type.endswith('+json'):
            def invalid_constant(_): raise ValueError('Invalid JSON constant')
            json.loads(body, parse_constant=invalid_constant)
        headers['Content-Type'] = content_type
    elif d['body_required']: raise ValueError('Required body')
    url = d['servers'][server]+path+('?'+'&'.join(query) if query else '')
    if len(url.encode()) > 8192 or sum(len(k.encode())+len(v.encode()) for k,v in headers.items()) > 16384: raise ValueError('Request too large')
    return urllib.request.Request(url, data=body.encode() if body else None, headers=headers, method=d['method'])

class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

def execute(d, **kwargs):
    req = build_request(d, **kwargs)
    # Disable environment proxies; TLS validation uses Python's default trust store.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}), _NoRedirect())
    try:
        res = opener.open(req, timeout=15)
    except urllib.error.HTTPError as exc:
        res = exc
    with res:
        body = res.read(1048577)
        if len(body) > 1048576: raise ValueError('Response exceeds 1 MiB')
        return Response(res.status, dict(res.headers.items()), body)
