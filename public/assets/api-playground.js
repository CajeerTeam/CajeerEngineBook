/* Published API request builder. No persistence, dynamic HTML, redirect following or ambient credentials. */
(() => {
  'use strict';
  const MAX_BODY = 262144, MAX_RESPONSE = 1048576;
  const bytes = value => new TextEncoder().encode(value).length;
  const enc = value => encodeURIComponent(value).replace(/[!'()*]/g, ch => '%' + ch.charCodeAt(0).toString(16).toUpperCase());
  const b64 = value => btoa(Array.from(new TextEncoder().encode(value), b => String.fromCharCode(b)).join(''));
  const base64Bytes = value => { let out = ''; for (let i=0;i<value.length;i+=8192) out += String.fromCharCode(...value.subarray(i,i+8192)); return btoa(out); };
  const value = (v, schema) => {
    if (typeof v !== 'string' || bytes(v)>4096 || /[\x00-\x1f\x7f]/.test(v)) throw Error('Invalid parameter value.');
    if (schema.type==='integer' && !/^-?(?:0|[1-9][0-9]*)$/.test(v) || schema.type==='number' && (!/^[+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?$/i.test(v)||!Number.isFinite(Number(v))) || schema.type==='boolean' && !['true','false'].includes(v)) throw Error('Parameter type does not match OpenAPI.');
    if (schema.enum && !schema.enum.map(String).includes(v)) throw Error('Parameter is not an allowed enum value.');
    return v;
  };
  function build(d,input,allowed,proxy=false) {
    if (!allowed.includes(input.server) || !d.servers[input.server] || !d.security[input.security] || input.values.length!==d.parameters.length || input.auth.length!==d.security[input.security].length) throw Error('Select an enabled server and authentication option.');
    const base=d.servers[input.server], origin=new URL(base).origin;
    let path=d.path;
    const headers={'Accept':'application/json, text/plain, */*'}, query=[], redactedQuery=[], cookies=[];
    const put=(where,name,v,secret=false)=>{
      if(where==='path') {
        if (!v || v==='.' || v==='..' || /[\/\\%]/.test(v)) throw Error('Unsafe path parameter.');
        path=path.split('{'+name+'}').join(enc(v));
      } else if(where==='query') { query.push(enc(name)+'='+enc(v)); redactedQuery.push(enc(name)+'='+enc(secret?'REDACTED':v)); }
      else if(where==='header') { if (/[^\x20-\x7e]/.test(v)) throw Error('Header values must be printable ASCII.'); headers[name]=v; }
      else { if(!proxy) throw Error('Cookie parameters require managed proxy.'); cookies.push(enc(name)+'='+enc(v)); }
    };
    d.parameters.forEach((p,i)=>{const v=input.values[i]; if(v===null||v===''){if(p.required)throw Error('Required parameter is missing: '+p.name);return;}put(p.in,p.name,value(v,p.schema));});
    if(/[{}]/.test(path))throw Error('Missing path parameter.');
    d.security[input.security].forEach((a,i)=>{let v=input.auth[i];if(!v)throw Error('Required credential is missing: '+a.name);v=value(v,{type:'string'});if(a.kind==='basic'){if(!v.includes(':'))throw Error('Basic credentials must be username:password.');v='Basic '+b64(v);}else if(a.kind==='bearer')v='Bearer '+v;put(a.in,a.field,v,true);});
    if(cookies.length) headers.Cookie=cookies.join('; ');
    const body=input.body||'', type=input.content_type||'';
    if(bytes(body)>MAX_BODY)throw Error('Request body exceeds 256 KiB.');
    if(body){if(!d.body_types.includes(type)||['GET','HEAD'].includes(d.method))throw Error('Request body type is not declared.');if(type==='application/json'||type.endsWith('+json'))try{JSON.parse(body);}catch{throw Error('Request body must be valid JSON.');}headers['Content-Type']=type;}
    else if(d.body_required)throw Error('Request body is required.');
    const url=base+path+(query.length?'?'+query.join('&'):''), redacted=base+path+(redactedQuery.length?'?'+redactedQuery.join('&'):'');
    if(new URL(url).origin!==origin||bytes(url)>8192||bytes(Object.keys(headers).join('')+Object.values(headers).join(''))>16384)throw Error('Request URL or headers exceed limit.');
    return {method:d.method,url,headers,body,redacted};
  }
  function curl(r) {
    const q=s=>"'"+String(s).replace(/'/g,"'\\''")+"'";
    const cmd=['curl','--request',r.method,'--max-time','15',q(r.redacted)];
    for(const [k,v] of Object.entries(r.headers))cmd.push('-H',q(k+': '+(['Accept','Content-Type'].includes(k)?v:'REDACTED')));
    if(r.body)cmd.push('--data-binary',q('@request-body'));
    return cmd.join(' ');
  }
  async function readBounded(response,max) {
    if(!response.body)return {data:new Uint8Array(),truncated:false};
    const reader=response.body.getReader(),chunks=[];let size=0,truncated=false;
    try{while(true){const {done,value:v}=await reader.read();if(done)break;const n=Math.min(v.length,max-size);if(n){chunks.push(v.subarray(0,n));size+=n;}if(n<v.length){truncated=true;await reader.cancel();break;}}}finally{reader.releaseLock();}
    const data=new Uint8Array(size);let offset=0;for(const chunk of chunks){data.set(chunk,offset);offset+=chunk.length;}return {data,truncated};
  }
  async function browserRequest(r,signal) {
    const started=performance.now();
    const response=await fetch(r.url,{method:r.method,headers:r.headers,body:r.body||undefined,credentials:'omit',redirect:'error',mode:'cors',cache:'no-store',referrerPolicy:'no-referrer',signal});
    const {data,truncated}=await readBounded(response,MAX_RESPONSE);let body,encoding='utf8';
    try {body=new TextDecoder('utf-8',{fatal:true}).decode(data);if(/[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(body))throw Error('Binary');} catch {body=base64Bytes(data);encoding='base64';}
    return {status:response.status,duration_ms:Math.round(performance.now()-started),headers:Array.from(response.headers.entries()),body,encoding,truncated};
  }
  async function proxyRequest(config,input,signal) {
    const body=new URLSearchParams({key:config.key,binding:config.binding,csrf:config.csrf,payload:JSON.stringify(input)});
    const response=await fetch(config.endpoint,{method:'POST',body,credentials:'same-origin',redirect:'error',cache:'no-store',referrerPolicy:'no-referrer',signal});
    const {data,truncated}=await readBounded(response,3*MAX_RESPONSE);if(truncated)throw Error('Proxy response exceeded limit.');
    let result;try{result=JSON.parse(new TextDecoder().decode(data));}catch{throw Error('Proxy returned an invalid response.');}
    if(!response.ok)throw Error(typeof result.error==='string'?result.error:'Managed proxy request failed.');
    if(!Number.isInteger(result.status)||!Array.isArray(result.headers)||typeof result.body!=='string'||!['utf8','base64'].includes(result.encoding))throw Error('Proxy returned an invalid response.');
    return result;
  }
  function initialize(form) {
    const config=JSON.parse(form.dataset.apiPlayground),d=config.definition;
    const $=name=>form.querySelector('[data-pg-'+name+']');
    const mode=$('mode'),server=$('server'),security=$('security'),params=$('parameters'),auth=$('auth'),send=$('send'),cancel=$('cancel'),status=$('status');
    const option=(select,val,label)=>{const o=document.createElement('option');o.value=String(val);o.textContent=label;select.append(o);};
    const label=(container,title,input)=>{const l=document.createElement('label');l.textContent=title;l.append(input);container.append(l);};
    const input=(type='text')=>{const i=document.createElement('input');i.type=type;i.autocomplete='off';i.spellcheck=false;i.maxLength=4096;return i;};
    if(config.browser.length)option(mode,'browser','Browser (direct, CORS)');if(config.proxy.length)option(mode,'proxy','Managed proxy (server)');
    const paramFields=d.parameters.map(p=>{
      let f;if(p.schema.enum||p.schema.type==='boolean'){f=document.createElement('select');option(f,'','Select value');for(const v of p.schema.enum||['true','false'])option(f,String(v),String(v));}else{f=input();if(['integer','number'].includes(p.schema.type))f.inputMode='decimal';}
      f.required=p.required;label(params,p.name+' ('+p.in+', '+p.schema.type+(p.required?', required':'')+')',f);return f;
    });
    let authFields=[];const refreshAuth=()=>{authFields.forEach(f=>f.value='');auth.replaceChildren();authFields=d.security[Number(security.value)].map(a=>{const f=input('password');f.required=true;f.placeholder=a.kind==='basic'?'username:password':'Enter credential';label(auth,a.name+' ('+a.in+')',f);return f;});};
    const refreshServers=()=>{server.replaceChildren();for(const i of config[mode.value])option(server,i,d.servers[i]);security.replaceChildren();d.security.forEach((alt,i)=>{if(mode.value==='proxy'||!alt.some(a=>a.in==='cookie'))option(security,i,alt.length?alt.map(a=>a.name+' ('+a.kind+')').join(' + '):'No authentication');});paramFields.forEach((f,i)=>{if(d.parameters[i].in==='cookie'){f.disabled=mode.value==='browser';if(f.disabled)f.value='';}});refreshAuth();$('mode-note').textContent=mode.value==='browser'?'Direct request from your browser. The API must allow this origin via CORS. Cookies and redirects are disabled.':'Request sent by this installation to an approved public HTTPS API. API credentials are forwarded, not stored. Redirects are disabled.';};
    mode.addEventListener('change',refreshServers);security.addEventListener('change',refreshAuth);refreshServers();
    if(d.body_types.length){$('body').hidden=false;d.body_types.forEach(t=>option($('content-type'),t,t));$('payload').required=d.body_required;}
    const mutates=!['GET','HEAD','OPTIONS'].includes(d.method);$('confirm-wrap').hidden=!mutates;$('confirm').required=mutates;
    let controller=null,last=null;
    const fields=()=>({server:Number(server.value),security:Number(security.value),values:paramFields.map(f=>f.value===''?null:f.value),auth:authFields.map(f=>f.value),content_type:$('content-type').value,body:$('payload').value,confirm:$('confirm').checked});
    const render=result=>{
      last=result;$('response').hidden=false;$('result').textContent='HTTP '+result.status+' · '+result.duration_ms+' ms'+(result.truncated?' · Response truncated at 1 MiB':'')+(result.encoding==='base64'?' · Binary (Base64)':'');
      $('headers').textContent=result.headers.map(([k,v])=>k+': '+v).join('\n');let body=result.body;
      if(result.encoding==='utf8'&&!result.truncated){try{body=JSON.stringify(JSON.parse(body),null,2);}catch{}}
      $('result-body').textContent=body;status.textContent='Response received.';
    };
    const busy=v=>{send.disabled=v;cancel.disabled=!v;mode.disabled=v;server.disabled=v;security.disabled=v;$('clear').disabled=v;$('curl').disabled=v;};
    form.addEventListener('submit',async event=>{
      event.preventDefault();if(controller)return;
      status.textContent='';$('response').hidden=true;last=null;$('command-wrap').hidden=true;
      let request,values;try{values=fields();if(mutates&&!values.confirm)throw Error('Confirm that this request may modify API data.');request=build(d,values,config[mode.value],mode.value==='proxy');}catch(e){status.textContent=e.message;return;}
      controller=new AbortController();const timeout=setTimeout(()=>controller?.abort(),mode.value==='proxy'?20000:15000);busy(true);status.textContent='Sending request…';
      try{render(mode.value==='proxy'?await proxyRequest(config,values,controller.signal):await browserRequest(request,controller.signal));}
      catch(e){status.textContent=e.name==='AbortError'?'Request cancelled or timed out. The API may already have processed it.':mode.value==='browser'&&e instanceof TypeError?'Browser request failed: check CORS, TLS, connectivity and redirects.':e.message;}
      finally{clearTimeout(timeout);controller=null;busy(false);}
    });
    cancel.addEventListener('click',()=>controller?.abort());
    $('curl').addEventListener('click',()=>{try{$('command').textContent=curl(build(d,fields(),config[mode.value],mode.value==='proxy'));$('command-wrap').hidden=false;status.textContent='';}catch(e){status.textContent=e.message;}});
    $('copy-curl').addEventListener('click',async()=>{try{await navigator.clipboard.writeText($('command').textContent);status.textContent='Redacted cURL copied.';}catch{status.textContent='Clipboard unavailable. Select the cURL text to copy.';}});
    $('clear').addEventListener('click',()=>{authFields.forEach(f=>f.value='');paramFields.forEach(f=>f.value='');$('payload').value='';$('command').textContent='';$('command-wrap').hidden=true;last=null;$('headers').textContent='';$('result-body').textContent='';$('response').hidden=true;status.textContent='Credentials, request body and response cleared.';});
    $('copy').addEventListener('click',async()=>{if(last)try{await navigator.clipboard.writeText(last.body);status.textContent='Response body copied.';}catch{status.textContent='Clipboard unavailable. Select the response body to copy.';}});
    $('download').addEventListener('click',()=>{if(!last)return;const data=last.encoding==='base64'?Uint8Array.from(atob(last.body),c=>c.charCodeAt(0)):last.body;const url=URL.createObjectURL(new Blob([data],{type:'application/octet-stream'}));const a=document.createElement('a');a.href=url;a.download='api-response.bin';a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);});
    window.addEventListener('pagehide',()=>{controller?.abort();authFields.forEach(f=>f.value='');paramFields.forEach(f=>f.value='');$('payload').value='';last=null;$('headers').textContent='';$('result-body').textContent='';$('response').hidden=true;$('command').textContent='';$('command-wrap').hidden=true;});
    send.disabled=false;
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={build,curl,browserRequest,readBounded,proxyRequest};
  if(typeof document!=='undefined')document.querySelectorAll('[data-api-playground]').forEach(form=>{try{initialize(form);}catch{form.querySelector('[data-pg-status]').textContent='Playground initialization failed. Reload the page.';}});
})();
