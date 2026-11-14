/* Mermaid runs only in an opaque sandbox. No binding of callbacks or links. */
(() => {
    'use strict';
    if (parent === self) return;
    const output = document.getElementById('diagram'), message = document.getElementById('message');
    const MAX = 32768;
    let ticket = '', svg = null, busy = false, width = 0, height = 0, theme = 'light', zoom = 1;
    function send(type, fields = {}) { parent.postMessage({bookDiagram: true, type, ticket, ...fields}, '*'); }
    function validate(source) {
        if (typeof source !== 'string' || !source.trim() || new TextEncoder().encode(source).length > MAX) throw new Error('Diagram source must contain 1–32768 UTF-8 bytes.');
        // Diagram directives/front matter can override configuration. Disallow them entirely.
        if (/^\s*---(?:\s|$)/.test(source) || /%%\s*\{/.test(source)) throw new Error('Mermaid configuration directives and front matter are disabled.');
        if (!/^\s*(?:%%[^\r\n]*[\r\n]+\s*)*(?:flowchart|graph|sequenceDiagram|classDiagram(?:-v2)?|stateDiagram(?:-v2)?|erDiagram|gantt|pie|journey|gitGraph|mindmap|timeline|quadrantChart|requirementDiagram|xychart-beta|sankey-beta|block-beta|architecture-beta|packet-beta|kanban|C4Context|C4Container|C4Component|C4Dynamic|C4Deployment)\b/.test(source)) throw new Error('Unsupported diagram type. Use a standard Mermaid diagram declaration.');
    }
    async function render(source, dark) {
        validate(source);
        const api = window.mermaid?.default || window.mermaid;
        if (!api?.render || !api?.initialize) throw new Error('Local Mermaid library is missing. Run bin/install-mermaid.sh or rebuild the Docker image.');
        theme = dark ? 'dark' : 'light';
        document.body.style.color = dark ? '#e6edf7' : '#162033';
        document.body.style.backgroundColor = dark ? '#111827' : '#ffffff';
        api.initialize({startOnLoad:false,securityLevel:'strict',theme:dark?'dark':'default',htmlLabels:false,flowchart:{htmlLabels:false},maxTextSize:MAX,maxEdges:500,suppressErrorRendering:true,secure:['secure','securityLevel','startOnLoad','maxTextSize','maxEdges','htmlLabels','flowchart','theme','themeCSS','dompurifyConfig','suppressErrorRendering']});
        output.replaceChildren(); svg = null; message.textContent = '';
        const result = await api.render('book-diagram', source, output);
        // Mermaid-generated SVG stays in the sandbox. Never invoke bindFunctions.
        const parsed = new DOMParser().parseFromString(result.svg, 'image/svg+xml');
        if (parsed.documentElement.localName !== 'svg' || parsed.querySelector('parsererror')) throw new Error('Mermaid returned invalid SVG.');
        const imported = document.importNode(parsed.documentElement, true);
        imported.querySelectorAll('script,iframe,object,embed,audio,video,animate,set,animateMotion,animateTransform').forEach(n=>n.remove());
        for (const el of [imported,...imported.querySelectorAll('*')]) {
            for (const attr of [...el.attributes]) {
                if (/^on/i.test(attr.name) || /^(?:href|xlink:href|src)$/i.test(attr.name) && !/^#[\w:.-]+$/.test(attr.value)) el.removeAttribute(attr.name);
            }
        }
        // Remove navigable links, preserving labels. No remote content or callbacks.
        imported.querySelectorAll('a').forEach(a=>a.replaceWith(...a.childNodes));
        output.replaceChildren(imported); svg = imported;
        const vb=svg.viewBox.baseVal;
        width=Math.max(1,Math.ceil(vb.width||svg.getBoundingClientRect().width));
        height=Math.max(1,Math.ceil(vb.height||svg.getBoundingClientRect().height));
        if (!Number.isFinite(width)||!Number.isFinite(height)||width>20000||height>20000) throw new Error('Diagram dimensions exceed the rendering limit.');
        svg.style.maxWidth='none'; svg.style.height='auto'; zoom=1; fit();
        send('rendered',{width,height});
    }
    function fit() {
        if (!svg) return;
        const availableWidth=Math.max(1,document.documentElement.clientWidth-28),availableHeight=Math.max(1,innerHeight-28);
        const base=Math.min(width,availableWidth,availableHeight*width/height);
        svg.style.width=Math.max(1,Math.round(base*zoom))+'px';
    }
    addEventListener('resize',fit);
    async function png() {
        if (!svg) throw new Error('Render the diagram first.');
        const clone=svg.cloneNode(true);
        if (clone.querySelector('foreignObject')) throw new Error('PNG export is unavailable for diagrams with HTML labels.');
        const scale=Math.min(2,4096/width,4096/height,Math.sqrt(8000000/(width*height)));
        const w=Math.max(1,Math.floor(width*scale)),h=Math.max(1,Math.floor(height*scale));
        clone.setAttribute('width',String(w));clone.setAttribute('height',String(h));clone.removeAttribute('style');
        const url=URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(clone)],{type:'image/svg+xml;charset=utf-8'}));
        try {
            const img=new Image();await new Promise((resolve,reject)=>{img.onload=resolve;img.onerror=()=>reject(new Error('PNG conversion failed.'));img.src=url;});
            const canvas=document.createElement('canvas');canvas.width=w;canvas.height=h;
            const ctx=canvas.getContext('2d');ctx.fillStyle=theme==='dark'?'#111827':'#ffffff';ctx.fillRect(0,0,w,h);ctx.drawImage(img,0,0,w,h);
            const data=canvas.toDataURL('image/png');if(data.length>16000000)throw new Error('PNG exceeds export limit.');send('png',{data});
        } finally {URL.revokeObjectURL(url);}
    }
    addEventListener('message', async e=>{
        if(e.source!==parent || !e.data || e.data.bookDiagram!==true) return;
        const d=e.data;
        if(d.type==='render' && typeof d.ticket==='string' && /^[a-f0-9]{32}$/.test(d.ticket) && !busy){
            ticket=d.ticket;busy=true;
            try{await render(d.source,d.dark===true);}catch(err){output.replaceChildren();svg=null;message.textContent='Diagram could not be rendered.';send('error',{message:String(err?.message||err).slice(0,500)});}finally{busy=false;}
        }else if(d.ticket===ticket && svg && d.type==='zoom'){
            const factor=Number(d.factor);if(Number.isFinite(factor)&&factor>=0.5&&factor<=3){zoom=factor;fit();}
        }else if(d.ticket===ticket && d.type==='png' && !busy){busy=true;try{await png();}catch(err){send('export-error',{message:String(err?.message||err).slice(0,300)});}finally{busy=false;}}
    });
    send('ready');
})();
