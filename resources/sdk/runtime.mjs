// CajeerBook SDK runtime. No dependencies; Node.js 22+ or modern browsers.
const enc = v => encodeURIComponent(v).replace(/[!'()*]/g,c=>'%'+c.charCodeAt(0).toString(16).toUpperCase());
const bytes = s => new TextEncoder().encode(s).length;
function scalar(v,s){
  if(typeof v!=='string'||bytes(v)>4096||/[\x00-\x1f\x7f]/.test(v))throw new TypeError('Invalid parameter value');
  if(s.type==='integer'&&!/^-?(0|[1-9][0-9]*)$/.test(v)||s.type==='number'&&(!/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i.test(v)||!Number.isFinite(Number(v)))||s.type==='boolean'&&!['true','false'].includes(v)||s.enum&&!s.enum.some(x=>String(x)===v))throw new TypeError('Parameter type or enum mismatch');return v;
}
export function buildRequest(d,input){
  const {server=0,security=0,values=[],auth=[],body='',content_type=''}=input;
  if(!Number.isInteger(server)||!d.servers[server]||!Number.isInteger(security)||!d.security[security]||!Array.isArray(values)||values.length!==d.parameters.length||!Array.isArray(auth)||auth.length!==d.security[security].length)throw new TypeError('Invalid server, security or parameter count');
  let path=d.path;const query=[],cookies=[],headers={'Accept':'application/json, text/plain, */*'};
  function put(loc,name,v){if(loc==='path'){if(!v||['.','..'].includes(v)||/[\\/%]/.test(v))throw new TypeError('Unsafe path parameter');path=path.replaceAll('{'+name+'}',enc(v));}else if(loc==='query')query.push(enc(name)+'='+enc(v));else if(loc==='cookie'){if(typeof process==='undefined'||!process.versions?.node)throw new TypeError('Cookie parameters require Node.js');cookies.push(enc(name)+'='+enc(v));}else{if(/[^\x20-\x7e]/.test(v))throw new TypeError('Invalid header value');headers[name]=v;}}
  d.parameters.forEach((p,i)=>{const v=values[i];if(v==null||v===''){if(p.required)throw new TypeError('Required parameter: '+p.name);return;}put(p.in,p.name,scalar(v,p.schema));});
  if(/[{}]/.test(path))throw new TypeError('Missing path parameter');
  d.security[security].forEach((a,i)=>{let v=scalar(auth[i],{type:'string'});if(!v)throw new TypeError('Missing credential');if(a.kind==='basic'){if(!v.includes(':'))throw new TypeError('Basic auth requires username:password');v='Basic '+btoa(String.fromCharCode(...new TextEncoder().encode(v)));}else if(a.kind==='bearer')v='Bearer '+v;put(a.in,a.field,v);});
  if(cookies.length)headers.Cookie=cookies.join('; ');
  if(typeof body!=='string'||bytes(body)>262144)throw new TypeError('Invalid request body');
  if(body){if(!d.body_types.includes(content_type)||['GET','HEAD'].includes(d.method))throw new TypeError('Invalid content type');if(content_type==='application/json'||content_type.endsWith('+json'))JSON.parse(body);headers['Content-Type']=content_type;}else if(d.body_required)throw new TypeError('Required body');
  const url=d.servers[server]+path+(query.length?'?'+query.join('&'):'');if(bytes(url)>8192||bytes(Object.entries(headers).flat().join(''))>16384)throw new TypeError('Request too large');return {url,method:d.method,headers,body:body||undefined};
}
export async function execute(d,input){
  const r=buildRequest(d,input),ctrl=new AbortController(),timer=setTimeout(()=>ctrl.abort(),15000);
  try{const response=await fetch(r.url,{method:r.method,headers:r.headers,body:r.body,redirect:'manual',credentials:'omit',cache:'no-store',referrerPolicy:'no-referrer',signal:ctrl.signal});
    if(response.type==='opaqueredirect')throw new Error('Redirect blocked');
    const chunks=[];let size=0;const reader=response.body?.getReader();if(reader)try{for(;;){const {done,value}=await reader.read();if(done)break;size+=value.length;if(size>1048576){await reader.cancel();throw new Error('Response exceeds 1 MiB');}chunks.push(value);}}finally{reader.releaseLock();}
    const body=new Uint8Array(size);let offset=0;for(const chunk of chunks){body.set(chunk,offset);offset+=chunk.length;}
    return {status:response.status,headers:Object.fromEntries(response.headers),body,text:()=>new TextDecoder().decode(body),json:()=>JSON.parse(new TextDecoder().decode(body))};
  }finally{clearTimeout(timer);}
}
