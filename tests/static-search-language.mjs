// Exercise the production offline search script with a minimal DOM (no browser/network dependency).
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const node = () => ({children:[], listeners:{}, value:'', textContent:'', append(...children){this.children.push(...children);},replaceChildren(){this.children=[];},addEventListener(type,fn){this.listeners[type]=fn;}});
const form=node(),input=node(),language=node(),status=node(),results=node();
form.querySelector=s=>s==='input[name=q]'?input:language;
form.requestSubmit=()=>form.listeners.submit({preventDefault(){}});
const document={querySelector:s=>({'form.search':form,'[data-search-status]':status,'[data-search-results]':results}[s]),createElement:node,createDocumentFragment:node};
const context={document,window:{BOOK_STATIC_INDEX:[{url:'docs/p-guide/p-1/p-start/index.html',title:'Quasar',path:'guide/1/start',text:'Quasar original',locale:'en'},{url:'docs/p-guide/p-1/p-ru/p-start/index.html',title:'Квазар',path:'guide/1/ru/start',text:'Quasar документация',locale:'ru'},{url:'docs/p-guide/p-1/p-old/index.html',title:'Legacy Quasar',path:'guide/1/old',text:'Quasar legacy'}]},location:{href:'file:///site/search.html?q=quasar&locale=ru',search:'?q=quasar&locale=ru'},history:{replaceState(){}},URL,URLSearchParams};
vm.runInNewContext(readFileSync(new URL('../public/assets/static-search.js',import.meta.url),'utf8'),context);
assert.equal(status.textContent,'1 result');assert.equal(results.children[0].children[0].lang,'ru');
language.value='en';language.listeners.change();assert.equal(status.textContent,'2 results');
language.value='';language.listeners.change();assert.equal(status.textContent,'3 results');
input.value='документация';language.value='ru';form.requestSubmit();assert.equal(status.textContent,'1 result');
console.log('PASS offline search locale, legacy English default, live filter and Cyrillic search');
