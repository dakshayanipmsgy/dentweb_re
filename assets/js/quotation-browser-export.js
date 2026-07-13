(function(){
  'use strict';
  const LIMIT=10, SCALE=1.5, READY_TIMEOUT=30000;
  const $=(s,r=document)=>r.querySelector(s);
  const $$=(s,r=document)=>Array.from(r.querySelectorAll(s));
  function setStatus(msg){ const el=$('#quotationBrowserExportStatus'); if(el) el.textContent=msg; }
  function selectedIds(form){ const seen=new Set(), out=[]; $$('.bulk-row-check',form).forEach(c=>{ if(c.checked&&!seen.has(c.value)){seen.add(c.value); out.push(c.value);} }); return out; }
  function requireSupport(){
    const missing=[];
    if(!window.Promise||!window.Blob||!window.URL||!window.FormData) missing.push('modern browser APIs');
    if(!window.html2canvas) missing.push('html2canvas');
    if(!window.jspdf?.jsPDF) missing.push('jsPDF');
    if(!window.fflate?.zipSync) missing.push('fflate');
    if(missing.length) throw new Error('Unsupported browser or missing local export library: '+missing.join(', '));
  }
  async function postSession(form, ids){
    const fd=new FormData(); fd.set('action','quotation_browser_export_session'); fd.set('csrf_token',form.querySelector('[name=csrf_token]')?.value||''); ids.forEach(id=>fd.append('selected_ids[]',id));
    const res=await fetch('admin-quotations.php',{method:'POST',credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},body:fd});
    const data=await res.json().catch(()=>null); if(!res.ok||!data?.ok) throw new Error(data?.message||'Unable to create browser export session.'); return data;
  }
  function wait(ms){return new Promise(r=>setTimeout(r,ms));}
  async function waitReady(frame){
    const start=Date.now();
    while(Date.now()-start<READY_TIMEOUT){
      const w=frame.contentWindow, d=frame.contentDocument;
      if(w&&d&&w.__quotationPdfReady===true){
        if(d.fonts?.ready) await d.fonts.ready;
        await Promise.all($$('img',d).map(img=>img.complete?Promise.resolve():new Promise(r=>{img.addEventListener('load',r,{once:true});img.addEventListener('error',r,{once:true});})));
        return;
      }
      await wait(150);
    }
    throw new Error('Readiness timeout while waiting for quotation assets, charts, fonts, and images.');
  }
  function downloadBlob(blob,name){ const url=URL.createObjectURL(blob); const a=document.createElement('a'); a.href=url; a.download=name; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),1500); }
  function pageElements(doc){ const explicit=$$('.pagedjs_page, .quotation-page, .quote-page',doc).filter(e=>e.offsetWidth&&e.offsetHeight); if(explicit.length) return explicit; const body=doc.body; return [body]; }
  async function renderPdf(item, token, index, total, cancelled){
    if(cancelled()) throw new Error('Export cancelled.');
    setStatus(`Preparing quotation ${index+1} of ${total}`);
    const frame=document.createElement('iframe'); frame.hidden=true; frame.style.cssText='position:fixed;left:-10000px;top:0;width:794px;height:1123px;border:0';
    document.body.appendChild(frame);
    try{
      frame.src=`admin-quotations.php?action=quotation_browser_export_render&token=${encodeURIComponent(token)}&id=${encodeURIComponent(item.id)}`;
      await new Promise((resolve,reject)=>{ frame.onload=resolve; frame.onerror=()=>reject(new Error('Unable to load quotation iframe.')); });
      await waitReady(frame);
      const doc=frame.contentDocument;
      const pdf=new window.jspdf.jsPDF({orientation:'portrait',unit:'mm',format:'a4',compress:true});
      const pages=pageElements(doc);
      for(let p=0;p<pages.length;p++){
        if(cancelled()) throw new Error('Export cancelled.');
        setStatus(`Rendering page ${p+1} of ${pages.length}`);
        const canvas=await window.html2canvas(pages[p],{scale:SCALE,useCORS:true,backgroundColor:'#ffffff',logging:false,windowWidth:pages[p].scrollWidth||794,windowHeight:pages[p].scrollHeight||1123});
        const img=canvas.toDataURL('image/jpeg',0.88);
        if(p>0) pdf.addPage('a4','portrait');
        pdf.addImage(img,'JPEG',0,0,210,297,undefined,'FAST');
        canvas.width=canvas.height=0;
      }
      return pdf.output('blob');
    } finally { frame.src='about:blank'; frame.remove(); }
  }
  async function run(form){
    let cancelled=false; const cancel=$('#quotationBrowserExportCancel'); if(cancel) cancel.hidden=false; const onCancel=()=>{cancelled=true;}; cancel?.addEventListener('click',onCancel,{once:true});
    try{
      requireSupport();
      const ids=selectedIds(form); if(!ids.length) throw new Error('Select at least one quotation.'); if(ids.length>LIMIT) throw new Error(`Browser export supports up to ${LIMIT} quotations per batch.`);
      setStatus('Using browser PDF/ZIP export — no server setup required.');
      const session=await postSession(form,ids); const files={};
      for(let i=0;i<session.items.length;i++){ const item=session.items[i]; const blob=await renderPdf(item,session.token,i,session.items.length,()=>cancelled); files[item.filename]=new Uint8Array(await blob.arrayBuffer()); }
      if(cancelled) throw new Error('Export cancelled.');
      if(session.items.length===1){ setStatus('Download ready'); downloadBlob(new Blob([files[session.items[0].filename]],{type:'application/pdf'}),session.items[0].filename); return; }
      setStatus('Creating ZIP'); const zipped=window.fflate.zipSync(files,{level:6}); downloadBlob(new Blob([zipped],{type:'application/zip'}),`quotations-browser-${new Date().toISOString().replace(/[:.]/g,'-')}.zip`); setStatus('Download ready');
    } catch(e){ setStatus(`Browser export failed: ${e?.message||e}. Selection preserved for retry. Emergency combined Print/Save as PDF remains available via Print Selected.`); }
    finally { if(cancel) cancel.hidden=true; }
  }
  document.addEventListener('click',e=>{ const btn=e.target.closest('[data-browser-quotation-export]'); if(!btn) return; e.preventDefault(); const form=$('#quoteBulkForm'); if(form) run(form); });
  document.addEventListener('submit',e=>{
    const submitter=e.submitter;
    if(submitter?.name==='action'&&submitter.value==='bulk_download_quotation_pdfs'&&submitter.dataset.serverPdfAvailable==='0'){
      e.preventDefault();
      const form=$('#quoteBulkForm');
      if(form) run(form);
    }
  });
})();
