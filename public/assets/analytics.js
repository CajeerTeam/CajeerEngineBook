/* CajeerEngine Book 1.0.5: opt-in basic consent, scoped aggregate and vendor analytics. */
(() => {
  'use strict';
  const element = document.getElementById('book-analytics-config');
  if (!element) return;
  let config;
  try { config = JSON.parse(element.dataset.config || '{}'); } catch (_) { return; }
  if (!/^[a-f0-9]{32}$/.test(config.workspace || '')) return;
  const prefix = document.documentElement.dataset.workspacePrefix || '';
  const storageKey = 'cajeer:book:analytics:' + config.workspace;
  const optOut = navigator.globalPrivacyControl === true;
  const events = new Set(['page_view', 'search', 'link_click', 'download', 'feedback']);
  let state = null, loaded = false;
  try { state = localStorage.getItem(storageKey); } catch (_) { /* session-only selection */ }
  if (optOut) state = 'denied';
  const path = () => {
    const raw = location.pathname;
    const local = prefix && raw.startsWith(prefix + '/') ? raw.slice(prefix.length) : raw;
    if (local === '/' || local === '/search') return local;
    if (/^\/docs\/[a-z0-9][a-z0-9._/-]{0,248}$/.test(local) && !local.includes('//') && !/(?:^|\/)\.{1,2}(?:\/|$)/.test(local)) return local;
    return null;
  };
  const send = name => {
    const p = path();
    if (state !== 'granted' || !p || !events.has(name)) return;
    fetch(prefix + '/analytics/collect', {
      method: 'POST', headers: {'Content-Type': 'application/json', 'X-Book-Analytics': '1'},
      body: JSON.stringify({event:name, path:p, consent:true}),
      credentials: 'omit', mode: 'same-origin', keepalive: true
    }).catch(() => {});
    if (config.ga4 && typeof window.gtag === 'function') window.gtag('event',name,{page_path:p});
    if (config.metrica && typeof window.ym === 'function') {
      if (name === 'page_view') window.ym(Number(config.metrica),'hit',location.href,{title:document.title});
      else window.ym(Number(config.metrica),'reachGoal',name);
    }
  };
  const load = () => {
    if (loaded || state !== 'granted') return;
    loaded = true;
    if (config.ga4 && /^G-[A-Z0-9]{6,20}$/.test(config.ga4)) {
      window.dataLayer = window.dataLayer || [];
      window.gtag = function(){window.dataLayer.push(arguments);};
      window.gtag('consent','default',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
      window.gtag('consent','update',{analytics_storage:'granted',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
      window.gtag('js',new Date());
      window.gtag('config',config.ga4,{send_page_view:false,allow_google_signals:false,allow_ad_personalization_signals:false});
      const s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id='+encodeURIComponent(config.ga4);document.head.appendChild(s);
    }
    if (config.metrica && /^[1-9][0-9]{0,14}$/.test(config.metrica)) {
      (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments);};m[i].l=Date.now();k=e.createElement(t);a=e.getElementsByTagName(t)[0];k.async=1;k.src=r;a.parentNode.insertBefore(k,a);})(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');
      window.ym(Number(config.metrica),'init',{defer:true,clickmap:false,trackLinks:false,accurateTrackBounce:false,webvisor:config.webvisor===true});
    }
    send('page_view');
  };
  const consentBox = document.createElement('section');
  consentBox.id='book-analytics-consent';
  consentBox.setAttribute('aria-label','Analytics privacy settings');
  consentBox.setAttribute('role','region');
  consentBox.className='book-consent';
  const message=document.createElement('p');
  message.textContent=optOut ? 'Global Privacy Control is enabled. Analytics is disabled in this browser.' : 'This workspace collects aggregate page views and events and may use Google Analytics and Yandex Metrica. Analytics is optional.';
  const actions=document.createElement('div');
  const allow=document.createElement('button');allow.type='button';allow.textContent='Accept analytics';
  const deny=document.createElement('button');deny.type='button';deny.textContent='Reject analytics';
  if (optOut) allow.disabled=true;
  actions.append(allow,deny);consentBox.append(message,actions);
  document.body.appendChild(consentBox);
  const preference=document.createElement('button');preference.type='button';preference.className='book-consent-pref';preference.textContent='Privacy preferences';
  const footer=document.querySelector('footer');(footer || document.body).appendChild(preference);
  const refresh = () => {consentBox.hidden=state!==null;};
  const setConsent = choice => {
    if (optOut && choice === 'granted') return;
    const old=state;state=choice;
    try{localStorage.setItem(storageKey,choice);}catch(_){/* no storage: current view only */}
    if(old==='granted'&&choice==='denied'){
      if(typeof window.gtag==='function')window.gtag('consent','update',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
      // Unload already-injected third-party scripts and stop all requests immediately.
      location.reload();return;
    }
    refresh();if(choice==='granted')load();
  };
  allow.addEventListener('click',()=>setConsent('granted'));
  deny.addEventListener('click',()=>setConsent('denied'));
  preference.addEventListener('click',()=>{consentBox.hidden=false;allow.focus();});
  document.addEventListener('submit',e=>{if(e.target instanceof HTMLFormElement && e.target.matches('form.search'))send('search');},true);
  document.addEventListener('click',e=>{
    const link=e.target instanceof Element?e.target.closest('a[href]'):null;
    if (!link) return;
    if(link.hasAttribute('download') || link.pathname.includes('/media/'))send('download');
    else if(link.origin===location.origin)send('link_click');
  },true);
  window.CajeerBookAnalytics=Object.freeze({track(name){if(events.has(name)&&name!=='page_view')send(name);}});
  refresh();if(state==='granted')load();
})();
