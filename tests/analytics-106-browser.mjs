/* Sandboxed browser API regression for consent and vendor injection.
 * No remote JS executes; every outgoing script and first-party beacon is observable. */
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const src=fs.readFileSync(new URL('../public/assets/analytics.js',import.meta.url),'utf8');
const ws='a'.repeat(32);
const conf={workspace:ws,ga4:'G-ABCDEF1234',metrica:'12345678',webvisor:false,search_terms:true,goals:['signup','download'],
  posthog_key:'phc_abc123456789',posthog_host:'https://eu.i.posthog.com',matomo_url:'https://matomo.example.com',matomo_site:'3',
  plausible_script:'https://plausible.io/js/pa-AbCd12345.js',umami_url:'https://umami.example.com',umami_site:'94db1cb1-74f4-4a40-ad6c-962362670409'};
function scenario(initial=null,gpc=false,pathname=`/w/${ws}/${ws}/`,search=''){
  const scripts=[],requests=[],elements=[],values=new Map(),listeners={},reloaded={value:false};
  if(initial) values.set('cajeer:book:analytics:'+ws,initial);
  const element=(type)=>({type,hidden:false,dataset:{},listeners:{},setAttribute(key,v){this[key]=v;},getAttribute(key){return this[key]??null;},
    addEventListener(ev,fn){this.listeners[ev]=fn;},append(...nodes){elements.push(...nodes);},appendChild(node){elements.push(node);},focus(){},closest(){return null;}});
  const root=element('html');root.dataset.workspacePrefix=`/w/${ws}/${ws}`;
  const config=element('config');config.dataset.config=JSON.stringify(conf);
  const body=element('body');const head=element('head');head.appendChild=node=>{scripts.push(node);};head.insertBefore=node=>{scripts.push(node);};head.parentNode=head;
  const document={documentElement:root,body,head,
    createElement:type=>{const e=element(type);e.parentNode=head;return e;},getElementById:(id)=>id==='book-analytics-config'?config:null,
    querySelector:()=>null,addEventListener:(name,fn)=>{listeners[name]=fn;},getElementsByTagName:()=>[head]};
  const location={pathname,search,origin:'https://docs.example.com',href:'https://docs.example.com'+pathname+search,reload(){reloaded.value=true;}};
  const context={document,location,navigator:{globalPrivacyControl:gpc},URL,URLSearchParams,Date,console,
    localStorage:{getItem:key=>values.get(key)??null,setItem:(key,v)=>values.set(key,v)},
    fetch:(url,options)=>{requests.push([url,JSON.parse(options.body)]);return Promise.resolve({status:204});}};
  context.window=context;
  vm.runInNewContext(src,context,{filename:'analytics.js'});
  const buttons=elements.filter(x=>x.type==='button');
  const button=(label)=>buttons.find(x=>x.textContent===label);
  return {context,scripts,requests,values,reloaded,button,listeners};
}
let s=scenario();
assert.equal(s.scripts.length,0);assert.equal(s.requests.length,0);
console.log('PASS no scripts or requests before consent');
s.button('Accept analytics').listeners.click();
const scriptURLs=s.scripts.map(x=>x.src).filter(Boolean);
for(const part of ['googletagmanager.com','mc.yandex.ru','eu-assets.i.posthog.com/static/array.js','matomo.example.com/matomo.js','plausible.io/js/pa-','umami.example.com/script.js'])
  assert(scriptURLs.some(url=>url.includes(part)),`Missing ${part}`);
assert.equal(s.requests[0][1].event,'page_view');
console.log('PASS six vendor SDKs injected and first-party view counted after opt-in');
s.context.CajeerBookAnalytics.trackConversion('signup');
assert(s.requests.some(([,body])=>body.event==='conversion'&&body.goal==='signup'));
const count=s.requests.length;s.context.CajeerBookAnalytics.trackConversion('unauthorized');assert.equal(s.requests.length,count);
console.log('PASS conversion API sends only configured goal');
s.button('Privacy preferences').listeners.click();s.button('Reject analytics').listeners.click();
assert(s.reloaded.value);assert.equal(s.values.get('cajeer:book:analytics:'+ws),'denied');
s.context.CajeerBookAnalytics.trackConversion('signup');assert.equal(s.requests.length,count);
console.log('PASS withdrawing consent prevents subsequent events');
s=scenario('denied');assert.equal(s.scripts.length,0);assert.equal(s.requests.length,0);
console.log('PASS remembered rejection loads no SDKs');
s=scenario('granted',true);assert.equal(s.scripts.length,0);assert.equal(s.requests.length,0);
assert(s.button('Accept analytics').disabled);console.log('PASS Global Privacy Control overrides prior consent');
s=scenario('granted',false,`/w/${ws}/${ws}/search`,'?q=PHP+Deployment');
assert(s.requests.some(([,body])=>body.event==='search'&&body.query==='php deployment'));
console.log('PASS real search-page query creates aggregate search beacon');
