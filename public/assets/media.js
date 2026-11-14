/* Local media uploader; each file is an independent, revision-safe request. */
(() => {
    'use strict';
    const form = document.querySelector('[data-media-upload]');
    if (!form) return;
    const input = form.elements.file, drop = form.querySelector('[data-media-drop]');
    const progress = form.querySelector('[data-media-progress]'), status = form.querySelector('[data-media-status]');
    const button = form.querySelector('button');
    input.multiple = true;
    let busy = false, dropped = null;
    input.addEventListener('change', () => { dropped = null; input.required = true; });
    for (const name of ['dragenter','dragover']) drop.addEventListener(name, e => {e.preventDefault(); if (!busy) drop.classList.add('dragging');});
    for (const name of ['dragleave','drop']) drop.addEventListener(name, e => {e.preventDefault(); drop.classList.remove('dragging');});
    drop.addEventListener('drop', e => {
        if (busy) return;
        dropped = [...e.dataTransfer.files];
        input.required = !dropped.length;
        status.textContent = `${dropped.length} file(s) selected`;
    });
    function send(file, folder, onProgress) {
        return new Promise((resolve,reject) => {
            const data = new FormData(); data.append('csrf',form.elements.csrf.value);data.append('action','upload');data.append('folder',folder);data.append('file',file);
            const xhr = new XMLHttpRequest();xhr.open('POST','/studio/media');xhr.setRequestHeader('X-Book-Media','1');xhr.timeout=120000;
            xhr.upload.onprogress = e => {if(e.lengthComputable)onProgress(e.loaded/e.total);};
            xhr.onload = () => {let body;try{body=JSON.parse(xhr.responseText);}catch{} if(xhr.status>=200&&xhr.status<300&&body?.item)resolve(body);else reject(new Error(body?.error || `Upload failed (${xhr.status})`));};
            xhr.onerror = () => reject(new Error('Network error'));xhr.ontimeout=()=>reject(new Error('Upload timed out'));xhr.send(data);
        });
    }
    form.addEventListener('submit', async e => {
        e.preventDefault();if(busy)return;
        const files=dropped || [...input.files];if(!files.length){status.textContent='Choose files first.';return;}
        if(files.length>50){status.textContent='Select at most 50 files per batch.';return;}
        busy=true;button.disabled=true;input.disabled=true;progress.hidden=false;progress.value=0;
        const errors=[];let uploaded=0, duplicates=0;const folder=form.elements.folder.value;
        for (let n=0;n<files.length;n++){
            const file=files[n];status.textContent=`Uploading ${n+1}/${files.length}: ${file.name}`;
            try{if(file.size<1||file.size>20*1024*1024)throw new Error('File must be 1 byte to 20 MiB');const result=await send(file,folder,p=>{progress.value=(n+p)/files.length*100;});if(result.duplicate)duplicates++;else uploaded++;}
            catch(err){errors.push(`${file.name}: ${err.message}`);}
            progress.value=(n+1)/files.length*100;
        }
        busy=false;button.disabled=false;input.disabled=false;input.value='';input.required=true;dropped=null;
        status.textContent=`Uploaded ${uploaded}, reused ${duplicates}.`+(errors.length?' Failed: '+errors.join('; '):'');
        if(!errors.length){location.assign('/studio/media');return;}
        const refresh=document.createElement('a');refresh.href='/studio/media';refresh.textContent=' Refresh library';status.append(refresh);
    });
    document.querySelectorAll('[data-media-copy]').forEach(button=>button.addEventListener('click',async()=>{
        const field=button.closest('.media-item').querySelector('input[readonly]');
        try{await navigator.clipboard.writeText(button.dataset.mediaCopy);button.textContent='Copied';}catch{field.focus();field.select();button.textContent='Select and copy';}
    }));
})();
