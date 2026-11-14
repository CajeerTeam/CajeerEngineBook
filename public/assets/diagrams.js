/* Progressive Mermaid enhancement: source always available; one sandbox per visible diagram. */
(() => {
    'use strict';
    const controllers=new WeakMap(), live=new Set(), maxFrames=6;
    const dark=()=>document.documentElement.dataset.theme==='dark';
    const button=(label,fn)=>{const b=document.createElement('button');b.type='button';b.textContent=label;b.addEventListener('click',fn);return b;};
    function randomTicket(){return [...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');}
    function mount(container, getSource, sourcePre=null){
        if(controllers.has(container))return controllers.get(container);
        const figure=document.createElement('section');figure.className='book-diagram';figure.setAttribute('aria-label','Mermaid diagram');
        const bar=document.createElement('div');bar.className='book-diagram-toolbar';
        const caption=document.createElement('span');caption.textContent='Mermaid';bar.append(caption);
        const state=document.createElement('p');state.className='book-diagram-status';state.setAttribute('role','status');state.textContent='Diagram source available. Render to view.';
        const viewport=document.createElement('div');viewport.className='book-diagram-viewport';viewport.hidden=true;
        let frame=null,ticket='',timer=null,exportTimer=null,ready=false,handshake=false,factor=1,renderedSource='',destroyed=false;
        const post=(type,fields={})=>frame?.contentWindow?.postMessage({bookDiagram:true,type,ticket,...fields},'*');
        function stop(){clearTimeout(timer);clearTimeout(exportTimer);frame?.remove();frame=null;ready=false;handshake=false;viewport.hidden=true;live.delete(controller);zoomOut.disabled=zoomIn.disabled=fit.disabled=download.disabled=true;}
        function start(){
            if(destroyed||!figure.isConnected)return;
            const source=getSource();stop();
            if(!source.trim()||new TextEncoder().encode(source).length>32768){state.textContent='Diagram must contain 1–32768 UTF-8 bytes.';return;}
            for(const c of [...live])if(!c.figure.isConnected)c.destroy();
            if(live.size>=maxFrames){state.textContent='Too many active diagrams. Close another diagram and retry.';return;}
            ticket=randomTicket();renderedSource=source;factor=1;
            frame=document.createElement('iframe');frame.title='Rendered Mermaid diagram';frame.setAttribute('sandbox','allow-scripts');frame.referrerPolicy='no-referrer';frame.src='/diagram-frame';
            viewport.append(frame);viewport.hidden=false;live.add(controller);state.textContent='Rendering diagram…';
            timer=setTimeout(()=>{stop();state.textContent='Diagram rendering timed out. Source remains available.';},20000);
        }
        const render=button('Render diagram',start), close=button('Close diagram',()=>{stop();const details=figure.querySelector('details');if(details)details.open=true;state.textContent='Diagram closed. Render to view again.';});
        function zoom(n){factor=Math.min(3,Math.max(.5,n));post('zoom',{factor});}
        const zoomOut=button('Zoom out',()=>zoom(factor-.25)),zoomIn=button('Zoom in',()=>zoom(factor+.25)),fit=button('Fit',()=>zoom(1));
        const expand=button('Expand',()=>{const expanded=figure.classList.toggle('book-diagram-expanded');expand.textContent=expanded?'Collapse':'Expand';expand.setAttribute('aria-pressed',String(expanded));});
        expand.setAttribute('aria-pressed','false');
        const fullscreen=button('Full screen',async()=>{try{if(document.fullscreenElement===figure){await document.exitFullscreen();return;}if(figure.requestFullscreen){await figure.requestFullscreen();return;}}catch{}figure.classList.add('book-diagram-expanded');expand.textContent='Collapse';expand.setAttribute('aria-pressed','true');});
        const download=button('Download PNG',()=>{download.disabled=true;post('png');exportTimer=setTimeout(()=>{download.disabled=!ready;state.textContent='PNG export timed out.';},15000);});
        zoomOut.disabled=zoomIn.disabled=fit.disabled=download.disabled=true;
        bar.append(render,close,zoomOut,zoomIn,fit,expand,fullscreen,download);figure.append(bar,state,viewport);
        if(sourcePre){const details=document.createElement('details');details.className='book-diagram-source';details.open=true;const summary=document.createElement('summary');summary.textContent='Diagram source';details.append(summary);sourcePre.before(figure);details.append(sourcePre);figure.append(details);}
        else container.append(figure);
        function receive(e){
            if(!frame||e.source!==frame.contentWindow||e.origin!=='null'||e.data?.bookDiagram!==true)return;
            const d=e.data;
            if(d.type==='ready'){if(!handshake){handshake=true;post('render',{source:renderedSource,dark:dark()});}return;}
            if(d.ticket!==ticket)return;
            if(d.type==='rendered'){clearTimeout(timer);ready=true;state.textContent='Diagram ready.';zoomOut.disabled=zoomIn.disabled=fit.disabled=download.disabled=false;const details=figure.querySelector('details');if(details)details.open=false;}
            else if(d.type==='error'){stop();state.textContent='Diagram error: '+String(d.message||'Unknown error').slice(0,500);const details=figure.querySelector('details');if(details)details.open=true;}
            else if(d.type==='png' && ready){clearTimeout(exportTimer);download.disabled=false;if(typeof d.data==='string'&&d.data.length<=16000000&&/^data:image\/png;base64,[A-Za-z0-9+/=]+$/.test(d.data)){const a=document.createElement('a');a.href=d.data;a.download='diagram.png';a.click();}else state.textContent='Invalid PNG export.';}
            else if(d.type==='export-error'){clearTimeout(exportTimer);download.disabled=false;state.textContent='Export error: '+String(d.message||'Unknown error').slice(0,300);}
        }
        const controller={figure,start,receive,suspend(){if(frame){stop();const details=figure.querySelector('details');if(details)details.open=true;state.textContent='Diagram paused. Render to view again.';}},refresh(){if(frame&&renderedSource!==getSource()){stop();state.textContent='Source changed. Render the new diagram.';}},theme(){if(frame)start();},destroy(){destroyed=true;stop();figure.remove();controllers.delete(container);}};
        controllers.set(container,controller);return controller;
    }
    // One listener; validate the actual sandbox window and per-render token.
    addEventListener('message',e=>{for(const c of live)c.receive(e);});
    const observer='IntersectionObserver' in window?new IntersectionObserver(entries=>{for(const e of entries)if(e.isIntersecting){observer.unobserve(e.target);controllers.get(e.target)?.start();}},{rootMargin:'150px'}):null;
    function enhance(scope=document){
        for(const code of scope.querySelectorAll('pre > code[data-book-mermaid]')){
            if(code.closest('.book-diagram')||code.closest('[data-visual-panel]')&&!code.closest('.editor-rich-preview'))continue;
            const c=mount(code.parentElement,()=>code.textContent,code.parentElement);
            controllers.set(c.figure,c);observer?.observe(c.figure);
        }
    }
    // Release detached previews and redraw active diagrams on theme changes only.
    new MutationObserver(()=>{for(const c of [...live])if(!c.figure.isConnected)c.destroy();}).observe(document.body,{childList:true,subtree:true});
    new MutationObserver(()=>{for(const c of [...live])c.theme();}).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
    window.BookDiagrams={enhance,suspend(scope){for(const c of [...live])if(scope.contains(c.figure))c.suspend();},editor(container,getSource){return mount(container,getSource);}};
    enhance();
})();
