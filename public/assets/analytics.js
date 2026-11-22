/* CajeerEngine Book 1.0.6: opt-in first-party analytics and optional vendor SDKs. */
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
  const goals = new Set(Array.isArray(config.goals) ? config.goals : []);
  const safeGoal = name => typeof name === 'string' && /^[a-z][a-z0-9_]{1,39}$/.test(name) && goals.has(name);
  let state = null, loaded = false;
  try { state = localStorage.getItem(storageKey); } catch (_) { /* Session-only preference. */ }
  if (state !== 'granted' && state !== 'denied') state = null;
  if (optOut) state = 'denied';

  const path = () => {
    const raw = location.pathname;
    const local = prefix && raw.startsWith(prefix + '/') ? raw.slice(prefix.length) : raw;
    if (local === '/' || local === '/search') return local;
    if (/^\/docs\/[a-z0-9][a-z0-9._/-]{0,248}$/.test(local) && !local.includes('//') && !/(?:^|\/)\.{1,2}(?:\/|$)/.test(local)) return local;
    return null;
  };
  const addScript = (src, attributes) => {
    const s = document.createElement('script'); s.async = true; s.src = src;
    for (const [name, value] of Object.entries(attributes || {})) s.setAttribute(name, value);
    document.head.appendChild(s); return s;
  };
  const hostname = url => { try { return new URL(url).origin; } catch (_) { return ''; } };
  const vendor = (name, p, goal) => {
    if (!loaded || state !== 'granted') return;
    const page = location.origin + location.pathname; // Never pass search terms to vendors.
    if (config.ga4 && typeof window.gtag === 'function') {
      window.gtag('event', name === 'conversion' ? 'conversion_' + goal : name, {page_path:p});
    }
    if (config.metrica && typeof window.ym === 'function') {
      if (name === 'page_view') window.ym(Number(config.metrica), 'hit', page, {title:document.title});
      else window.ym(Number(config.metrica), 'reachGoal', name === 'conversion' ? goal : name);
    }
    if (config.posthog_key && typeof window.posthog?.capture === 'function') {
      window.posthog.capture(name === 'conversion' ? 'conversion_' + goal : name, {$current_url:page, page_path:p});
    }
    if (config.matomo_url && Array.isArray(window._paq)) {
      window._paq.push(['setCustomUrl', page]);
      if (name === 'page_view') window._paq.push(['trackPageView', document.title]);
      else window._paq.push(['trackEvent', 'Documentation', name, goal || p]);
    }
    if (config.plausible_script && typeof window.plausible === 'function') {
      if (name === 'page_view') window.plausible('pageview', {u:page});
      else window.plausible(name === 'conversion' ? 'conversion_' + goal : name);
    }
    if (config.umami_url) {
      if (typeof window.umami?.track === 'function') {
        if (name === 'page_view') window.umami.track();
        else window.umami.track(name === 'conversion' ? 'conversion_' + goal : name);
      } else {
        umamiPending.push([name,goal]);
      }
    }
  };
  const umamiPending = [];
  const send = (name, extras = {}) => {
    const p = path();
    if (state !== 'granted' || !p || (!events.has(name) && name !== 'conversion')) return;
    if (name === 'conversion' && !safeGoal(extras.goal)) return;
    const body = {event:name, path:p, consent:true};
    if (name === 'conversion') body.goal = extras.goal;
    if (name === 'search' && config.search_terms === true && typeof extras.query === 'string') {
      const query = extras.query.trim().toLocaleLowerCase().replace(/\s+/g, ' ');
      if (query.length >= 3 && query.length <= 80 && /^[\p{L}\p{M}\p{N}][\p{L}\p{M}\p{N} _.-]*$/u.test(query) && !/\d{4}/.test(query)) body.query=query;
    }
    fetch(prefix + '/analytics/collect', {
      method:'POST', headers:{'Content-Type':'application/json', 'X-Book-Analytics':'1'},
      body:JSON.stringify(body), credentials:'omit', mode:'same-origin', keepalive:true
    }).catch(() => {});
    vendor(name,p,extras.goal);
    // Selected built-in goals count as conversions with no additional markup.
    if (name !== 'conversion' && safeGoal(name) && (name === 'download' || name === 'feedback')) send('conversion',{goal:name});
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
      addScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(config.ga4));
    }
    if (config.metrica && /^[1-9][0-9]{0,14}$/.test(config.metrica)) {
      (function(m,e,t,r,i,k,a){m[i]=m[i]||function(){(m[i].a=m[i].a||[]).push(arguments);};m[i].l=Date.now();k=e.createElement(t);a=e.getElementsByTagName(t)[0];k.async=1;k.src=r;a.parentNode.insertBefore(k,a);})(window,document,'script','https://mc.yandex.ru/metrika/tag.js','ym');
      window.ym(Number(config.metrica),'init',{defer:true,clickmap:false,trackLinks:false,accurateTrackBounce:false,webvisor:config.webvisor===true});
    }
    if (config.posthog_key && /^[A-Za-z0-9_]{8,120}$/.test(config.posthog_key)) {
      const api = hostname(config.posthog_host);
      if (api) {
        const ph=window.posthog=window.posthog||[];
        ph._i=[];ph.__SV=1;
        ph.capture=function(){ph.push(['capture',...arguments]);};
        ph.opt_out_capturing=function(){ph.push(['opt_out_capturing']);};
        ph.init=function(token,options){ph._i.push([token,options,'posthog']);};
        ph.init(config.posthog_key,{
          api_host:api,autocapture:false,capture_pageview:false,capture_pageleave:false,
          disable_session_recording:true,persistence:'memory',person_profiles:'never',
          disable_surveys:true,disable_web_experiments:true
        });
        const assets = api.replace('.i.posthog.com','-assets.i.posthog.com');
        addScript(assets + '/static/array.js');
      }
    }
    if (config.matomo_url && config.matomo_site) {
      const base = hostname(config.matomo_url);
      if (base) {
        window._paq = window._paq || [];
        window._paq.push(['requireConsent']);
        window._paq.push(['disableCookies']);
        window._paq.push(['setTrackerUrl',base+'/matomo.php']);
        window._paq.push(['setSiteId',config.matomo_site]);
        window._paq.push(['setConsentGiven']);
        addScript(base+'/matomo.js');
      }
    }
    if (config.plausible_script && /^https:\/\/[^\s]+\.js$/.test(config.plausible_script)) {
      window.plausible=window.plausible||function(){(window.plausible.q=window.plausible.q||[]).push(arguments);};
      window.plausible.init=window.plausible.init||function(options){window.plausible.o=options||{};};
      window.plausible.init({autoCapturePageviews:false});
      addScript(config.plausible_script);
    }
    if (config.umami_url && config.umami_site) {
      const base=hostname(config.umami_url);
      if (base) {
        const script=addScript(base+'/script.js',{
          'data-website-id':config.umami_site,'data-auto-track':'false','data-exclude-search':'true',
          'data-do-not-track':'true','data-host-url':base
        });
        script.addEventListener('load',()=>{
          if (state !== 'granted' || typeof window.umami?.track !== 'function') return;
          for (const [name,goal] of umamiPending.splice(0)) {
            if(name === 'page_view')window.umami.track();
            else window.umami.track(name==='conversion'?'conversion_'+goal:name);
          }
        });
      }
    }
    send('page_view');
    if (path() === '/search') {
      const query = new URLSearchParams(location.search).get('q');
      if (query && query.trim()) send('search',{query});
    }
  };
  const consentBox=document.createElement('section');
  consentBox.id='book-analytics-consent';consentBox.setAttribute('aria-label','Analytics privacy settings');
  consentBox.setAttribute('role','region');consentBox.className='book-consent';
  const message=document.createElement('p');
  message.textContent=optOut?'Global Privacy Control is enabled. Analytics is disabled in this browser.':
    'This documentation workspace collects optional aggregate analytics and may connect to configured third-party analytics providers. You can accept, reject or withdraw consent.';
  const actions=document.createElement('div');
  const allow=document.createElement('button');allow.type='button';allow.textContent='Accept analytics';
  const deny=document.createElement('button');deny.type='button';deny.textContent='Reject analytics';
  if (optOut) allow.disabled=true;
  actions.append(allow,deny);consentBox.append(message,actions);document.body.appendChild(consentBox);
  const preference=document.createElement('button');preference.type='button';preference.className='book-consent-pref';preference.textContent='Privacy preferences';
  const footer=document.querySelector('footer');(footer||document.body).appendChild(preference);
  const refresh=()=>{consentBox.hidden=state!==null;};
  const setConsent=choice=>{
    if(optOut&&choice==='granted')return;
    const old=state;state=choice;
    try{localStorage.setItem(storageKey,choice);}catch(_){/* current view only */}
    if(old==='granted'&&choice==='denied'){
      if(typeof window.gtag==='function')window.gtag('consent','update',{analytics_storage:'denied',ad_storage:'denied',ad_user_data:'denied',ad_personalization:'denied'});
      if(typeof window.posthog?.opt_out_capturing==='function')window.posthog.opt_out_capturing();
      if(Array.isArray(window._paq))window._paq.push(['forgetConsentGiven']);
      location.reload();return;
    }
    refresh();if(choice==='granted')load();
  };
  allow.addEventListener('click',()=>setConsent('granted'));
  deny.addEventListener('click',()=>setConsent('denied'));
  preference.addEventListener('click',()=>{consentBox.hidden=false;allow.focus();});
  document.addEventListener('click',e=>{
    const target=e.target instanceof Element?e.target:null;
    if (!target) return;
    const goal=target.closest('[data-book-conversion]')?.getAttribute('data-book-conversion');
    if (safeGoal(goal)) send('conversion',{goal});
    const link=target.closest('a[href]');if(!link)return;
    if(link.hasAttribute('download')||link.pathname.includes('/media/'))send('download');
    else if(link.origin===location.origin)send('link_click');
  },true);
  window.CajeerBookAnalytics=Object.freeze({
    track(name){if(events.has(name)&&name!=='page_view')send(name);},
    trackConversion(goal){if(safeGoal(goal))send('conversion',{goal});}
  });
  refresh();if(state==='granted')load();
})();
